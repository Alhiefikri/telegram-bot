<?php

namespace Tests\Feature;

use App\Enums\InventoryMovementType;
use App\Jobs\MaterializeProductWaitlist;
use App\Jobs\SendProductStockNotifications;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductSupplierSource;
use App\Models\ProductWaitlistEvent;
use App\Models\StockNotificationEvent;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierPricePolicy;
use App\Services\ProductWaitlistNotificationService;
use App\Services\Suppliers\SupplierAvailability;
use App\Services\Suppliers\SupplierCatalogSync;
use App\Services\Telegram\StockNotificationDeliveryPipeline;
use App\Services\Telegram\TelegramCatalogProjectionCache;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\NonTransactionalDatabaseTestCase as TestCase;

final class SupplierAvailabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Cache::flush();
        Http::preventStrayRequests();
        $this->travelTo(now()->startOfSecond());
    }

    public function test_sync_projects_only_uncommitted_external_capacity_without_mutating_local_stock(): void
    {
        [$product, $supplier, $item] = $this->mapped(stock: 2);
        $supplier->forceFill(['reserved_balance_micros' => 6500000])->save();
        $item->forceFill(['reserved_quantity' => 3])->save();
        $this->snapshot('10', 20);
        $this->assertTrue(app(SupplierCatalogSync::class)->sync($supplier));
        $fresh = $product->fresh();
        $this->assertSame(2, $fresh->localAvailableStock());
        $this->assertSame(5, $fresh->availableStock());
        $this->assertSame(6500000, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(3, $item->fresh()->reserved_quantity);
        $this->assertSame(0, InventoryMovement::query()->count());
        Http::assertSent(fn ($request): bool => $request->method() === 'GET');
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
    }

    public function test_product_transition_events_dedupe_repeated_sync_and_recover_lost_dispatch(): void
    {
        [$product, $supplier] = $this->mapped();
        $this->snapshot('10', 20);
        $this->assertTrue(app(SupplierCatalogSync::class)->sync($supplier));
        $this->assertTrue(app(SupplierCatalogSync::class)->sync($supplier));
        $movement = InventoryMovement::query()->sole();
        $this->assertSame(InventoryMovementType::Availability, $movement->type);
        $this->assertSame(0, $movement->stock_before);
        $this->assertSame(0, $movement->available_before);
        $this->assertSame(10, $movement->available_after);
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(1, StockNotificationEvent::query()->count());
        $this->assertSame(1, ProductWaitlistEvent::query()->count());
        Queue::fake();
        $this->assertSame(1, app(StockNotificationDeliveryPipeline::class)->reconcile());
        app(ProductWaitlistNotificationService::class)->reconcile();
        Queue::assertPushed(SendProductStockNotifications::class, 1);
        Queue::assertPushed(MaterializeProductWaitlist::class, 1);
    }

    public function test_failed_read_expiry_and_same_observation_recovery_do_not_create_false_restock(): void
    {
        [$product, $supplier] = $this->mapped();
        $this->snapshot('10', 20);
        app(SupplierCatalogSync::class)->sync($supplier);
        $this->travel(6)->minutes();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([], 503)]);
        $this->assertFalse(app(SupplierCatalogSync::class)->sync($supplier));
        $this->assertSame(0, $product->fresh()->availableStock());
        $this->assertSame(10, $product->fresh()->supplier_available_stock);
        app(SupplierAvailability::class)->refreshProducts([$product->id]);
        $this->assertSame(10, $product->fresh()->supplier_available_stock);
        $this->snapshot('10', 20);
        $this->assertTrue(app(SupplierCatalogSync::class)->sync($supplier));
        $this->assertSame(10, $product->fresh()->availableStock());
        $this->assertSame(1, InventoryMovement::query()->count());
    }

    public function test_authoritative_zero_balance_then_topup_produces_one_genuine_restock(): void
    {
        [$product, $supplier] = $this->mapped();
        $this->snapshot('0', 20);
        app(SupplierCatalogSync::class)->sync($supplier);
        $this->assertSame(0, $product->fresh()->availableStock());
        $this->snapshot('3', 20);
        app(SupplierCatalogSync::class)->sync($supplier);
        $this->assertSame(3, $product->fresh()->availableStock());
        $this->assertSame(1, ProductWaitlistEvent::query()->count());
        $this->snapshot('0', 20);
        app(SupplierCatalogSync::class)->sync($supplier);
        $this->assertSame(0, $product->fresh()->availableStock());
        $this->assertSame(1, InventoryMovement::query()->where('became_unavailable', true)->count());
        $this->snapshot('3', 20);
        app(SupplierCatalogSync::class)->sync($supplier);
        $this->assertSame(2, ProductWaitlistEvent::query()->count());
    }

    public function test_missing_sku_and_unsupported_activation_fail_closed_and_preserve_holds(): void
    {
        [$product, $supplier, $item] = $this->mapped();
        $item->forceFill(['reserved_quantity' => 2])->save();
        $this->snapshot('10', 20, activation: true);
        app(SupplierCatalogSync::class)->sync($supplier);
        $this->assertSame(0, $product->fresh()->availableStock());
        $this->snapshot('10', 20, missing: true);
        app(SupplierCatalogSync::class)->sync($supplier);
        $this->assertSame(0, $item->fresh()->stock);
        $this->assertFalse($item->fresh()->orderable);
        $this->assertSame(2, $item->fresh()->reserved_quantity);
        $this->assertSame(0, InventoryMovement::query()->count());
    }

    public function test_changed_supplier_generation_discards_inflight_read_without_overwriting_observations(): void
    {
        [$product, $supplier] = $this->mapped();
        $supplier->forceFill(['balance_micros' => 7000000])->save();
        Http::fake(function ($request) use ($supplier) {
            $this->assertSame(0, DB::connection()->transactionLevel());
            if (str_ends_with($request->url(), '/balance')) {
                Supplier::query()->whereKey($supplier->id)->increment('version');

                return Http::response(['balance' => '99']);
            }

            return Http::response(['products' => [$this->row(20)]]);
        });
        $this->assertFalse(app(SupplierCatalogSync::class)->sync($supplier));
        $this->assertSame(7000000, $supplier->fresh()->balance_micros);
        $this->assertSame(0, $product->fresh()->availableStock());
        $this->assertSame(0, InventoryMovement::query()->count());
    }

    public function test_currency_mismatch_requires_admin_correction_and_invalidates_fresh_projection(): void
    {
        [$product, $supplier] = $this->mapped();
        $this->snapshot('10', 20);
        app(SupplierCatalogSync::class)->sync($supplier);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*/balance' => Http::response(['balance' => '10', 'currency' => 'USD']),
            '*/products' => Http::response(['products' => [$this->row(20)]]),
        ]);
        $this->assertFalse(app(SupplierCatalogSync::class)->sync($supplier));
        $this->assertFalse($supplier->fresh()->currency_confirmed);
        $this->assertSame('currency_mismatch', $supplier->fresh()->last_error);
        $this->assertSame(0, $product->fresh()->availableStock());
    }

    public function test_disabled_primary_source_does_not_restock_when_equivalent_fallback_remains_buyable(): void
    {
        [$product, $supplier, $item] = $this->mapped();
        $this->snapshot('10', 20);
        app(SupplierCatalogSync::class)->sync($supplier);
        $fallback = SupplierCatalogItem::query()->create(['supplier_id' => $supplier->id, 'external_id' => 'fallback', 'name' => 'Private equivalent', 'currency' => 'USDT', 'unit_price_micros' => 1000000, 'stock' => 20, 'orderable' => true, 'requires_input' => false, 'synced_at' => now()]);
        ProductSupplierSource::query()->create(['product_id' => $product->id, 'supplier_catalog_item_id' => $fallback->id, 'priority' => 2, 'is_active' => true]);
        ProductSupplierSource::query()->where('supplier_catalog_item_id', $item->id)->update(['is_active' => false]);
        app(SupplierAvailability::class)->refreshProducts([$product->id]);
        $this->assertSame(10, $product->fresh()->availableStock());
        $this->assertSame(1, InventoryMovement::query()->count());
    }

    public function test_automatic_price_changes_retain_retail_tiers_and_fail_closed_when_schedule_would_break(): void
    {
        [$product, $supplier] = $this->mapped();
        $product->quantityPriceTiers()->create(['minimum_quantity' => 5, 'price_amount' => 18000]);
        SupplierPricePolicy::query()->where('product_id', $product->id)->update(['mode' => 'fixed', 'profit_idr' => 1000]);
        $this->snapshot('10', 20);
        app(SupplierCatalogSync::class)->sync($supplier);
        $this->assertSame(20000, $product->fresh()->price_amount);
        $this->assertSame(0, $product->fresh()->availableStock());
        $this->assertSame(18000, $product->quantityPriceTiers()->sole()->price_amount);
        SupplierPricePolicy::query()->where('product_id', $product->id)->update(['profit_idr' => 3000]);
        app(SupplierAvailability::class)->refreshProducts([$product->id]);
        $this->assertSame(19000, $product->fresh()->price_amount);
        $this->assertSame(10, $product->fresh()->availableStock());
        $this->assertSame(18000, $product->quantityPriceTiers()->sole()->price_amount);
    }

    public function test_profit_boundary_and_disabled_connection_never_authorize_external_stock(): void
    {
        [$product, $supplier] = $this->mapped();
        SupplierPricePolicy::query()->where('product_id', $product->id)->update(['minimum_profit_idr' => 4001]);
        $this->snapshot('10', 20);
        app(SupplierCatalogSync::class)->sync($supplier);
        $this->assertSame(0, $product->fresh()->availableStock());
        SupplierPricePolicy::query()->where('product_id', $product->id)->update(['minimum_profit_idr' => 4000]);
        app(SupplierAvailability::class)->refreshProducts([$product->id]);
        $this->assertSame(10, $product->fresh()->availableStock());
        $supplier->forceFill(['is_active' => false])->save();
        app(SupplierAvailability::class)->refreshProducts([$product->id]);
        $this->assertSame(0, $product->fresh()->availableStock());
        $this->assertFalse(Product::query()->availableForPurchase()->whereKey($product->id)->exists());
    }

    public function test_private_low_balance_warning_rearms_after_observed_recovery(): void
    {
        [$product, $supplier] = $this->mapped();
        $supplier->low_balance_threshold_micros = 2000000;
        $supplier->save();
        $this->snapshot('1', 20);
        app(SupplierCatalogSync::class)->sync($supplier);
        $first = $supplier->fresh()->low_balance_alerted_at;
        $this->assertNotNull($first);
        $this->travel(1)->seconds();
        app(SupplierCatalogSync::class)->sync($supplier);
        $this->assertTrue($first->equalTo($supplier->fresh()->low_balance_alerted_at));
        $this->snapshot('2', 20);
        app(SupplierCatalogSync::class)->sync($supplier);
        $this->assertNull($supplier->fresh()->low_balance_alerted_at);
        $this->snapshot('1', 20);
        app(SupplierCatalogSync::class)->sync($supplier);
        $this->assertTrue($supplier->fresh()->low_balance_alerted_at->greaterThan($first));
    }

    public function test_expiry_bounds_cached_catalog_and_sql_purchase_semantics_without_supplier_n_plus_one(): void
    {
        [$product, $supplier] = $this->mapped();
        $this->snapshot('10', 20);
        app(SupplierCatalogSync::class)->sync($supplier);
        Product::factory()->count(30)->create(['stock' => 0]);
        $cache = app(TelegramCatalogProjectionCache::class);
        $build = fn (): array => ['available' => Product::query()->findOrFail($product->id)->availableStock()];
        $this->assertSame(['available' => 10], $cache->remember('supplier-test', [], $build));
        $this->travel(5)->minutes();
        $this->assertSame(['available' => 0], $cache->remember('supplier-test', [], $build));
        $this->assertFalse(Product::query()->availableForPurchase()->whereKey($product->id)->exists());
        DB::enableQueryLog();
        DB::flushQueryLog();
        $products = Product::query()->select(['id', 'stock', 'reserved_stock'])->withAvailability()->storefrontOrder()->get();
        foreach ($products as $listed) {
            $this->assertSame(0, $listed->availableStock());
        }
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(2, count($queries));
        $this->assertSame([], array_values(array_filter($queries, fn (array $query): bool => str_contains($query['query'], 'supplier_catalog_items') || str_contains($query['query'], '"suppliers"'))));
    }

    public function test_concurrent_sync_does_not_replace_the_active_read_lease(): void
    {
        [$product, $supplier] = $this->mapped();
        $overlappingResult = null;
        Http::fake(function ($request) use ($supplier, &$overlappingResult) {
            if (str_ends_with($request->url(), '/balance')) {
                $overlappingResult = app(SupplierCatalogSync::class)->sync($supplier->fresh());

                return Http::response(['balance' => '10']);
            }

            return Http::response(['products' => [$this->row(20)]]);
        });

        $this->assertTrue(app(SupplierCatalogSync::class)->sync($supplier));
        $this->assertFalse($overlappingResult);
        $this->assertSame(10, $product->fresh()->availableStock());
        $this->assertNull($supplier->fresh()->sync_token);
    }

    public function test_expired_sync_lease_recovers_without_losing_existing_commitments(): void
    {
        [$product, $supplier, $item] = $this->mapped();
        $supplier->forceFill([
            'sync_token' => '00000000-0000-0000-0000-000000000001',
            'sync_lease_expires_at' => now()->subSecond(),
            'reserved_balance_micros' => 2000000,
        ])->save();
        $item->forceFill(['reserved_quantity' => 2])->save();
        $this->snapshot('10', 20);

        $this->assertTrue(app(SupplierCatalogSync::class)->sync($supplier));
        $this->assertSame(8, $product->fresh()->availableStock());
        $this->assertSame(2000000, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(2, $item->fresh()->reserved_quantity);
        $this->assertNull($supplier->fresh()->sync_lease_expires_at);
    }

    public function test_scheduler_skips_fresh_catalog_and_renews_before_capacity_expires(): void
    {
        [$product, $supplier] = $this->mapped();
        $this->snapshot('10', 20);
        app(SupplierCatalogSync::class)->sync($supplier);
        $originalExpiry = $supplier->fresh()->sync_expires_at;
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake();

        $this->artisan('suppliers:sync', ['--limit' => 1])->assertSuccessful();
        Http::assertNothingSent();
        $this->assertTrue($supplier->fresh()->sync_expires_at->equalTo($originalExpiry));

        $this->travel(4)->minutes();
        $this->snapshot('10', 20);
        $this->artisan('suppliers:sync', ['--limit' => 1])->assertSuccessful();
        $this->assertTrue($supplier->fresh()->sync_expires_at->greaterThan($originalExpiry));
        $this->assertSame(10, $product->fresh()->availableStock());
        $this->assertSame(1, InventoryMovement::query()->count());
    }

    public function test_failed_supplier_does_not_starve_an_unattempted_connection(): void
    {
        [, $failed] = $this->mapped();
        [, $healthy] = $this->mapped();
        $failed->api_key = 'fixture-failing-account';
        $failed->save();
        $healthy->api_key = 'fixture-healthy-account';
        $healthy->save();
        Http::fake(function ($request) {
            if ($request->hasHeader('X-API-Key', 'fixture-failing-account')) {
                return Http::response([], 503);
            }

            return Http::response(str_ends_with($request->url(), '/balance')
                ? ['balance' => '10']
                : ['products' => [$this->row(20)]]);
        });

        $this->artisan('suppliers:sync', ['--limit' => 1])->assertSuccessful();
        $this->assertNull($failed->fresh()->balance_micros);
        $this->artisan('suppliers:sync', ['--limit' => 1])->assertSuccessful();
        $this->assertSame(10000000, $healthy->fresh()->balance_micros);
        $this->assertNotNull($failed->fresh()->sync_attempted_at);
    }

    private function mapped(int $stock = 0): array
    {
        $product = Product::factory()->create(['stock' => $stock, 'reserved_stock' => 0, 'price_amount' => 20000]);
        $supplier = Supplier::query()->create(['name' => 'Private test connection', 'provider' => 'prodseller', 'api_key' => 'fixture-only-key', 'currency' => 'USDT', 'currency_confirmed' => true, 'exchange_rate_idr' => 16000, 'is_active' => true]);
        $item = SupplierCatalogItem::query()->create(['supplier_id' => $supplier->id, 'external_id' => 'sku-1', 'name' => 'Private supplier SKU', 'unit_price_micros' => 1000000, 'stock' => 20, 'orderable' => true, 'requires_input' => false]);
        ProductSupplierSource::query()->create(['product_id' => $product->id, 'supplier_catalog_item_id' => $item->id, 'priority' => 1, 'is_active' => true]);
        SupplierPricePolicy::query()->create(['product_id' => $product->id, 'mode' => 'manual', 'rounding_idr' => 1]);

        return [$product, $supplier, $item];
    }

    private function snapshot(string $balance, int $stock, bool $activation = false, bool $missing = false): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*/balance' => Http::response(['balance' => $balance]),
            '*/products' => Http::response(['products' => $missing ? [] : [$this->row($stock, $activation)]]),
        ]);
    }

    private function row(int $stock, bool $activation = false): array
    {
        return ['id' => 'sku-1', 'name' => 'Private supplier SKU', 'price' => '1', 'stock' => $stock, 'requiresEmailActivation' => $activation, 'delivery' => ['type' => 'instant']];
    }
}
