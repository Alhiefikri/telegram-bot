<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Models\Customer;
use App\Models\ManualFulfillmentReview;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\ProductSupplierSource;
use App\Models\SalesProofPost;
use App\Models\StoreChannelSetting;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierFulfillment;
use App\Models\SupplierPricePolicy;
use App\Models\TelegramOutboundEffect;
use App\Services\CustomerApi\CustomerApiCredentials;
use App\Services\CustomerApi\CustomerApiOrderCheckout;
use App\Services\CustomerApi\CustomerApiOrderRequest;
use App\Services\PaidInvoice\PaidInvoiceOrderPresentationAdapter;
use App\Services\PaidInvoice\PaidInvoiceRenderer;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\Suppliers\SupplierAvailability;
use App\Services\Suppliers\SupplierFulfillmentService;
use App\Services\Telegram\AdminOrderNotificationDeliveryPipeline;
use App\Services\Telegram\SalesProofDeliveryPipeline;
use App\Services\Telegram\SalesProofEligibility;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\VerifiedUsdtFunding;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NonTransactionalDatabaseTestCase;

final class CustomerApiOrderNotificationTest extends NonTransactionalDatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['services.telegram.token' => 'fixture-token', 'services.telegram.admin_telegram_chat_id' => '999123']);
        StoreChannelSetting::create(['singleton_key' => StoreChannelSetting::SINGLETON_KEY, 'sales_proof_enabled' => true, 'telegram_chat_id' => '-100123']);
    }

    public static function currencies(): array
    {
        return [['IDR', 'Rp 5.000'], ['USDT', '2 USDT']];
    }

    #[DataProvider('currencies')]
    public function test_completed_api_order_publishes_once_with_safe_origin_and_original_money(string $currency, string $amount): void
    {
        [$order, $replay] = $this->purchase($currency);
        $this->assertSame($order->id, $replay()->id);
        $effects = app(TelegramOutboundEffectService::class);
        $proof = $effects->forOrder($order->id, $effects::TYPE_SALES_PROOF);
        $paid = $effects->forOrder($order->id, $effects::TYPE_ADMIN_PAID);
        $this->assertNotNull($proof);
        $this->assertNotNull($paid);
        $presentation = app(PaidInvoiceOrderPresentationAdapter::class)->present($order);
        $image = app(PaidInvoiceRenderer::class)->render($presentation);
        $this->assertSame('api', $image->metadata['origin']);
        $this->assertSame($currency, $image->metadata['currency']);
        $this->assertSame($currency === 'USDT' ? 2_000_000 : 5000, $image->metadata['total_paid']);
        $this->assertSame([720, 720], array_slice(getimagesizefromstring($image->bytes), 0, 2));
        $this->assertSafe(json_encode($image->metadata, JSON_THROW_ON_ERROR));
        $economic = $this->economics($order);
        Http::fake(function () {
            $this->assertSame(0, DB::transactionLevel());

            return Http::response(['ok' => true, 'result' => ['message_id' => 123]]);
        });
        app(SalesProofDeliveryPipeline::class)->deliver($proof);
        app(AdminOrderNotificationDeliveryPipeline::class)->deliver($paid);
        app(SalesProofDeliveryPipeline::class)->deliver($proof->fresh());
        app(AdminOrderNotificationDeliveryPipeline::class)->deliver($paid->fresh());
        $this->artisan('telegram:recover-outbound')->assertSuccessful();
        Http::assertSentCount(2);
        $public = (string) collect(Http::recorded()->first()[0]->data())->firstWhere('name', 'caption')['contents'];
        $owner = (string) Http::recorded()->last()[0]['text'];
        $this->assertStringContainsString('API', $public);
        $this->assertStringContainsString($amount, $public);
        $this->assertStringContainsString('Customer API', $owner);
        $this->assertStringContainsString($order->invoice_number, $owner);
        $this->assertStringContainsString('765432109', $owner);
        $this->assertSafe($public);
        $this->assertStringNotContainsString('765432109', $public);
        $this->assertSame(TelegramOutboundEffectState::Sent, $proof->fresh()->state);
        $this->assertSame(TelegramOutboundEffectState::Sent, $paid->fresh()->state);
        $this->assertSame($economic, $this->economics($order));
        $this->assertSame(1, SalesProofPost::where('order_id', $order->id)->count());
    }

    public function test_mixed_order_waits_for_supplier_completion_and_review_notice_is_durable(): void
    {
        [$order] = $this->purchase(supplier: true);
        $effects = app(TelegramOutboundEffectService::class);
        $this->assertNull($effects->forOrder($order->id, $effects::TYPE_SALES_PROOF));
        $this->assertNotNull(app(SalesProofEligibility::class)->rejectionReason($order));
        $economic = $this->economics($order);
        $fulfillment = SupplierFulfillment::where('order_id', $order->id)->sole();
        Http::fake(fn () => Http::response(['status' => 'delivered', 'quantity' => 1, 'deliveredKeys' => ['untrusted-fixture-key']]));
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $review = ManualFulfillmentReview::where('order_id', $order->id)->sole();
        $notice = $effects->recordAdminOrderReviewNotification($review);
        $this->assertNotNull($notice);
        $this->assertSame($notice->id, $effects->recordAdminOrderReviewNotification($review)->id);
        Queue::fake();
        $this->artisan('telegram:recover-outbound')->assertSuccessful();
        $this->assertNull($effects->forOrder($order->id, $effects::TYPE_SALES_PROOF));
        $this->assertNotNull(app(SalesProofEligibility::class)->rejectionReason($order->fresh()));
        Http::swap(new Factory);
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 124]]));
        app(AdminOrderNotificationDeliveryPipeline::class)->deliver($notice);
        $text = (string) Http::recorded()->sole()[0]['text'];
        $this->assertStringContainsString('Customer API', $text);
        $this->assertStringContainsString('diperlukan', $text);
        $this->assertStringContainsString('review', strtolower($text));
        $this->assertSafe($text, public: false);
        $this->assertSame($economic, $this->economics($order));
        $this->assertSame(1, $fulfillment->purchases()->count());
    }

    public function test_supplier_success_materializes_one_safe_proof_for_the_whole_mixed_order(): void
    {
        [$order] = $this->purchase(supplier: true);
        Http::fake(fn () => Http::response(['status' => 'delivered', 'orderId' => 'upstream-private-ref', 'quantity' => 1, 'total_cost' => '1', 'deliveredKeys' => ['supplier-password-private']]));
        $service = app(SupplierFulfillmentService::class);
        $fulfillment = SupplierFulfillment::where('order_id', $order->id)->sole();
        $service->process($fulfillment->id);
        $service->process($fulfillment->id);
        $this->assertSame('delivered', $fulfillment->fresh()->state);
        $this->assertNull(app(SalesProofEligibility::class)->rejectionReason($order->fresh()));
        $effects = app(TelegramOutboundEffectService::class);
        $proof = $effects->forOrder($order->id, $effects::TYPE_SALES_PROOF);
        $this->assertNotNull($proof);
        $this->assertSame(1, SalesProofPost::where('order_id', $order->id)->count());
        $image = app(PaidInvoiceRenderer::class)->render(app(PaidInvoiceOrderPresentationAdapter::class)->present($order->fresh()));
        $this->assertSame(2, $image->metadata['items'][0]['quantity']);
        $this->assertSafe(json_encode($image->metadata, JSON_THROW_ON_ERROR));
        $economic = $this->economics($order);
        Http::swap(new Factory);
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 125]]));
        app(SalesProofDeliveryPipeline::class)->deliver($proof);
        $this->assertSafe((string) collect(Http::recorded()->sole()[0]->data())->firstWhere('name', 'caption')['contents']);
        $this->assertSame($economic, $this->economics($order));
    }

    public function test_unknown_public_and_owner_sends_are_never_resent_by_recovery(): void
    {
        [$order] = $this->purchase();
        $economic = $this->economics($order);
        $effects = app(TelegramOutboundEffectService::class);
        $proof = $effects->forOrder($order->id, $effects::TYPE_SALES_PROOF);
        $owner = $effects->forOrder($order->id, $effects::TYPE_ADMIN_PAID);
        Http::fake(fn () => throw new ConnectionException('fixture timeout'));
        app(SalesProofDeliveryPipeline::class)->deliver($proof);
        app(AdminOrderNotificationDeliveryPipeline::class)->deliver($owner);
        $this->assertSame(TelegramOutboundEffectState::Unknown, $proof->fresh()->state);
        $this->assertSame(TelegramOutboundEffectState::Unknown, $owner->fresh()->state);
        $identities = fn () => TelegramOutboundEffect::whereIn('effect_type', [$effects::TYPE_ADMIN_PAID, $effects::TYPE_SALES_PROOF])->orderBy('id')->pluck('id')->all();
        $ids = $identities();
        $this->artisan('telegram:recover-outbound')->assertSuccessful();
        $this->artisan('telegram:recover-outbound')->assertSuccessful();
        $this->assertSame($ids, $identities());
        $this->assertSame(1, $proof->fresh()->attempts);
        $this->assertSame(1, $owner->fresh()->attempts);
        $this->assertSame($economic, $this->economics($order));
    }

    public function test_missing_targets_and_open_review_never_control_the_accepted_purchase(): void
    {
        config(['services.telegram.admin_telegram_chat_id' => null]);
        StoreChannelSetting::query()->update(['telegram_chat_id' => null]);
        [$order] = $this->purchase();
        $economic = $this->economics($order);
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $review = ManualFulfillmentReview::create(['order_id' => $order->id, 'payment_id' => $order->payments()->sole()->id, 'amount' => 5000, 'currency' => 'IDR', 'reason' => 'supplier_fulfillment', 'state' => 'pending']);
        $this->assertNotNull(app(SalesProofEligibility::class)->rejectionReason($order->fresh()));
        config(['services.telegram.admin_telegram_chat_id' => '999123']);
        $this->artisan('telegram:recover-outbound')->assertSuccessful();
        $this->assertSame(1, TelegramOutboundEffect::where('source_type', ManualFulfillmentReview::class)->where('source_id', $review->id)->count());
        $this->assertSame($economic, $this->economics($order));
        Http::assertNothingSent();
    }

    public function test_an_existing_proof_cannot_send_after_review_or_credential_retirement(): void
    {
        [$order] = $this->purchase();
        $proof = app(TelegramOutboundEffectService::class)->forOrder($order->id, TelegramOutboundEffectService::TYPE_SALES_PROOF);
        $economic = $this->economics($order);
        ManualFulfillmentReview::create(['order_id' => $order->id, 'payment_id' => $order->payments()->sole()->id, 'amount' => 5000, 'currency' => 'IDR', 'reason' => 'supplier_fulfillment', 'state' => 'pending']);
        app(SalesProofDeliveryPipeline::class)->deliver($proof);
        $this->assertSame(TelegramOutboundEffectState::Failed, $proof->fresh()->state);
        $this->assertSame('proof_not_eligible', $proof->fresh()->last_error_code);
        ManualFulfillmentReview::query()->update(['state' => 'fulfilled']);
        ProductStockItem::query()->whereNotNull('order_item_id')->update(['retired_at' => now()]);
        $this->assertNotNull(app(SalesProofEligibility::class)->rejectionReason($order->fresh()));
        $this->assertSame($economic, $this->economics($order));
        Http::assertNothingSent();
    }

    private function purchase(string $currency = 'IDR', bool $supplier = false): array
    {
        $customer = Customer::factory()->create(['telegram_id' => 765432109, 'username' => 'privatebuyer', 'email' => 'buyer-private@example.test']);
        $product = Product::factory()->create(['name' => 'Public plan', 'price_amount' => 5000, 'usdt_price_amount' => 2_000_000, 'stock' => 1, 'reserved_stock' => 0]);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'local-password-private']);
        if ($supplier) {
            $connection = Supplier::forceCreate(['name' => 'upstream-private-name', 'provider' => 'prodseller', 'api_key' => 'upstream-private-key', 'currency' => 'USDT', 'currency_confirmed' => true, 'exchange_rate_idr' => 1000, 'balance_micros' => 10_000_000, 'is_active' => true, 'synced_at' => now(), 'sync_expires_at' => now()->addMinutes(5)]);
            $sku = SupplierCatalogItem::create(['supplier_id' => $connection->id, 'external_id' => 'upstream-private-sku', 'name' => 'upstream-private-product', 'unit_price_micros' => 1_000_000, 'stock' => 10, 'orderable' => true, 'requires_input' => false, 'synced_at' => now(), 'price_tiers' => [], 'bulk_discounts' => []]);
            SupplierPricePolicy::create(['product_id' => $product->id, 'mode' => 'manual', 'minimum_profit_idr' => 500, 'rounding_idr' => 1]);
            ProductSupplierSource::create(['product_id' => $product->id, 'supplier_catalog_item_id' => $sku->id, 'is_active' => true, 'priority' => 0]);
            app(SupplierAvailability::class)->refreshProducts([$product->id]);
        }
        $quantity = $supplier ? 2 : 1;
        if ($currency === 'USDT') {
            app(CustomerUsdtBalanceLedger::class)->creditVerifiedTopUp($customer, new VerifiedUsdtFunding($customer->id, UsdtMicroAmount::fromUnits(5_000_000), 'fixture-funding', 'fixture-receipt', 'v1'), 'fixture-funding');
        } else {
            app(StoreBalanceLedger::class)->creditTopUp($customer, 20_000, 'fixture-funding', 'fixture-funding');
        }
        $credentials = app(CustomerApiCredentials::class);
        $principal = $credentials->authenticate('Bearer '.$credentials->generate($customer)->takeSecret());
        $http = Request::create('/api/v1/orders', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['product_id' => (string) $product->id, 'quantity' => $quantity, 'preference' => $currency === 'USDT' ? 'usd_only' : 'idr_only', 'expected_totals' => $currency === 'USDT' ? ['USD' => '2'] : ['IDR' => (string) (5000 * $quantity)]], JSON_THROW_ON_ERROR));
        $http->headers->set('Idempotency-Key', 'notification-fixture');
        $request = CustomerApiOrderRequest::fromRequest($http);
        $checkout = app(CustomerApiOrderCheckout::class);

        return [$checkout->place($principal, $request)->order, fn () => $checkout->place($principal, $request)->order];
    }

    private function economics(Order $order): array
    {
        return [Order::count(), $order->fresh()->payment_status, $order->payments()->sole()->amount,
            DB::table('store_balance_entries')->where('kind', 'purchase_debit')->count(),
            DB::table('customer_usdt_balance_entries')->where('kind', 'purchase_debit')->count(),
            DB::table('order_refunds')->count(),
            app(StoreBalanceLedger::class)->snapshot($order->customer)->balance(),
            app(CustomerUsdtBalanceLedger::class)->snapshot($order->customer)->balanceMicro()];
    }

    private function assertSafe(string $payload, bool $public = true): void
    {
        // Operator notices name the purchased Supplier on purpose (docs/runbook-supplier-api-reseller.md);
        // only credentials and Customer-visible identity must never appear.
        $secrets = ['local-password-private', 'supplier-password-private', $public ? 'upstream-private' : 'upstream-private-key', ...($public ? ['buyer-private@example.test', 'privatebuyer'] : [])];
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $payload);
        }
    }
}
