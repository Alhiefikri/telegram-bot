<?php

namespace Tests\Feature;

use App\Enums\BalancePaymentPreference;
use App\Enums\CartOrigin;
use App\Enums\CartStatus;
use App\Enums\InventoryMovementType;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidCartState;
use App\Http\Controllers\CustomerApiOrderController;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\CustomerApiOrderClaim;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Services\CartService;
use App\Services\CustomerApi\CustomerApiCredentials;
use App\Services\CustomerApi\CustomerApiFailure;
use App\Services\CustomerApi\CustomerApiPrincipal;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\Telegram\StockNotificationDeliveryPipeline;
use App\Services\Telegram\TelegramProductNotificationService;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\VerifiedUsdtFunding;
use App\Storefront\StorefrontGuestCart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Mockery;
use Tests\TestCase;

final class CustomerApiOrderTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private CustomerApiPrincipal $principal;

    private int $fundingSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['services.telegram.admin_telegram_chat_id' => '123456', 'services.telegram.token' => 'fixture-token']);
        $this->customer = Customer::factory()->create();
        $this->customer->forceFill(['balance_payment_preference' => BalancePaymentPreference::UsdFirst])->save();
        $credentials = app(CustomerApiCredentials::class);
        $this->principal = $credentials->authenticate('Bearer '.$credentials->generate($this->customer)->takeSecret());
    }

    public function test_one_paid_balance_order_is_created_and_the_same_claim_replays_it_unchanged(): void
    {
        $product = $this->product();
        $this->fundUsd(5_000_000);

        $created = $this->send($this->body($product), 'claim-1');

        $this->assertSame(201, $created->getStatusCode());
        $payload = $created->getData(true);
        $this->assertFalse($payload['idempotent_replay']);
        $this->assertSame(['id', 'invoice_number', 'origin', 'status', 'payment_status', 'currency', 'amount',
            'quantity', 'product', 'created_at', 'request_id'], array_keys($payload['data']));
        $this->assertSame('api', $payload['data']['origin']);
        $this->assertSame('request-fixture', $payload['data']['request_id']);
        $this->assertSame('USD', $payload['data']['currency']);
        $this->assertSame('2', $payload['data']['amount']);
        $this->assertSame(1, $payload['data']['quantity']);
        $this->assertSame(['id' => (string) $product->id, 'name' => $product->name], $payload['data']['product']);

        $order = Order::sole();
        $this->assertSame((string) $order->id, $payload['data']['id']);
        $this->assertSame($order->order_status->value, $payload['data']['status']);
        $this->assertSame(PaymentStatus::Paid->value, $payload['data']['payment_status']);
        $this->assertSame('USDT', $order->currency);
        $this->assertSame('usd_first', $order->balance_checkout_snapshot['preference']);
        $this->assertSame('account', $order->balance_checkout_snapshot['source']);
        $claim = CustomerApiOrderClaim::sole();
        $this->assertSame($this->principal->credentialId, $claim->credential_id);
        $this->assertSame($order->id, $claim->order_id);
        $this->assertSame(CustomerApiOrderClaim::STATE_ACCEPTED, $claim->state);
        $this->assertSame($claim->id, $order->api_order_claim_id);
        $this->assertSame(3_000_000, app(CustomerUsdtBalanceLedger::class)->snapshot($this->customer)->balanceMicro());
        $this->assertSame(2, InventoryMovement::where('order_id', $order->id)->count());
        $this->assertSame(1, InventoryMovement::where('order_id', $order->id)->where('type', InventoryMovementType::Sale)->count());
        $this->assertSame(1, ProductStockItem::whereNotNull('order_item_id')->count());
        $this->assertSame(CartStatus::CheckedOut, Cart::sole()->status);
        $this->assertSame(CartOrigin::Api, Cart::sole()->origin);
        $this->assertSame($order->id, Cart::sole()->orders()->sole()->id);

        // Lost response: the same claim reconstructs the accepted Order without
        // current stock or price, even after every mutable input changed.
        $product->update(['price_amount' => 99_000, 'usdt_price_amount' => 9_000_000, 'reseller_usdt_price_amount' => 1]);
        $this->customer->forceFill(['balance_payment_preference' => BalancePaymentPreference::IdrOnly])->save();
        $replayed = $this->send($this->body($product), 'claim-1');

        $this->assertSame(200, $replayed->getStatusCode());
        $this->assertTrue($replayed->getData(true)['idempotent_replay']);
        $this->assertSame($payload['data'], $replayed->getData(true)['data']);
        $this->assertSame(1, Order::count());
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->where('kind', 'purchase_debit')->count());
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame(1, Cart::count());
        Http::assertNothingSent();
    }

    public function test_sold_out_recipient_failure_does_not_rollback_purchase_and_recovers_without_charging_again(): void
    {
        $product = $this->product();
        $this->fundUsd(2_000_000);
        $notifications = app(TelegramProductNotificationService::class);
        $unavailable = Mockery::mock(TelegramProductNotificationService::class);
        $unavailable->shouldReceive('productSoldOutDeliveryBatch')->andThrow(new \RuntimeException('Recipient renderer unavailable'));
        $this->app->instance(TelegramProductNotificationService::class, $unavailable);

        $response = $this->send($this->body($product), 'sold-out-renderer');
        $this->assertSame(201, $response->getStatusCode());
        $order = Order::sole();
        $sale = InventoryMovement::where('order_id', $order->id)->where('type', InventoryMovementType::Sale)->sole();
        try {
            app(StockNotificationDeliveryPipeline::class)->deliver($sale->id);
            $this->fail('The unavailable renderer must prevent notification delivery.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Recipient renderer unavailable', $error->getMessage());
        }

        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(0, app(CustomerUsdtBalanceLedger::class)->snapshot($this->customer)->balanceMicro());
        $this->assertSame(1, ProductStockItem::whereNotNull('order_item_id')->count());
        $this->app->instance(TelegramProductNotificationService::class, $notifications);
        $pipeline = app(StockNotificationDeliveryPipeline::class);
        $pipeline->materialize($sale->id);
        $recipients = DB::table('stock_notification_recipients')->where('customer_id', $this->customer->id)->get();
        $this->assertCount(1, $recipients);
        $this->assertStringContainsString($product->name, $recipients->first()->text);
        $pipeline->materialize($sale->id);
        $replay = $this->send($this->body($product), 'sold-out-renderer');
        $this->assertSame((string) $order->id, $replay->getData(true)['data']['id']);
        $this->assertSame(1, DB::table('stock_notification_recipients')->where('customer_id', $this->customer->id)->count());
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->where('kind', 'purchase_debit')->count());
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame(0, $product->fresh()->reserved_stock);
    }

    public function test_request_override_wins_and_an_unacknowledged_secondary_price_is_never_charged(): void
    {
        $first = $this->product();
        $second = $this->product();
        $this->fundIdr(30_000);

        // Explicit request override wins over the account preference.
        $order = $this->send(['product_id' => (string) $first->id, 'quantity' => 1,
            'preference' => 'idr_only', 'expected_totals' => ['IDR' => '15000']], 'override');
        $this->assertSame(201, $order->getStatusCode());
        $this->assertSame('IDR', $order->getData(true)['data']['currency']);
        $this->assertSame('override', Order::sole()->balance_checkout_snapshot['source']);
        $this->assertSame(15_000, app(StoreBalanceLedger::class)->snapshot($this->customer)->balance());

        // The secondary wallet is affordable but not authorized: no fallback.
        $unauthorized = $this->failure(['product_id' => (string) $second->id, 'quantity' => 1,
            'preference' => 'usd_first', 'expected_totals' => ['USD' => '2']], 'fallback-unacknowledged');
        $this->assertSame(402, $unauthorized->status);
        $this->assertSame('insufficient_funds', $unauthorized->machineCode);
        $this->assertSame(1, Order::count());
        $this->assertSame(1, ProductStockItem::whereNull('order_item_id')->count());
        $this->assertSame(2, InventoryMovement::count());

        // Authorized secondary consent falls back inside the same locked snapshot.
        $fallback = $this->send(['product_id' => (string) $second->id, 'quantity' => 1,
            'preference' => 'usd_first', 'expected_totals' => ['USD' => '2', 'IDR' => '15000']], 'fallback-authorized');
        $this->assertSame(201, $fallback->getStatusCode());
        $this->assertSame('IDR', $fallback->getData(true)['data']['currency']);
        $this->assertSame(0, app(StoreBalanceLedger::class)->snapshot($this->customer)->balance());
        $this->assertSame(2, Order::count());
    }

    public function test_changed_payload_on_the_same_key_conflicts_and_a_rolled_back_claim_stays_retryable(): void
    {
        $product = $this->product();
        $other = $this->product();
        $this->fundUsd(2_000_000);
        $this->send($this->body($product), 'retryable');
        $cartsBefore = Cart::count();

        foreach ([
            ['product_id' => (string) $other->id, 'quantity' => 1, 'expected_totals' => ['USD' => '2']],
            ['product_id' => (string) PHP_INT_MAX, 'quantity' => 1, 'expected_totals' => ['USD' => '2']],
            ['product_id' => (string) $product->id, 'quantity' => 2, 'expected_totals' => ['USD' => '2']],
            ['product_id' => (string) $product->id, 'quantity' => 1, 'preference' => 'idr_only', 'expected_totals' => ['USD' => '2']],
            ['product_id' => (string) $product->id, 'quantity' => 1, 'expected_totals' => ['USD' => '3']],
            ['product_id' => (string) $product->id, 'quantity' => 1, 'expected_totals' => ['USD' => '2', 'IDR' => '15000']],
        ] as $index => $changed) {
            $failure = $this->failure($changed, 'retryable');
            $this->assertSame(409, $failure->status, 'changed payload '.$index);
            $this->assertSame('idempotency_conflict', $failure->machineCode);
        }

        $this->assertSame(1, Order::count());
        $this->assertSame($cartsBefore, Cart::count());
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->where('kind', 'purchase_debit')->count());

        // A financially rolled-back request is never an accepted claim and
        // leaves its lease recoverable for the same key.
        $failure = $this->failure($this->body($other), 'exhausted');
        $this->assertSame(402, $failure->status);
        $claim = CustomerApiOrderClaim::where('idempotency_key', 'exhausted')->sole();
        $this->assertSame(CustomerApiOrderClaim::STATE_IN_PROGRESS, $claim->state);
        $this->assertNull($claim->order_id);
        $this->assertNull($claim->attempt_token);
        $this->assertSame($cartsBefore, Cart::count());
        $this->assertSame(0, app(CustomerUsdtBalanceLedger::class)->snapshot($this->customer)->balanceMicro());

        $this->fundUsd(2_000_000);
        $retried = $this->send($this->body($other), 'exhausted');
        $this->assertSame(201, $retried->getStatusCode());
        $this->assertSame(CustomerApiOrderClaim::STATE_ACCEPTED, $claim->fresh()->state);
        $this->assertSame(2, Order::count());
        $this->assertSame(2, Cart::where('status', CartStatus::CheckedOut)->count());
        $this->assertSame(0, Cart::where('origin', CartOrigin::Customer)->count());
    }

    public function test_a_worker_that_lost_its_claim_lease_cannot_commit_a_purchase(): void
    {
        $product = $this->product();
        $this->fundUsd(5_000_000);
        CustomerApiOrderClaim::created(function (CustomerApiOrderClaim $claim): void {
            // Simulate takeover while the original worker was suspended after admission.
            DB::table('customer_api_order_claims')->where('id', $claim->id)->update([
                'attempt_token' => str_repeat('b', 32),
                'lease_expires_at' => now()->addMinute(),
            ]);
        });

        try {
            $failure = $this->failure($this->body($product), 'stale-worker');
            $this->assertSame('in_progress', $failure->machineCode);
            $this->assertSame(409, $failure->status);
        } finally {
            CustomerApiOrderClaim::flushEventListeners();
        }

        $this->assertSame(0, Order::count());
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, Cart::count());
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->where('kind', 'purchase_debit')->count());
        $this->assertSame(5_000_000, app(CustomerUsdtBalanceLedger::class)->snapshot($this->customer)->balanceMicro());
        $this->assertSame(1, ProductStockItem::whereNull('order_item_id')->count());
        $this->assertSame(str_repeat('b', 32), CustomerApiOrderClaim::sole()->attempt_token);
    }

    public function test_stale_expected_totals_and_missing_primary_consent_never_charge(): void
    {
        $product = $this->product();
        $this->fundIdr(20_000);
        $this->fundUsd(5_000_000);

        $missing = $this->failure(['product_id' => (string) $product->id, 'quantity' => 1,
            'preference' => 'usd_first'], 'consent-missing');
        $this->assertSame(422, $missing->status);
        $this->assertSame('invalid_input', $missing->machineCode);

        $stale = $this->failure(['product_id' => (string) $product->id, 'quantity' => 1,
            'preference' => 'usd_first', 'expected_totals' => ['USD' => '1500000']], 'consent-stale');
        $this->assertSame(409, $stale->status);
        $this->assertSame('price_changed', $stale->machineCode);
        $this->assertSame([['currency' => 'IDR', 'total' => '15000'], ['currency' => 'USD', 'total' => '2']], $stale->details['quotes']);

        // A stale secondary rejects even when the primary wallet would cover it.
        $secondary = $this->failure(['product_id' => (string) $product->id, 'quantity' => 1,
            'preference' => 'usd_first', 'expected_totals' => ['USD' => '2', 'IDR' => '16000']], 'consent-secondary');
        $this->assertSame(409, $secondary->status);
        $this->assertSame('price_changed', $secondary->machineCode);

        $this->assertSame(0, Order::count());
        $this->assertSame(20_000, app(StoreBalanceLedger::class)->snapshot($this->customer)->balance());
        $this->assertSame(5_000_000, app(CustomerUsdtBalanceLedger::class)->snapshot($this->customer)->balanceMicro());
        $this->assertSame(0, Cart::count());
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->where('kind', 'purchase_debit')->count());
        $this->assertSame(0, DB::table('store_balance_entries')->where('kind', 'purchase_debit')->count());
        $this->assertSame(0, CustomerApiOrderClaim::whereNotNull('order_id')->count());
    }

    public function test_malformed_body_header_and_unsupported_fields_are_rejected_before_any_claim(): void
    {
        $product = $this->product();
        $base = $this->body($product);

        foreach ([
            $base + ['inputs' => ['email' => 'x@y.z']],
            $base + ['unknown' => true],
            ['product_id' => $product->id, 'quantity' => 1, 'expected_totals' => ['USD' => '2']],
            ['product_id' => '01', 'quantity' => 1, 'expected_totals' => ['USD' => '2']],
            ['product_id' => '0', 'quantity' => 1, 'expected_totals' => ['USD' => '2']],
            ['product_id' => (string) $product->id, 'quantity' => 0, 'expected_totals' => ['USD' => '2']],
            ['product_id' => (string) $product->id, 'quantity' => 101, 'expected_totals' => ['USD' => '2']],
            ['product_id' => (string) $product->id, 'quantity' => 1.5, 'expected_totals' => ['USD' => '2']],
            ['product_id' => (string) $product->id, 'quantity' => '1', 'expected_totals' => ['USD' => '2']],
            ['product_id' => (string) $product->id, 'quantity' => 1, 'preference' => 'mixed', 'expected_totals' => ['USD' => '2']],
            ['product_id' => (string) $product->id, 'quantity' => 1, 'expected_totals' => ['EUR' => '2']],
            ['product_id' => (string) $product->id, 'quantity' => 1, 'expected_totals' => ['USD' => '1.0000001']],
            ['product_id' => (string) $product->id, 'quantity' => 1, 'expected_totals' => ['USD' => '-1']],
            ['product_id' => (string) $product->id, 'quantity' => 1, 'expected_totals' => ['IDR' => '15.5']],
            ['product_id' => (string) $product->id, 'quantity' => 1, 'expected_totals' => []],
        ] as $index => $body) {
            $failure = $this->failure($body, 'malformed-'.$index);
            $this->assertSame(422, $failure->status, 'malformed body '.$index);
            $this->assertSame('invalid_input', $failure->machineCode);
        }

        foreach ([null, '', str_repeat('a', 129), "key\nwith-control", 'key-ñ'] as $index => $key) {
            $failure = $this->failure($base, $key);
            $this->assertSame(422, $failure->status, 'malformed header '.$index);
            $this->assertSame('invalid_input', $failure->machineCode);
        }
        foreach (['"scalar"', '["list"]', '{bad json', ''] as $index => $raw) {
            $failure = $this->failure($base, 'body-shape-'.$index, $raw);
            $this->assertSame(422, $failure->status, 'non-object body '.$index);
        }

        $this->assertSame(0, CustomerApiOrderClaim::query()->count());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Cart::count());
    }

    public function test_reseller_usd_override_above_a_zero_base_usd_price_never_charges_usd_at_checkout(): void
    {
        $product = $this->product();
        // The guard rejects this pair on write, so the malformed row is staged the
        // way legacy data exists: a zero base price that survived an override.
        $product->forceFill(['usdt_price_amount' => 0])->save();
        DB::table('products')->where('id', $product->id)->update(['reseller_usdt_price_amount' => 400_000]);
        $this->fundUsd(5_000_000);

        // The account preference is USD first, so the override amount is what the
        // customer would try to acknowledge. The quote's dollar total is 0 instead,
        // and a zero total is not even representable as consent, so the malformed
        // row can never become a dollar charge.
        $unacknowledged = $this->failure(['product_id' => (string) $product->id, 'quantity' => 1,
            'preference' => 'usd_first', 'expected_totals' => ['USD' => '0.4']], 'zero-base-usd');
        $this->assertSame(409, $unacknowledged->status);
        $this->assertSame('price_changed', $unacknowledged->machineCode);
        $this->assertContains(['currency' => 'USD', 'total' => '0'], $unacknowledged->details['quotes']);

        $zeroConsent = $this->failure(['product_id' => (string) $product->id, 'quantity' => 1,
            'preference' => 'usd_first', 'expected_totals' => ['USD' => '0']], 'zero-base-usd-zero');
        $this->assertSame(422, $zeroConsent->status);
        $this->assertSame('invalid_input', $zeroConsent->machineCode);

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Cart::count());
        $this->assertSame(5_000_000, app(CustomerUsdtBalanceLedger::class)->snapshot($this->customer)->balanceMicro());
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->where('kind', 'purchase_debit')->count());
    }

    public function test_api_carts_stay_invisible_to_bot_and_storefront_cart_surfaces(): void
    {
        $product = $this->product();
        $this->fundUsd(5_000_000);
        $carts = app(CartService::class);
        $this->assertNull($carts->currentActiveCartFor($this->customer));

        $this->send($this->body($product), 'claim-cart');
        $apiCartId = (int) Cart::sole()->getKey();

        $this->assertNull($carts->currentActiveCartFor($this->customer));
        $botCart = $carts->activeCartFor($this->customer);
        $this->assertNotSame($apiCartId, (int) $botCart->getKey());
        $this->assertSame(CartOrigin::Customer, $botCart->origin);
        $this->assertSame(0, $botCart->cartItems()->count());
        $this->assertSame(2, Cart::count());

        // The bot "cancel active carts" write is origin-scoped exactly as the
        // Telegram cancel paths issue it and cannot touch the API cart.
        $this->customer->carts()->where('origin', CartOrigin::Customer)->where('status', CartStatus::Active)
            ->update(['status' => CartStatus::Abandoned]);
        $this->assertSame(CartStatus::CheckedOut, Cart::findOrFail($apiCartId)->status);
        $this->assertSame(CartStatus::Abandoned, $botCart->fresh()->status);

        // Customer cart mutations reject an API cart outright.
        try {
            $carts->addItem(Cart::findOrFail($apiCartId), $product, 1);
            $this->fail('An API cart accepted a customer cart mutation.');
        } catch (InvalidCartState) {
            $this->assertSame(1, Cart::findOrFail($apiCartId)->cartItems()->count());
        }

        // The storefront "resume last cart" and "current cart" surfaces use the
        // same origin filter, so an accepted API order never becomes a Web cart.
        $session = app('session.store');
        $session->put(StorefrontGuestCart::CLIENT_KEY, 'guest-client');
        $session->put('storefront.guest_customer_id', (int) $this->customer->id);
        $session->put('storefront.guest_customer_client_key', 'guest-client');
        $this->assertNull(app(StorefrontGuestCart::class)->checkoutCandidate($session));
        $this->assertNull(app(StorefrontGuestCart::class)->current($session));
    }

    public function test_unknown_product_revoked_key_and_hidden_product_fail_closed_without_mutation(): void
    {
        $product = $this->product();
        $hidden = Product::factory()->create(['is_active' => false, 'price_amount' => 15_000, 'usdt_price_amount' => 2_000_000]);
        ProductStockItem::create(['product_id' => $hidden->id, 'secret' => 'hidden-secret']);
        $this->fundUsd(5_000_000);

        $missing = $this->failure($this->body(['id' => PHP_INT_MAX]), 'missing-product');
        $this->assertSame(404, $missing->status);
        $this->assertSame('not_found', $missing->machineCode);
        $this->assertSame(0, CustomerApiOrderClaim::query()->count());

        $notPublic = $this->failure($this->body($hidden), 'hidden-product');
        $this->assertSame(404, $notPublic->status);
        $this->assertSame(CustomerApiOrderClaim::where('idempotency_key', 'hidden-product')->sole()->state, CustomerApiOrderClaim::STATE_IN_PROGRESS);

        app(CustomerApiCredentials::class)->disable($this->customer);
        $revoked = $this->failure($this->body($product), 'revoked-key');
        $this->assertSame(401, $revoked->status);
        $this->assertSame('unauthenticated', $revoked->machineCode);

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Cart::count());
        $this->assertSame(0, CustomerApiOrderClaim::query()->whereNotNull('order_id')->count());
        $this->assertSame(5_000_000, app(CustomerUsdtBalanceLedger::class)->snapshot($this->customer)->balanceMicro());
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->where('kind', 'purchase_debit')->count());
    }

    public function test_claim_and_order_api_origin_identity_are_immutable(): void
    {
        $product = $this->product();
        $this->fundUsd(5_000_000);
        $this->send($this->body($product), 'claim-immutable');
        $claim = CustomerApiOrderClaim::sole();
        $order = Order::sole();
        $cart = Cart::sole();

        foreach ([['fingerprint' => str_repeat('a', 64)], ['quantity' => 2],
            ['state' => CustomerApiOrderClaim::STATE_IN_PROGRESS], ['order_id' => null]] as $dirty) {
            try {
                $claim->forceFill($dirty)->save();
                $this->fail('Claim identity mutation was accepted: '.json_encode($dirty));
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }
        try {
            $claim->delete();
            $this->fail('Claim deletion was accepted.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
        try {
            $order->forceFill(['api_order_claim_id' => null])->save();
            $this->fail('Order API origin mutation was accepted.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
        try {
            $cart->forceFill(['origin' => CartOrigin::Customer])->save();
            $this->fail('Cart origin mutation was accepted.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        $this->assertSame('claim-immutable', $claim->fresh()->idempotency_key);
        $this->assertSame($claim->id, $order->fresh()->api_order_claim_id);
        $this->assertSame(CartOrigin::Api, $cart->fresh()->origin);
    }

    public function test_model_rejects_acceptance_without_an_order_on_create_and_update(): void
    {
        $product = $this->product();
        $this->assertSame('insufficient_funds', $this->failure($this->body($product), 'invalid-acceptance')->machineCode);
        $claim = CustomerApiOrderClaim::sole();

        foreach ([false, true] as $creating) {
            $invalid = $creating ? $claim->replicate() : $claim->fresh();
            $invalid->forceFill(['state' => CustomerApiOrderClaim::STATE_ACCEPTED, 'order_id' => null]);
            try {
                $invalid->save();
                $this->fail('An acceptance without an Order was saved.');
            } catch (LogicException $error) {
                $this->assertSame('Customer API order claim acceptance requires exactly one Order.', $error->getMessage());
            }
        }

        $this->assertSame(CustomerApiOrderClaim::STATE_IN_PROGRESS, $claim->fresh()->state);
        $this->assertNull($claim->fresh()->order_id);
    }

    /**
     * @param  array<string, mixed>|Product  $product
     * @return array<string, mixed>
     */
    private function body(array|Product $product): array
    {
        $id = $product instanceof Product ? $product->id : $product['id'];

        return ['product_id' => (string) $id, 'quantity' => 1, 'expected_totals' => ['USD' => '2']];
    }

    private function product(int $stock = 1): Product
    {
        $product = Product::factory()->create(['price_amount' => 15_000, 'usdt_price_amount' => 2_000_000, 'stock' => $stock, 'reserved_stock' => 0]);
        for ($index = 0; $index < $stock; $index++) {
            ProductStockItem::create(['product_id' => $product->id, 'secret' => 'api-order-secret-'.$product->id.'-'.$index]);
        }

        return $product;
    }

    private function fundIdr(int $amount): void
    {
        $sequence = ++$this->fundingSequence;
        app(StoreBalanceLedger::class)->creditTopUp($this->customer, $amount, 'api-fixture-idr-'.$sequence, 'api-fixture-idr-'.$sequence);
    }

    private function fundUsd(int $micro): void
    {
        $sequence = ++$this->fundingSequence;
        app(CustomerUsdtBalanceLedger::class)->creditVerifiedTopUp(
            $this->customer,
            new VerifiedUsdtFunding($this->customer->id, UsdtMicroAmount::fromUnits($micro), 'api-fixture-usd-'.$sequence, 'api-fixture-receipt-'.$sequence, 'v1'),
            'api-fixture-usd-'.$sequence,
        );
    }

    private function send(array $body, ?string $key, ?string $raw = null): JsonResponse
    {
        $request = Request::create('/api/v1/orders', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw ?? json_encode($body, JSON_THROW_ON_ERROR));
        if ($key !== null) {
            $request->headers->set('Idempotency-Key', $key);
        }
        $request->attributes->set('customer_api_principal', $this->principal);
        $request->attributes->set('customer_api_request_id', 'request-fixture');

        return app()->call([app(CustomerApiOrderController::class), 'store'], ['request' => $request]);
    }

    private function failure(array $body, ?string $key, ?string $raw = null): CustomerApiFailure
    {
        try {
            $this->send($body, $key, $raw);
        } catch (CustomerApiFailure $failure) {
            return $failure;
        }

        $this->fail('An unsafe API order request was accepted.');
    }
}
