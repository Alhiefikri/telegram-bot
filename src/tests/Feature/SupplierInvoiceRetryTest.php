<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductSupplierSource;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierFulfillment;
use App\Models\SupplierPricePolicy;
use App\Models\SupplierPurchase;
use App\Models\User;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\OrderLifecycle;
use App\Services\Suppliers\SupplierAvailability;
use App\Services\Suppliers\SupplierFulfillmentService;
use App\Services\Suppliers\SupplierInvoiceRetry;
use App\Services\Suppliers\SupplierMoney;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\NonTransactionalDatabaseTestCase as TestCase;

class SupplierInvoiceRetryTest extends TestCase
{
    public function test_owner_dashboard_retry_synchronizes_and_queues_only_one_purchase(): void
    {
        [$fulfillment, $supplier, $sku] = $this->reviewedInvoice();
        $old = $this->attachRejectedPurchase($fulfillment, $supplier, $sku);
        $sku->update(['unit_price_micros' => 1_000_000]);
        $this->fakeSnapshot($sku);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $url = route('admin.suppliers.resolve-invoice', $fulfillment);
        $payload = ['action' => 'retry', 'purchase_id' => $old->id];
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->post($url, $payload)->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Pembelian dijadwalkan. Jangan menekan ulang; periksa hasil pembelian dan pengiriman.');
        $this->post($url, $payload)->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Pembelian sedang diproses. Tidak ada pembelian baru yang dijadwalkan.');
        $this->assertSame(2, SupplierPurchase::count());
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_dashboard_requires_owner_confirmation_for_the_exact_above_limit_quote(): void
    {
        [$fulfillment, , $sku] = $this->reviewedInvoice();
        $this->fakeSnapshot($sku);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $url = route('admin.suppliers.resolve-invoice', $fulfillment);
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->post($url, ['action' => 'retry', 'purchase_id' => 0])->assertSessionHasNoErrors();
        $quote = session('supplier_retry_preview');
        $this->assertSame(5000, $quote['cost_idr']);
        $this->post($url, ['action' => 'approve_price', 'purchase_id' => 0, 'approval_token' => $quote['token']])
            ->assertSessionHasErrors('recovery');
        $this->post($url, ['action' => 'approve_price', 'purchase_id' => 0, 'approval_token' => $quote['token'], 'confirmed' => 1])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Pembelian dijadwalkan. Jangan menekan ulang; periksa hasil pembelian dan pengiriman.');
        $this->assertSame(1, SupplierPurchase::count());
    }

    public function test_price_approval_is_one_use_and_revalidated_before_dispatch(): void
    {
        [$fulfillment, , $sku] = $this->reviewedInvoice();
        $retry = app(SupplierInvoiceRetry::class);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->fakeSnapshot($sku);
        $this->assertSame('price_approval_required', $retry->attempt($fulfillment->id, false, 5000)['status']);
        $this->assertSame(0, SupplierPurchase::count());

        $preview = $retry->preview($fulfillment->id, $owner);
        $this->assertSame('price_approval_required', $preview['status']);
        $sku->update(['unit_price_micros' => 6_000_000]);
        $this->assertSame('quote_changed', $retry->confirm($fulfillment->id, $owner, $preview['token'])['status']);
        $this->assertSame(0, SupplierPurchase::count());

        $preview = $retry->preview($fulfillment->id, $owner);
        $this->assertSame('queued', $retry->confirm($fulfillment->id, $owner, $preview['token'])['status']);
        $this->assertSame('already_processing', $retry->confirm($fulfillment->id, $owner, $preview['token'])['status']);
        $sku->update(['unit_price_micros' => 7_000_000]);
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $this->assertSame('review', $fulfillment->fresh()->state);
        $this->assertSame('rejected', SupplierPurchase::sole()->state);
        $this->assertNull(SupplierPurchase::sole()->dispatched_at);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_proven_rejected_hold_is_released_even_when_fresh_stock_is_zero(): void
    {
        [$fulfillment, $supplier, $sku] = $this->reviewedInvoice();
        $this->attachRejectedPurchase($fulfillment, $supplier, $sku);
        $sku->update(['stock' => 0]);
        $this->fakeSnapshot($sku);
        $this->assertSame('stock_unavailable', app(SupplierInvoiceRetry::class)->attempt($fulfillment->id)['status']);
        $this->assertSame(0, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(0, $sku->fresh()->reserved_quantity);
        $this->assertSame('review', $fulfillment->fresh()->state);
    }

    public function test_rejected_purchase_retries_only_once_and_uses_a_new_purchase(): void
    {
        [$fulfillment, $supplier, $sku] = $this->reviewedInvoice();
        $old = $this->attachRejectedPurchase($fulfillment, $supplier, $sku);
        $sku->update(['unit_price_micros' => 1_000_000]);
        $this->fakeSnapshot($sku);
        $retry = app(SupplierInvoiceRetry::class);
        $this->assertSame('queued', $retry->attempt($fulfillment->id)['status']);
        $this->assertSame('already_processing', $retry->attempt($fulfillment->id)['status']);
        $this->assertSame(2, SupplierPurchase::count());
        $this->assertNotSame($old->id, $fulfillment->fresh()->current_purchase_id);

        Http::swap(new Factory);
        Http::fake(fn () => Http::response(['status' => 'delivered', 'orderId' => 'retry-order', 'quantity' => 1, 'total_cost' => '1', 'deliveredKeys' => ['retry-account']]));
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $this->assertSame('delivered', $fulfillment->fresh()->state);
        $this->assertSame(2, SupplierPurchase::count());
        Http::assertSentCount(1);
    }

    public function test_rejected_purchase_with_contradictory_debit_evidence_never_releases_or_retries(): void
    {
        [$fulfillment, $supplier, $sku] = $this->reviewedInvoice();
        $purchase = $this->attachRejectedPurchase($fulfillment, $supplier, $sku);
        $purchase->forceFill(['actual_cost_micros' => 1_000_000])->save();
        $retry = app(SupplierInvoiceRetry::class);

        $this->assertSame('unsafe', $retry->attempt($fulfillment->id)['status']);
        $this->assertSame('unsafe', $retry->releaseProvenRejected($fulfillment->id)['status']);
        $this->assertSame(1_000_000, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(1, SupplierPurchase::count());
        Http::assertNothingSent();
    }

    public function test_stale_dashboard_takeover_keeps_ambiguous_supplier_hold(): void
    {
        [$fulfillment, $supplier, $sku] = $this->reviewedInvoice(ambiguous: true);
        $purchase = SupplierPurchase::sole();
        Http::swap(new Factory);
        Http::fake(fn () => Http::response([], 503));
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.suppliers.resolve-invoice', $fulfillment), [
                'action' => 'take_over', 'purchase_id' => $purchase->id, 'confirmed' => 1,
            ])->assertSessionHasNoErrors();
        $this->assertNotNull($fulfillment->fresh()->manual_takeover_at);
        $this->assertSame(1_000_000, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(1, $sku->fresh()->reserved_quantity);
        $this->assertSame('ambiguous', $purchase->fresh()->state);
        $this->assertSame('manual_takeover', app(SupplierInvoiceRetry::class)->attempt($fulfillment->id)['status']);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_ambiguous_purchase_never_retries_or_releases_its_hold(): void
    {
        [$fulfillment, $supplier, $sku] = $this->reviewedInvoice(ambiguous: true);
        $purchase = SupplierPurchase::sole();
        $this->assertSame('ambiguous', $purchase->state);
        $this->assertSame('unsafe', app(SupplierInvoiceRetry::class)->attempt($fulfillment->id)['status']);
        $this->assertSame('unsafe', app(SupplierInvoiceRetry::class)->releaseProvenRejected($fulfillment->id)['status']);
        $this->assertSame(1_000_000, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(1, $sku->fresh()->reserved_quantity);
        $this->assertSame($purchase->id, $fulfillment->fresh()->current_purchase_id);
        $this->assertSame(1, SupplierPurchase::count());
        Http::assertSentCount(1);
    }

    public function test_approved_exact_quote_can_complete_without_changing_the_original_ceiling(): void
    {
        [$fulfillment] = $this->reviewedInvoice();
        $originalCeiling = $fulfillment->maximum_cost_idr;
        $retry = app(SupplierInvoiceRetry::class);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $preview = $retry->preview($fulfillment->id, $owner);
        $this->assertSame('price_approval_required', $preview['status']);
        $this->assertSame('queued', $retry->confirm($fulfillment->id, $owner, $preview['token'])['status']);
        Http::swap(new Factory);
        Http::fake(fn () => Http::response(['status' => 'delivered', 'orderId' => 'approved-order', 'quantity' => 1, 'total_cost' => '5', 'deliveredKeys' => ['approved-account']]));
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $this->assertSame('delivered', $fulfillment->fresh()->state);
        $this->assertSame($originalCeiling, $fulfillment->fresh()->maximum_cost_idr);
        Http::assertSentCount(1);
    }

    public function test_supplier_debit_above_the_approved_quote_is_not_delivered(): void
    {
        [$fulfillment] = $this->reviewedInvoice();
        $retry = app(SupplierInvoiceRetry::class);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $preview = $retry->preview($fulfillment->id, $owner);
        $this->assertSame('queued', $retry->confirm($fulfillment->id, $owner, $preview['token'])['status']);
        Http::swap(new Factory);
        Http::fake(fn () => Http::response(['status' => 'delivered', 'orderId' => 'over-quote-order', 'quantity' => 1, 'total_cost' => '6', 'deliveredKeys' => ['over-quote-account']]));
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $this->assertSame('review', $fulfillment->fresh()->state);
        $this->assertSame('cost_mismatch', $fulfillment->fresh()->last_error);
        $this->assertSame(0, $fulfillment->orderItem->stockItems()->count());
        Http::assertSentCount(1);
    }

    private function fakeSnapshot(SupplierCatalogItem $sku): void
    {
        Http::swap(new Factory);
        Http::fake(function ($request) use ($sku) {
            $current = $sku->fresh();

            return Http::response(str_ends_with($request->url(), '/balance')
                ? ['balance' => '10', 'currency' => 'USDT']
                : ['currency' => 'USDT', 'products' => [[
                    'id' => $current->external_id, 'name' => 'Retry SKU',
                    'price' => SupplierMoney::decimal($current->unit_price_micros), 'stock' => $current->stock,
                ]]]);
        });
    }

    private function attachRejectedPurchase(SupplierFulfillment $fulfillment, Supplier $supplier, SupplierCatalogItem $sku): SupplierPurchase
    {
        $purchase = SupplierPurchase::create([
            'supplier_fulfillment_id' => $fulfillment->id, 'supplier_id' => $supplier->id,
            'supplier_catalog_item_id' => $sku->id, 'reference' => (string) Str::uuid(),
            'quantity' => 1, 'expected_cost_micros' => 1_000_000, 'exchange_rate_idr' => 1000,
            'state' => 'rejected', 'dispatched_at' => now(),
        ]);
        $fulfillment->update(['current_purchase_id' => $purchase->id]);

        return $purchase;
    }

    private function reviewedInvoice(bool $ambiguous = false): array
    {
        Queue::fake();
        Http::fake();
        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0, 'price_amount' => 5000]);
        $supplier = Supplier::forceCreate([
            'name' => 'Retry supplier', 'provider' => $ambiguous ? 'warzone' : 'prodseller', 'api_key' => 'test-secret',
            'currency' => $ambiguous ? 'USD' : 'USDT',
            'currency_confirmed' => true, 'exchange_rate_idr' => 1000, 'balance_micros' => 10_000_000,
            'is_active' => true, 'synced_at' => now(), 'sync_expires_at' => now()->addMinutes(5),
        ]);
        $sku = SupplierCatalogItem::create([
            'supplier_id' => $supplier->id, 'external_id' => 'retry-sku', 'name' => 'Retry SKU',
            'unit_price_micros' => 1_000_000, 'stock' => 10, 'orderable' => true, 'requires_input' => false,
            'synced_at' => now(), 'price_tiers' => [], 'bulk_discounts' => [],
        ]);
        SupplierPricePolicy::create(['product_id' => $product->id, 'mode' => 'manual', 'minimum_profit_idr' => 500, 'rounding_idr' => 1]);
        ProductSupplierSource::create(['product_id' => $product->id, 'supplier_catalog_item_id' => $sku->id, 'is_active' => true, 'priority' => 0]);
        app(SupplierAvailability::class)->refreshProducts([$product->id]);
        $customer = Customer::factory()->create(['telegram_id' => 790001]);
        $cart = Cart::factory()->create(['customer_id' => $customer->id, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1]);
        $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::manualQris());
        app(OrderLifecycle::class)->applyManualCompletion($order);
        if ($ambiguous) {
            Http::fake(fn () => Http::response([], 500));
        } else {
            $sku->update(['unit_price_micros' => 5_000_000]);
        }
        $fulfillment = SupplierFulfillment::where('order_id', $order->id)->sole();
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $this->assertSame('review', $fulfillment->fresh()->state);

        return [$fulfillment, $supplier, $sku];
    }
}
