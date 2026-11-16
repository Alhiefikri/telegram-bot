<?php

namespace Tests\Feature;

use App\Enums\CredentialFormat;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Jobs\SendTelegramReceipt;
use App\Models\Customer;
use App\Models\ManualFulfillmentReview;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\TelegramOutboundEffect;
use App\Models\User;
use App\Services\Admin\ManualFulfillmentResolutionService;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\OrderLifecycle;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\Telegram\ReceiptDeliveryPipeline;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\Telegram\TelegramPaidOrderTxtExporter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NonTransactionalDatabaseTestCase;

final class UniqueCredentialEmailTest extends NonTransactionalDatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['services.telegram.token' => 'fixture-token', 'services.telegram.admin_telegram_chat_id' => '999123']);
    }

    public function test_default_disabled_product_allows_the_same_customer_to_receive_the_same_email_again(): void
    {
        $customer = $this->customer();
        $product = Product::factory()->create(['price_amount' => 5000, 'stock' => 0, 'reserved_stock' => 0]);
        $this->assertFalse($product->fresh()->unique_credential_email);
        $this->restock($product, ['repeat@example.test|old-password']);
        $this->purchase($customer, $product);
        $duplicate = $this->restock($product, ['REPEAT@example.test|new-password'])[0];

        $order = $this->purchase($customer, $product);

        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame($order->orderItems()->sole()->id, $duplicate->fresh()->order_item_id);
        $this->assertFalse($order->orderItems()->sole()->unique_credential_email);
        $this->assertDatabaseCount('manual_fulfillment_reviews', 0);
        $this->assertSame(0, $product->fresh()->stock);
    }

    public static function credentialFormats(): array
    {
        return [
            'pipe history, dash replacement' => ['pipe', 'dash'],
            'dash history, structured replacement' => ['dash', 'structured'],
            'structured history, pipe replacement' => ['structured', 'pipe'],
        ];
    }

    #[DataProvider('credentialFormats')]
    public function test_enabled_checkout_skips_historical_email_despite_case_whitespace_and_password_changes(string $historyFormat, string $replacementFormat): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $this->restock($product, [$this->credential($historyFormat, '  Repeat@Example.test ', 'old-password')]);
        $first = $this->purchase($customer, $product);
        [$duplicate, $unknown, $eligible] = $this->restock($product, [
            $this->credential($replacementFormat, ' REPEAT@example.test ', 'changed-password'),
            'not-an-email|unparseable-password',
            $this->credential($replacementFormat, 'fresh@example.test', 'fresh-password'),
        ]);

        $second = $this->purchase($customer, $product);

        $this->assertSame(OrderStatus::Completed, $first->fresh()->order_status);
        $this->assertSame(OrderStatus::Completed, $second->fresh()->order_status);
        $this->assertSame($second->orderItems()->sole()->id, $eligible->fresh()->order_item_id);
        $this->assertNull($duplicate->fresh()->order_item_id);
        $this->assertNull($unknown->fresh()->order_item_id);
        $this->assertSame(2, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertDatabaseCount('manual_fulfillment_reviews', 0);
    }

    public function test_different_customers_can_receive_the_same_email_for_the_same_product(): void
    {
        $product = $this->product();
        $this->restock($product, ['shared@example.test|first-password', 'SHARED@example.test|second-password']);
        $first = $this->purchase($this->customer(), $product);
        $second = $this->purchase($this->customer(), $product);

        $this->assertSame(OrderStatus::Completed, $first->fresh()->order_status);
        $this->assertSame(OrderStatus::Completed, $second->fresh()->order_status);
        $this->assertSame(2, ProductStockItem::where('product_id', $product->id)->whereNotNull('order_item_id')->count());
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertDatabaseCount('manual_fulfillment_reviews', 0);
    }

    public function test_customer_email_history_is_independent_between_products(): void
    {
        $customer = $this->customer();
        $firstProduct = $this->product();
        $secondProduct = $this->product();
        $this->restock($firstProduct, ['shared@example.test|first-password']);
        $this->restock($secondProduct, ['SHARED@example.test|second-password']);

        $first = $this->purchase($customer, $firstProduct);
        $second = $this->purchase($customer, $secondProduct);

        $this->assertSame(OrderStatus::Completed, $first->fresh()->order_status);
        $this->assertSame(OrderStatus::Completed, $second->fresh()->order_status);
        $this->assertDatabaseCount('manual_fulfillment_reviews', 0);
    }

    public function test_refunded_and_retired_email_is_not_reissued_to_the_same_customer(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $original = $this->restock($product, ['retired@example.test|old-password'])[0];
        $first = $this->purchase($customer, $product);
        app(OrderLifecycle::class)->applyPaymentRefunded($first->payments()->sole());
        $this->assertNotNull($original->fresh()->retired_at);
        [$duplicate, $fresh] = $this->restock($product, [
            'RETIRED@example.test|changed-password', 'fresh@example.test|fresh-password',
        ]);

        $second = $this->purchase($customer, $product);

        $this->assertSame(OrderStatus::Completed, $second->order_status);
        $this->assertNull($duplicate->fresh()->order_item_id);
        $this->assertSame($second->orderItems()->sole()->id, $fresh->fresh()->order_item_id);
        $this->assertSame(1, $product->fresh()->stock);
    }

    public function test_multiunit_checkout_never_assigns_the_same_email_twice_within_its_batch(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $duplicates = $this->restock($product, ['batch@example.test|first-password', ' BATCH@example.test |second-password']);
        $distinct = $this->restock($product, ['distinct@example.test|third-password'])[0];

        $order = $this->purchase($customer, $product, 3);

        $this->assertProcessingReview($order, 3, 1);
        $this->assertSame(1, ProductStockItem::whereIn('id', array_map(fn (ProductStockItem $item): int => $item->id, $duplicates))->whereNotNull('order_item_id')->count());
        $this->assertSame($order->orderItems()->sole()->id, $distinct->fresh()->order_item_id);
        $this->assertSame(1, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertSame(35_000, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
    }

    public static function availableUniqueUnits(): array
    {
        return ['none eligible' => [false], 'partial eligible' => [true]];
    }

    #[DataProvider('availableUniqueUnits')]
    public function test_paid_checkout_keeps_full_entitlement_and_reviews_missing_unique_units_without_consuming_duplicates(bool $partial): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $this->restock($product, ['repeat@example.test|original-password']);
        $this->purchase($customer, $product);
        $stock = $this->restock($product, [
            'REPEAT@example.test|changed-password',
            $partial ? 'eligible@example.test|eligible-password' : ' repeat@example.test |another-password',
        ]);

        $order = $this->purchase($customer, $product, 2);
        app(OrderLifecycle::class)->applyPaymentPaid($order->payments()->sole());
        app(OrderLifecycle::class)->applyPaymentPaid($order->payments()->sole());

        $this->assertProcessingReview($order, 2, $partial ? 1 : 2);
        $this->assertNull($stock[0]->fresh()->order_item_id);
        $this->assertSame($partial ? 1 : 2, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertSame(35_000, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        $this->assertSame(1, ManualFulfillmentReview::where('order_id', $order->id)->count());
        $this->actingAs($customer, 'customer')->get(route('customer.orders.index'))
            ->assertOk()->assertSee($order->invoice_number)->assertSee('Diproses')
            ->assertDontSee('unique_email_unavailable')->assertDontSee('unique_credential_email');
        $this->actingAs($customer, 'customer')->get(route('customer.orders.show', $order))
            ->assertOk()->assertSee($order->invoice_number)
            ->assertDontSee('unique_email_unavailable')->assertDontSee('unique_credential_email')
            ->assertDontSee('changed-password')->assertDontSee('another-password');
    }

    #[DataProvider('availableUniqueUnits')]
    public function test_pending_cancellation_releases_only_physical_reservations(bool $partial): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $this->restock($product, ['repeat@example.test|original-password']);
        $this->purchase($customer, $product);
        [$duplicate, $other] = $this->restock($product, [
            'repeat@example.test|changed-password',
            $partial ? 'eligible@example.test|eligible-password' : 'REPEAT@example.test|another-password',
        ]);
        $order = $this->purchase($customer, $product, 2, manual: true);

        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
        $this->assertSame(2, $product->fresh()->stock);
        $this->assertSame($partial ? 1 : 0, $product->fresh()->reserved_stock);
        $this->assertSame($partial ? 1 : 2, $order->orderItems()->sole()->deferred_local_quantity);
        $this->assertNull($duplicate->fresh()->order_item_id);
        app(OrderLifecycle::class)->applyPendingCancellation($order);
        app(OrderLifecycle::class)->applyPendingCancellation($order->fresh());

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->order_status);
        $this->assertSame(2, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertNull($duplicate->fresh()->order_item_id);
        $this->assertNull($other->fresh()->order_item_id);
        $this->assertSame(45_000, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
    }

    public function test_cancelled_unpaid_email_can_be_purchased_again_without_a_false_duplicate_hold(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $stock = $this->restock($product, ['cancelled@example.test|original-password'])[0];
        $pending = $this->purchase($customer, $product, manual: true);
        $this->assertSame($pending->orderItems()->sole()->id, $stock->fresh()->order_item_id);
        app(OrderLifecycle::class)->applyPendingCancellation($pending);
        $carts = app(CartService::class);
        $carts->removeItem($carts->activeCartFor($customer), $product);

        $next = $this->purchase($customer, $product);

        $this->assertSame(OrderStatus::Completed, $next->order_status);
        $this->assertSame($next->orderItems()->sole()->id, $stock->fresh()->order_item_id);
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertSame(45_000, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        $this->assertSame(0, ManualFulfillmentReview::where('order_id', $next->id)->count());
    }

    public function test_paid_deferred_order_refund_preserves_duplicate_stock_and_does_not_complete_it(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $this->restock($product, ['repeat@example.test|old-password']);
        $this->purchase($customer, $product);
        $duplicate = $this->restock($product, ['repeat@example.test|changed-password'])[0];
        $held = $this->purchase($customer, $product);
        $this->assertProcessingReview($held, 1, 1);

        app(OrderLifecycle::class)->applyPaymentRefunded($held->payments()->sole());
        app(OrderLifecycle::class)->applyPaymentRefunded($held->payments()->sole());

        $this->assertSame(PaymentStatus::Refunded, $held->fresh()->payment_status);
        $this->assertNotSame(OrderStatus::Completed, $held->fresh()->order_status);
        $this->assertNull($duplicate->fresh()->order_item_id);
        $this->assertNull($duplicate->fresh()->retired_at);
        $this->assertSame(1, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertSame(45_000, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        $this->assertSame(0, $held->orderItems()->sole()->stockItems()->count());
    }

    public static function rejectedManualCredentials(): array
    {
        return [
            'historical email after valid first unit' => [['fresh@example.test|fresh-password', ' REPEAT@example.test |changed-password']],
            'duplicate in submitted batch' => [['fresh@example.test|first-password', 'FRESH@example.test|second-password']],
            'unknown email after valid first unit' => [['fresh@example.test|fresh-password', 'not-an-account']],
        ];
    }

    #[DataProvider('rejectedManualCredentials')]
    public function test_manual_assignment_rejects_and_rolls_back_the_whole_batch_even_after_product_policy_is_disabled(array $credentials): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $this->restock($product, ['repeat@example.test|original-password']);
        $history = $this->purchase($customer, $product);
        $this->restock($product, ['repeat@example.test|changed-password', 'REPEAT@example.test|another-password']);
        $order = $this->purchase($customer, $product, 2);
        $review = ManualFulfillmentReview::where('order_id', $order->id)->sole();
        $item = $order->orderItems()->sole();
        $product->forceFill(['unique_credential_email' => false])->save();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->assertTrue($item->fresh()->unique_credential_email);
        $this->actingAs($owner)->get(route('admin.manual-fulfillment-reviews.show', $review))
            ->assertOk()->assertSee('repeat@example.test')->assertSee($history->invoice_number);
        Queue::fake();

        try {
            app(ManualFulfillmentResolutionService::class)->provideLocalCredentials($review, $owner, [$item->id => $credentials], 'Complete missing local units.');
            $this->fail('Manual assignment accepted an unknown or duplicate credential email.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('credentials', $exception->errors());
        }

        $this->assertProcessingReview($order, 2, 2);
        $this->assertSame(0, ProductStockItem::where('order_item_id', $item->id)->count());
        $this->assertDatabaseCount('product_stock_items', 3);
        $this->assertSame(2, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        Queue::assertNotPushed(SendTelegramReceipt::class);
    }

    public function test_manual_completion_adds_order_owned_units_without_consuming_duplicate_stock_and_replays_once(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $this->restock($product, ['repeat@example.test|original-password']);
        $this->purchase($customer, $product);
        [$duplicate, $eligible] = $this->restock($product, ['repeat@example.test|changed-password', 'eligible@example.test|eligible-password']);
        $order = $this->purchase($customer, $product, 2);
        $review = ManualFulfillmentReview::where('order_id', $order->id)->sole();
        $item = $order->orderItems()->sole();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $product->forceFill(['unique_credential_email' => false])->save();
        Queue::fake();
        $resolution = app(ManualFulfillmentResolutionService::class);

        $resolved = $resolution->provideLocalCredentials($review, $owner, [$item->id => [$this->credential('structured', 'manual@example.test', 'manual-password')]], 'Provide the missing account.');
        $replay = $resolution->provideLocalCredentials($review->fresh(), $owner, [], 'Replay after a lost response.');

        $this->assertSame('fulfilled', $resolved->state);
        $this->assertSame('fulfilled', $replay->state);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertTrue($item->fresh()->unique_credential_email);
        $this->assertSame(2, $item->fresh()->localQuantity());
        $this->assertSame(2, ProductStockItem::where('order_item_id', $item->id)->count());
        $manual = ProductStockItem::where('order_item_id', $item->id)->where('id', '!=', $eligible->id)->sole();
        $this->assertSame($product->id, $manual->product_id);
        $this->assertNull($manual->supplier_purchase_id);
        $this->assertSame('manual@example.test', $manual->credential_payload['email']);
        $this->assertNull($duplicate->fresh()->order_item_id);
        $this->assertSame(1, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertSame(35_000, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        Queue::assertPushed(SendTelegramReceipt::class, 1);
        $this->actingAs($customer, 'customer')->get(route('customer.orders.show', $order))
            ->assertOk()->assertSee('manual@example.test')->assertSee('eligible@example.test')
            ->assertDontSee('changed-password')->assertDontSee('unique_email_unavailable');
    }

    public function test_single_manual_redeem_code_is_delivered_after_recovery_waits_for_input(): void
    {
        $customer = $this->customer();
        $product = Product::factory()->create([
            'manual_unlimited' => true, 'price_amount' => 5000, 'stock' => 0, 'reserved_stock' => 0,
        ]);
        $order = $this->purchase($customer, $product);
        $item = $order->orderItems()->sole();
        $review = ManualFulfillmentReview::where('order_id', $order->id)->sole();

        $this->artisan('telegram:recover-outbound')->assertSuccessful();
        $this->assertNull(app(TelegramOutboundEffectService::class)->forOrder(
            (string) $order->id,
            TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS,
        ));

        $owner = User::factory()->create(['role' => UserRole::Owner]);
        app(ManualFulfillmentResolutionService::class)->provideLocalCredentials(
            $review, $owner, [$item->id => ['REDEEM-CODE-123']],
        );
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 123]])]);
        $receipt = app(TelegramOutboundEffectService::class)->recordReceipt($order->fresh());
        $this->assertNotNull($receipt);
        $effects = TelegramOutboundEffect::query()->where('effect_group_key', $receipt->effect_group_key)->orderBy('sequence')->get();
        foreach ($effects as $effect) {
            app(ReceiptDeliveryPipeline::class)->deliver($effect, dispatchNext: false);
        }

        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
        Http::assertSent(function ($request) use ($customer): bool {
            if (! str_ends_with($request->url(), '/sendPhoto') && ! str_ends_with($request->url(), '/sendMessage')) {
                return false;
            }
            $data = $request->data();
            foreach ($data as $part) {
                if (is_array($part) && isset($part['name'])) {
                    $data[$part['name']] = $part['contents'] ?? null;
                }
            }

            return (string) ($data['chat_id'] ?? '') === (string) $customer->telegram_id
                && str_contains((string) ($data['caption'] ?? $data['text'] ?? ''), 'REDEEM-CODE-123');
        });
    }

    public function test_unlimited_manual_order_stays_paid_pending_until_accounts_are_supplied(): void
    {
        $customer = $this->customer();
        $customer->update(['username' => null]);
        $product = Product::factory()->create([
            'manual_unlimited' => true, 'price_amount' => 5000, 'stock' => 0, 'reserved_stock' => 0,
        ]);

        $order = $this->purchase($customer, $product, 2);
        $item = $order->orderItems()->sole();
        $review = ManualFulfillmentReview::where('order_id', $order->id)->sole();
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        $this->assertSame(ManualFulfillmentReview::REASON_MANUAL_UNLIMITED, $review->reason);
        $this->assertSame(2, $item->deferred_local_quantity);
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertSame(0, $item->stockItems()->count());
        $this->assertTrue($item->manual_unlimited);

        $this->artisan('telegram:recover-outbound')->assertSuccessful();
        $this->assertNull(app(TelegramOutboundEffectService::class)->forOrder(
            (string) $order->id,
            TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS,
        ));
        $this->assertNull(app(TelegramOutboundEffectService::class)->recordReceipt($order->fresh()));

        $product->forceFill(['manual_unlimited' => false])->save();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->actingAs($owner)->get(route('admin.suppliers.purchases', ['invoice' => $order->invoice_number]))
            ->assertOk()->assertSee($order->invoice_number);
        Queue::fake();
        $resolution = app(ManualFulfillmentResolutionService::class);
        try {
            $resolution->provideLocalCredentials($review, $owner, [$item->id => ['only-one']], 'Incomplete submission');
            $this->fail('A partial manual delivery must not be accepted.');
        } catch (ValidationException) {
            $this->assertSame(0, $item->stockItems()->count());
        }
        $resolution->provideLocalCredentials($review, $owner, [$item->id => ['account-one', 'account-two']], 'Complete order');
        $resolution->provideLocalCredentials($review->fresh(), $owner, [], 'Retry');

        $this->assertSame(2, $item->stockItems()->count());
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertTrue($item->fresh()->manual_unlimited);
        Queue::assertPushed(SendTelegramReceipt::class, 1);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 123]])]);
        $receipt = app(TelegramOutboundEffectService::class)->recordReceipt($order->fresh());
        $this->assertNotNull($receipt);
        $this->actingAs($owner)->get(route('admin.suppliers.purchases', ['invoice' => $order->invoice_number]))
            ->assertOk()->assertSee($order->invoice_number)->assertDontSee('account-one');
        $effects = TelegramOutboundEffect::query()->where('effect_group_key', $receipt->effect_group_key)->orderBy('sequence')->get();
        foreach ($effects as $effect) {
            app(ReceiptDeliveryPipeline::class)->deliver($effect, dispatchNext: false);
        }
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
        $this->assertSame((string) $customer->telegram_id, (string) $receipt->telegram_chat_id);
        $document = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendDocument'))->sole()[0];
        $payload = app(TelegramPaidOrderTxtExporter::class)->export($order->fresh(), $customer->fresh());
        $this->assertNotNull($payload);
        $this->assertTrue($document->hasFile('document', $payload->documentContents(), $payload->filename()));
        $this->assertStringContainsString('account-one', $payload->documentContents());
        $this->assertStringContainsString('account-two', $payload->documentContents());
        $data = $document->data();
        foreach ($data as $part) {
            if (is_array($part) && ($part['name'] ?? null) === 'chat_id') {
                $data['chat_id'] = $part['contents'];
            }
        }
        $this->assertSame((string) $customer->telegram_id, (string) ($data['chat_id'] ?? ''));
    }

    private function assertProcessingReview(Order $order, int $quantity, int $deferred): void
    {
        $order = $order->fresh();
        $item = $order->orderItems()->sole();
        $review = ManualFulfillmentReview::where('order_id', $order->id)->sole();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(PaymentStatus::Paid, $order->payments()->sole()->status);
        $this->assertSame(OrderStatus::Processing, $order->order_status);
        $this->assertSame('unique_email_unavailable', $review->reason);
        $this->assertSame('pending', $review->state);
        $this->assertSame($order->payments()->sole()->id, $review->payment_id);
        $this->assertSame($quantity * 5000, $review->amount);
        $this->assertSame('IDR', $review->currency);
        $this->assertTrue($item->unique_credential_email);
        $this->assertSame($quantity, $item->quantity);
        $this->assertSame($quantity, $item->localQuantity());
        $this->assertSame($deferred, $item->deferred_local_quantity);
        $this->assertSame($quantity - $deferred, $item->localStockQuantity());
        $this->assertSame($quantity - $deferred, ProductStockItem::where('order_item_id', $item->id)->count());
    }

    private function customer(): Customer
    {
        $customer = Customer::factory()->create(['email_verified_at' => now(), 'locale' => 'id']);
        app(StoreBalanceLedger::class)->creditTopUp($customer, 50_000, 'fixture-funding-'.$customer->id, 'fixture-funding-'.$customer->id);

        return $customer;
    }

    private function product(): Product
    {
        return Product::factory()->create(['unique_credential_email' => true, 'price_amount' => 5000, 'stock' => 0, 'reserved_stock' => 0]);
    }

    private function restock(Product $product, array $credentials): array
    {
        $items = [];
        foreach ($credentials as $credential) {
            $items[] = ProductStockItem::create(['product_id' => $product->id] + (is_array($credential)
                ? ['credential_format' => CredentialFormat::StructuredAccount, 'credential_payload' => $credential]
                : ['secret' => $credential]));
        }
        $product->refresh()->forceFill(['stock' => $product->stock + count($items)])->save();

        return $items;
    }

    private function credential(string $format, string $email, string $password): array|string
    {
        return match ($format) {
            'pipe' => $email.'|'.$password,
            'dash' => $email.'----'.$password.'----https://mfa.example.test/token',
            'structured' => ['version' => 1, 'email' => $email, 'password' => $password, 'access_method' => 'none'],
        };
    }

    private function purchase(Customer $customer, Product $product, int $quantity = 1, bool $manual = false): Order
    {
        $carts = app(CartService::class);
        $cart = $carts->activeCartFor($customer);
        $carts->addItem($cart, $product->fresh(), $quantity);

        return app(OrderCheckoutService::class)->checkout($cart,
            $manual ? CheckoutPaymentIntents::manualQris() : CheckoutPaymentIntents::storeBalance(),
            'unique-email-'.$cart->id);
    }
}
