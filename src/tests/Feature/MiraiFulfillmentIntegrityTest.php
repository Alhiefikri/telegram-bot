<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InsufficientStock;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\ProductSupplierSource;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierFulfillment;
use App\Models\SupplierPricePolicy;
use App\Models\SupplierPurchase;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\Credentials\CredentialDeliveryEligibility;
use App\Services\OrderCheckoutService;
use App\Services\OrderLifecycle;
use App\Services\Suppliers\SupplierAvailability;
use App\Services\Suppliers\SupplierCatalogSync;
use App\Services\Suppliers\SupplierFulfillmentService;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NonTransactionalDatabaseTestCase;

final class MiraiFulfillmentIntegrityTest extends NonTransactionalDatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['cache.default' => 'array', 'cache.limiter' => 'array']);
        Cache::flush();
        \Illuminate\Support\Facades\RateLimiter::swap(new RateLimiter(Cache::store('array')));
        $this->resetHttp();
    }

    public static function localQuantities(): array
    {
        return ['external only' => [0], 'mixed local and external' => [1]];
    }

    #[DataProvider('localQuantities')]
    public function test_missing_charged_total_keeps_hold_and_exact_recovery_settles_and_delivers_once(int $local): void
    {
        [$product, $supplier, $sku, $cart] = $this->fixture(local: $local, quantity: $local + 2);
        $order = $this->checkout($cart);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        Http::fake(function ($request) {
            if ($request->method() === 'POST') {
                return Http::response($this->payload($request['external_order_id'], ['total_vnd' => null]));
            }
            $this->assertStringEndsWith('/orders/mirai-order', $request->url());

            return Http::response($this->payload(SupplierPurchase::sole()->reference));
        });
        $service = app(SupplierFulfillmentService::class);
        $fulfillment = SupplierFulfillment::where('order_id', $order->id)->sole();
        $service->process($fulfillment->id);
        $purchase = SupplierPurchase::sole();

        $this->assertSame('awaiting_delivery', $purchase->state);
        $this->assertSame('mirai-order', $purchase->supplier_order_id);
        $this->assertNull($purchase->actual_cost_micros);
        $this->assertNull($purchase->committed_at);
        $this->assertSame(12_600_000_000, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(100_000_000_000, $supplier->fresh()->balance_micros);
        $this->assertSame(2, $sku->fresh()->reserved_quantity);
        $this->assertSame($local, ProductStockItem::count());
        $this->assertSame(0, ProductStockItem::whereNotNull('supplier_purchase_id')->count());
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertFalse(app(CredentialDeliveryEligibility::class)->allows($order->fresh(), $order->customer));

        $service->recoverOrder($fulfillment->id, 'mirai-order');
        $service->process($fulfillment->id);
        $service->process($fulfillment->id);

        $this->assertSame(12_600_000_000, $purchase->fresh()->actual_cost_micros);
        $this->assertNotNull($purchase->fresh()->committed_at);
        $this->assertSame(87_400_000_000, $supplier->fresh()->balance_micros);
        $this->assertSame(0, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(0, $sku->fresh()->reserved_quantity);
        $this->assertSame(2, ProductStockItem::where('supplier_purchase_id', $purchase->id)->count());
        $this->assertSame($local + 2, $order->orderItems()->sole()->stockItems()->count());
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertTrue(app(CredentialDeliveryEligibility::class)->allows($order->fresh(), $order->customer));
        $this->assertSame(1, SupplierPurchase::count());
        Http::assertSentCount(2);
        $this->assertSame(1, Http::recorded(fn ($request) => $request->method() === 'POST')->count());
    }

    public static function mismatchedIdentities(): array
    {
        return [
            'wrong package' => [['package_code' => 'OTHER']],
            'wrong reference' => [['external_order_id' => 'other-reference']],
            'wrong package and alias' => [['package_code' => 'OTHER', 'redeem_code' => 'other-code']],
            'wrong reference and alias' => [['external_order_id' => 'other-reference', 'redeem_code' => 'other-code']],
        ];
    }

    #[DataProvider('mismatchedIdentities')]
    public function test_ordinary_post_finalizer_does_not_commit_uncorrelated_economics(array $overrides): void
    {
        [, $supplier, $sku, $cart] = $this->fixture(quantity: 2);
        $order = $this->checkout($cart);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        Http::fake(fn ($request) => Http::response($this->payload($request['external_order_id'], $overrides)));
        $fulfillment = SupplierFulfillment::where('order_id', $order->id)->sole();
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $purchase = SupplierPurchase::sole();

        $this->assertSame('review', $purchase->state);
        $this->assertNull($purchase->supplier_order_id);
        $this->assertNull($purchase->actual_cost_micros);
        $this->assertNull($purchase->observed_quantity);
        $this->assertNull($purchase->committed_at);
        $this->assertSame(12_600_000_000, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(100_000_000_000, $supplier->fresh()->balance_micros);
        $this->assertSame(2, $sku->fresh()->reserved_quantity);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        $this->assertSame(0, ProductStockItem::count());
        Http::assertSentCount(1);
    }

    public function test_wrong_exact_order_with_alias_mismatch_cannot_settle_a_known_purchase(): void
    {
        [, $supplier, $sku, $cart] = $this->fixture(quantity: 2);
        $order = $this->checkout($cart);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        Http::fake(function ($request) {
            return Http::response($this->payload(
                $request->method() === 'POST' ? $request['external_order_id'] : SupplierPurchase::sole()->reference,
                $request->method() === 'POST'
                    ? ['total_vnd' => null]
                    : ['order_id' => 'wrong-order', 'redeem_code' => 'other-code'],
            ));
        });
        $fulfillment = SupplierFulfillment::where('order_id', $order->id)->sole();
        $service = app(SupplierFulfillmentService::class);
        $service->process($fulfillment->id);
        $service->recoverOrder($fulfillment->id, 'mirai-order');
        $purchase = SupplierPurchase::sole();

        $this->assertSame('mirai-order', $purchase->supplier_order_id);
        $this->assertNull($purchase->actual_cost_micros);
        $this->assertNull($purchase->committed_at);
        $this->assertSame(12_600_000_000, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(2, $sku->fresh()->reserved_quantity);
        $this->assertSame(0, ProductStockItem::count());
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        Http::assertSentCount(2);
    }

    public static function rejectionCases(): array
    {
        return [
            'undocumented 402' => [402, 'insufficient wallet balance', false],
            'undocumented balance message' => [409, 'insufficient balance', false],
            'undocumented package message' => [409, 'package not found', false],
            'documented balance rejection' => [409, 'insufficient wallet balance', true],
            'documented package rejection' => [409, 'package is not available', true],
            'documented bad request' => [400, 'invalid package_code', true],
        ];
    }

    #[DataProvider('rejectionCases')]
    public function test_only_documented_rejection_releases_original_hold_and_allows_fallback(int $status, string $message, bool $safe): void
    {
        [$product, $supplier, $sku, $cart] = $this->fixture(quantity: 2);
        $alternative = $supplier->replicate();
        $alternative->api_key = 'alternative-mirai-key';
        $alternative->save();
        $secondSku = $sku->replicate();
        $secondSku->supplier_id = $alternative->id;
        $secondSku->save();
        ProductSupplierSource::create(['product_id' => $product->id, 'supplier_catalog_item_id' => $secondSku->id, 'is_active' => true, 'priority' => 1]);
        $order = $this->checkout($cart);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        Http::fake(function ($request) use ($status, $message) {
            $this->assertSame('POST', $request->method());
            if ($request->hasHeader('Authorization', 'Bearer fixture-mirai-key')) {
                return Http::response(['success' => false, 'message' => $message], $status);
            }
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer alternative-mirai-key'));

            return Http::response($this->payload($request['external_order_id']));
        });
        $fulfillment = SupplierFulfillment::where('order_id', $order->id)->sole();
        $service = app(SupplierFulfillmentService::class);
        $service->process($fulfillment->id);

        $this->assertSame($safe ? 0 : 12_600_000_000, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame($safe ? 0 : 2, $sku->fresh()->reserved_quantity);
        $this->assertSame($safe ? 12_600_000_000 : 0, $alternative->fresh()->reserved_balance_micros);
        $this->assertSame($safe ? 2 : 0, $secondSku->fresh()->reserved_quantity);
        $this->travel(1)->minutes();
        $service->recover();
        $service->process($fulfillment->id);
        $this->travelBack();

        Http::assertSentCount($safe ? 2 : 1);
        $this->assertSame($safe ? 2 : 1, SupplierPurchase::count());
        $this->assertSame(100_000_000_000, $supplier->fresh()->balance_micros);
        if ($safe) {
            $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
            $this->assertSame(87_400_000_000, $alternative->fresh()->balance_micros);
            $this->assertSame(2, ProductStockItem::count());
        } else {
            $purchase = SupplierPurchase::sole();
            $this->assertNull($purchase->committed_at);
            $this->assertNull($purchase->supplier_order_id);
            $this->assertSame($supplier->id, $fulfillment->fresh()->reserved_supplier_id);
            $this->assertSame(12_600_000_000, $supplier->fresh()->reserved_balance_micros);
            $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
            $this->assertSame(0, ProductStockItem::count());
        }
    }

    public static function quantityCases(): array
    {
        return [
            'minimum five external one' => [5, 10, 0, 1, false],
            'minimum five mixed external one' => [5, 10, 2, 3, false],
            'minimum five mixed external four' => [5, 10, 2, 6, false],
            'minimum five cannot represent even five' => [5, 10, 0, 5, false],
            'minimum five retains local-only checkout' => [5, 10, 2, 2, true],
            'valid minimum one' => [1, 3, 0, 1, true],
            'valid maximum' => [1, 3, 0, 3, true],
            'maximum exceeded' => [1, 3, 0, 4, false],
            'mixed valid maximum' => [1, 3, 2, 5, true],
            'mixed maximum exceeded' => [1, 3, 2, 6, false],
        ];
    }

    #[DataProvider('quantityCases')]
    public function test_synced_quantity_eligibility_is_enforced_at_checkout(int $minimum, int $maximum, int $local, int $quantity, bool $accepted): void
    {
        [$product, $supplier, $sku, $cart] = $this->fixture($local, $quantity, $minimum, $maximum);
        $this->assertSame($minimum === 1, $sku->orderable);
        $this->assertSame($minimum === 1 ? $maximum : 0, $product->supplier_available_stock);
        if (! $accepted) {
            try {
                $this->checkout($cart);
                $this->fail('Checkout accepted an unrepresentable supplier quantity.');
            } catch (InsufficientStock) {
                $this->assertSame(0, SupplierFulfillment::count());
                $this->assertSame(0, $supplier->fresh()->reserved_balance_micros);
                $this->assertSame(0, $sku->fresh()->reserved_quantity);
            }
        } else {
            $order = $this->checkout($cart);
            $external = max(0, $quantity - $local);
            $this->assertSame($external, $order->orderItems()->sole()->supplier_quantity);
            $this->assertSame($external * 6_300_000_000, $supplier->fresh()->reserved_balance_micros);
            $this->assertSame($external, $sku->fresh()->reserved_quantity);
        }
        Http::assertNothingSent();
    }

    public function test_minimum_increase_after_reservation_prevents_dispatch_without_releasing_hold(): void
    {
        [, $supplier, $sku, $cart] = $this->fixture(quantity: 2);
        $order = $this->checkout($cart);
        $this->syncCatalog($supplier, 5, 10);
        $this->resetHttp();
        app(OrderLifecycle::class)->applyManualCompletion($order);
        $fulfillment = SupplierFulfillment::where('order_id', $order->id)->sole();
        app(SupplierFulfillmentService::class)->process($fulfillment->id);

        $this->assertSame('source_unavailable', $fulfillment->fresh()->last_error);
        $this->assertSame(12_600_000_000, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(2, $sku->fresh()->reserved_quantity);
        $this->assertSame(0, SupplierPurchase::count());
        Http::assertNothingSent();
    }

    private function fixture(int $local = 0, int $quantity = 1, int $minimum = 1, int $maximum = 10): array
    {
        $product = Product::factory()->create(['stock' => $local, 'reserved_stock' => 0, 'price_amount' => 10000]);
        for ($index = 0; $index < $local; $index++) {
            ProductStockItem::create(['product_id' => $product->id, 'secret' => 'local-code-'.$index]);
        }
        $supplier = Supplier::forceCreate(['name' => 'Mirai synthetic', 'provider' => 'miraiapi', 'api_key' => 'fixture-mirai-key', 'currency' => 'VND', 'currency_confirmed' => true, 'exchange_rate_idr' => '0.650001', 'is_active' => true]);
        $this->syncCatalog($supplier, $minimum, $maximum);
        $sku = SupplierCatalogItem::where('supplier_id', $supplier->id)->sole();
        SupplierPricePolicy::create(['product_id' => $product->id, 'mode' => 'manual', 'minimum_profit_idr' => 500, 'rounding_idr' => 1]);
        ProductSupplierSource::create(['product_id' => $product->id, 'supplier_catalog_item_id' => $sku->id, 'is_active' => true, 'priority' => 0]);
        app(SupplierAvailability::class)->refreshProducts([$product->id]);
        $customer = Customer::factory()->create();
        $cart = Cart::factory()->create(['customer_id' => $customer->id, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => $quantity]);
        $this->resetHttp();

        return [$product->fresh(), $supplier->fresh(), $sku, $cart];
    }

    private function syncCatalog(Supplier $supplier, int $minimum, int $maximum): void
    {
        $this->resetHttp();
        Http::fake(function ($request) use ($minimum, $maximum) {
            $this->assertSame('GET', $request->method());
            if (str_ends_with($request->url(), '/balance')) {
                return Http::response(['success' => true, 'data' => ['balance_vnd' => 100000]]);
            }
            $this->assertStringEndsWith('/packages', $request->url());

            return Http::response(['success' => true, 'data' => [[
                'code' => 'GPT_10M', 'name' => 'Synthetic package', 'reseller_price_vnd' => 6300,
                'min_quantity' => $minimum, 'max_quantity' => $maximum, 'remaining_today' => 100, 'unlimited' => false,
            ]]]);
        });
        $this->assertTrue(app(SupplierCatalogSync::class)->sync($supplier->fresh()));
    }

    private function payload(string $reference, array $overrides = []): array
    {
        return ['success' => true, 'data' => array_replace([
            'order_id' => 'mirai-order', 'external_order_id' => $reference, 'package_code' => 'GPT_10M',
            'quantity' => 2, 'status' => 'fulfilled', 'total_vnd' => 12600,
            'redeem_codes' => ['mirai-code-a', 'mirai-code-b'], 'redeem_code' => 'mirai-code-a',
        ], $overrides)];
    }

    private function checkout(Cart $cart): Order
    {
        return app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::manualQris());
    }

    private function resetHttp(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }
}
