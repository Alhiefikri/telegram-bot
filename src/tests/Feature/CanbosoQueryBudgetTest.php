<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\ProductSupplierSource;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierPricePolicy;
use App\Models\User;
use App\Services\Admin\AdminSupplierDashboardData;
use App\Services\Suppliers\SupplierAvailability;
use App\Services\Suppliers\SupplierCatalogSync;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\NonTransactionalDatabaseTestCase as TestCase;

final class CanbosoQueryBudgetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Cache::flush();
        Http::preventStrayRequests();
        Http::fake([]);
        $this->travelTo(now()->startOfSecond());
    }

    public function test_customer_availability_listing_stays_bounded_and_never_reads_supplier_rows_or_http(): void
    {
        $supplier = $this->supplier();
        $expected = [];
        $local = [];
        $baseline = null;

        foreach ([1, 50, 200] as $size) {
            for ($index = count($expected) + 1; $index <= $size; $index++) {
                $localStock = $index % 3 === 2 ? 0 : 3;
                [$product] = $this->mapped($supplier, $index, [
                    'stock' => $localStock,
                    'reserved_stock' => $localStock === 0 ? 0 : 1,
                ], ['orderable' => $index % 3 !== 0]);
                $local[$product->id] = $localStock === 0 ? 0 : 2;
                $expected[$product->id] = $local[$product->id] + ($index % 3 !== 0 ? 7 : 0);
            }
            app(SupplierAvailability::class)->refreshProducts(array_keys($expected));

            [$listing, $queries] = $this->measure(fn (): array => $this->availabilityListing());

            $this->assertSame($expected, $listing['stock']);
            $this->assertSame(array_keys(array_filter($expected, fn (int $stock): bool => $stock > 0)), $listing['purchasable']);
            $this->assertLessThanOrEqual(4, count($queries), $this->sql($queries));
            $baseline ??= count($queries);
            $this->assertLessThanOrEqual($baseline + 1, count($queries), $this->sql($queries));
            $this->assertSame([], $this->forTable($queries, 'suppliers'));
            $this->assertSame([], $this->forTable($queries, 'supplier_catalog_items'));
            $this->assertSame([], $this->forTable($queries, 'product_supplier_sources'));
        }

        $this->travel(6)->minutes();
        [$expired, $queries] = $this->measure(fn (): array => $this->availabilityListing());
        $this->assertSame($local, $expired['stock']);
        $this->assertSame(array_keys(array_filter($local, fn (int $stock): bool => $stock > 0)), $expired['purchasable']);
        $this->assertLessThanOrEqual(4, count($queries), $this->sql($queries));
        Http::assertNothingSent();
    }

    public function test_admin_dashboard_mapped_product_reads_are_eager_loaded_at_page_and_dataset_boundaries(): void
    {
        $supplier = $this->supplier();
        $expected = [];
        $baseline = null;

        foreach ([1, 12, 200] as $size) {
            for ($index = count($expected) + 1; $index <= $size; $index++) {
                [$product, $item, $source] = $this->mapped($supplier, $index);
                $expected[$product->id] = [$product->name, 'manual', $source->id, $source->priority, true, $item->external_id, $item->name, $supplier->name];
            }

            [$data, $queries] = $this->measure(function (): array {
                $dashboard = app(AdminSupplierDashboardData::class)->forTab(['supplier_source' => 'mapped']);
                $facts = [];
                foreach ($dashboard['products'] as $product) {
                    $source = $product->supplierSources->sole();
                    $facts[$product->id] = [$product->name, $product->supplierPricePolicy->mode, $source->id, $source->priority, $source->is_active, $source->catalogItem->external_id, $source->catalogItem->name, $source->catalogItem->supplier->name];
                }

                return [
                    'total' => $dashboard['products']->total(),
                    'per_page' => $dashboard['products']->perPage(),
                    'facts' => $facts,
                    'sources' => $dashboard['productFilters']['sourceOptions']->pluck('name', 'id')->all(),
                    'connections' => $dashboard['connections']->getCollection()->all(),
                ];
            });

            $this->assertSame($size, $data['total']);
            $this->assertSame(12, $data['per_page']);
            $this->assertSame(array_slice($expected, 0, 12, true), $data['facts']);
            $this->assertSame([$supplier->id => $supplier->name], $data['sources']);
            $this->assertSame('canboso', $data['connections'][0]['provider']);
            $this->assertLessThanOrEqual(12, count($queries), $this->sql($queries));
            $baseline ??= count($queries);
            $this->assertLessThanOrEqual($baseline + 2, count($queries), $this->sql($queries));
        }
        Http::assertNothingSent();
    }

    public function test_private_catalog_rendering_eager_loads_mapping_facts_without_per_item_sql(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        $supplier = $this->supplier();
        $expected = [];
        $baseline = null;

        foreach ([1, 25, 200] as $size) {
            for ($index = count($expected) + 1; $index <= $size; $index++) {
                [$product, $item, $source] = $this->mapped($supplier, $index);
                $expected[$item->id] = [$item->external_id, $item->name, $item->stock, $item->reserved_quantity, $source->id, $product->id, $product->name];
            }

            [$data, $queries] = $this->measure(function () use ($supplier): array {
                $response = $this->get(route('admin.suppliers.catalog', $supplier));
                $response->assertOk();
                $items = $response->viewData('items');
                $facts = [];
                foreach ($items as $item) {
                    $source = $item->sources->sole();
                    $facts[$item->id] = [$item->external_id, $item->name, $item->stock, $item->reserved_quantity, $source->id, $source->product->id, $source->product->name];
                    $response->assertSee($item->name)->assertSee($source->product->name);
                }

                return ['response' => $response, 'total' => $items->total(), 'per_page' => $items->perPage(), 'facts' => $facts];
            });

            $this->assertSame($size, $data['total']);
            $this->assertSame(25, $data['per_page']);
            $this->assertSame(array_slice($expected, 0, 25, true), $data['facts']);
            $data['response']->assertSee('Canboso')->assertDontSee('synthetic-canboso-query-key');
            if ($size > 25) {
                $data['response']->assertDontSee($expected[array_key_last($expected)][1]);
            }
            $this->assertLessThanOrEqual(16, count($queries), $this->sql($queries));
            $baseline ??= count($queries);
            $this->assertLessThanOrEqual($baseline + 4, count($queries), $this->sql($queries));
        }
        Http::assertNothingSent();
    }

    public function test_catalog_sync_batches_writes_across_two_hundred_row_chunks_without_per_sku_reads(): void
    {
        $supplier = $this->supplier();
        [$product, $anchor, $source] = $this->mapped($supplier, 1);
        $obsolete = SupplierCatalogItem::query()->create([
            'supplier_id' => $supplier->id, 'external_id' => 'obsolete', 'name' => 'Retired synthetic SKU',
            'currency' => 'USD', 'unit_price_micros' => 1000000, 'stock' => 9,
            'orderable' => true, 'requires_input' => false,
        ]);
        $obsolete->forceFill(['reserved_quantity' => 3])->save();
        $baselineReads = null;
        $baselineTotal = null;

        foreach ([1, 50, 200, 401] as $size) {
            $this->snapshot($size);
            [$applied, $queries] = $this->measure(fn (): bool => app(SupplierCatalogSync::class)->sync($supplier));

            $this->assertTrue($applied);
            $reads = $this->selects($queries);
            $chunks = (int) ceil($size / 200);
            $baselineReads ??= count($reads);
            $baselineTotal ??= count($queries);
            $this->assertLessThanOrEqual(26, count($reads), $this->sql($queries));
            $this->assertLessThanOrEqual($baselineReads + 2, count($reads), $this->sql($queries));
            $this->assertLessThanOrEqual($baselineTotal + ($chunks - 1) + 3, count($queries), $this->sql($queries));
            $catalogWrites = array_values(array_filter($this->forTable($queries, 'supplier_catalog_items'), fn (string $sql): bool => preg_match('/^\s*(insert|update)\b/i', $sql) === 1));
            $this->assertLessThanOrEqual($chunks + 1, count($catalogWrites), implode("\n", $catalogWrites));
            $this->assertCatalog($supplier, $size);
            $this->assertSame(2, $anchor->fresh()->reserved_quantity);
            $this->assertSame(3, $obsolete->fresh()->reserved_quantity);
            $this->assertSame(0, $obsolete->fresh()->stock);
            $this->assertFalse($obsolete->fresh()->orderable);
            $this->assertSame($anchor->id, $source->fresh()->supplier_catalog_item_id);
            $fresh = $product->fresh();
            $this->assertSame(3, $fresh->stock);
            $this->assertSame(1, $fresh->reserved_stock);
            $this->assertSame(7, $fresh->supplierAvailableStock());
            $this->assertSame(9, $fresh->availableStock());
            $this->assertSame(1000000000, $supplier->fresh()->balance_micros);
            Http::assertSentCount(2);
            Http::assertSent(fn ($request): bool => $request->method() === 'GET' && parse_url($request->url(), PHP_URL_PATH) === '/api/v2/telegram-buyer/products');
            Http::assertNotSent(fn ($request): bool => $request->method() !== 'GET');
        }
    }

    public function test_sync_reads_remain_bounded_as_mapped_products_grow_while_allowing_projection_writes(): void
    {
        $supplier = $this->supplier();
        $products = [];
        $baselineReads = null;

        foreach ([1, 50] as $size) {
            for ($index = count($products) + 1; $index <= $size; $index++) {
                [$product] = $this->mapped($supplier, $index);
                $product->quantityPriceTiers()->createMany(array_map(fn (int $minimum): array => [
                    'minimum_quantity' => $minimum,
                    'price_amount' => 50000 - ($minimum * 1000),
                    'usdt_price_amount' => null,
                ], range(2, 6)));
                $products[] = $product;
            }
            $this->travel(1)->seconds();
            $this->snapshot($size);
            [$applied, $queries] = $this->measure(fn (): bool => app(SupplierCatalogSync::class)->sync($supplier));

            $this->assertTrue($applied);
            $reads = $this->selects($queries);
            $baselineReads ??= count($reads);
            $this->assertLessThanOrEqual(26, count($reads), $this->sql($queries));
            $this->assertLessThanOrEqual($baselineReads + 3, count($reads), $this->sql($queries));
            $this->assertLessThanOrEqual(2, count($this->forTable($reads, 'product_quantity_price_tiers')), $this->sql($queries));
            // Each changed Product projection is a legitimate write, not a catalog N+1.
            $this->assertLessThanOrEqual($size + 35, count($queries), $this->sql($queries));
            $this->assertLessThanOrEqual(2, count(array_filter($this->forTable($queries, 'supplier_catalog_items'), fn (string $sql): bool => preg_match('/^\s*(insert|update)\b/i', $sql) === 1)), $this->sql($queries));
            foreach ($products as $offset => $product) {
                $fresh = $product->fresh();
                $this->assertSame(7 + ($offset % 3), $fresh->supplierAvailableStock());
                $this->assertSame(9 + ($offset % 3), $fresh->availableStock());
                $this->assertSame(3, $fresh->stock);
                $this->assertSame(1, $fresh->reserved_stock);
                $this->assertSame(5, $fresh->quantityPriceTiers()->count());
            }
            Http::assertSentCount(2);
            Http::assertNotSent(fn ($request): bool => $request->method() !== 'GET');
        }
    }

    public function test_sync_keeps_invalid_six_tier_schedule_fail_closed_despite_orderable_supplier_stock(): void
    {
        $supplier = $this->supplier();
        [$product, $item] = $this->mapped($supplier, 1);
        $product->quantityPriceTiers()->createMany(array_map(fn (int $minimum): array => [
            'minimum_quantity' => $minimum,
            'price_amount' => 50000 - ($minimum * 1000),
            'usdt_price_amount' => null,
        ], range(2, 7)));
        $this->snapshot(1);

        [$applied, $queries] = $this->measure(fn (): bool => app(SupplierCatalogSync::class)->sync($supplier));

        $this->assertTrue($applied);
        $fresh = $product->fresh();
        $this->assertSame(6, $fresh->quantityPriceTiers()->count());
        $this->assertSame(0, $fresh->supplierAvailableStock());
        $this->assertSame(2, $fresh->availableStock());
        $this->assertSame(3, $fresh->stock);
        $this->assertSame(1, $fresh->reserved_stock);
        $this->assertTrue($item->fresh()->orderable);
        $this->assertSame(9, $item->fresh()->stock);
        $this->assertLessThanOrEqual(2, count($this->forTable($this->selects($queries), 'product_quantity_price_tiers')), $this->sql($queries));
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request): bool => $request->method() !== 'GET');
    }

    private function availabilityListing(): array
    {
        $stock = [];
        foreach (Product::query()->select(['id', 'stock', 'reserved_stock'])->withAvailability()->storefrontOrder()->get() as $product) {
            $stock[$product->id] = $product->availableStock();
        }
        ksort($stock);

        return ['stock' => $stock, 'purchasable' => Product::query()->availableForPurchase()->orderBy('id')->pluck('id')->all()];
    }

    private function supplier(): Supplier
    {
        return Supplier::forceCreate([
            'name' => 'Synthetic Canboso connection', 'provider' => 'canboso',
            'api_key' => 'synthetic-canboso-query-key', 'currency' => 'USD',
            'currency_confirmed' => true, 'exchange_rate_idr' => 16000, 'is_active' => true,
            'balance_micros' => 1000000000, 'balance_observed_at' => now(),
            'synced_at' => now(), 'sync_expires_at' => now()->addMinutes(4),
        ]);
    }

    private function mapped(Supplier $supplier, int $index, array $productAttributes = [], array $itemAttributes = []): array
    {
        $product = Product::factory()->create(array_replace([
            'name' => 'Store Canboso product '.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
            'stock' => 3, 'reserved_stock' => 1, 'price_amount' => 50000, 'is_active' => true,
        ], $productAttributes));
        $item = SupplierCatalogItem::query()->create(array_replace([
            'supplier_id' => $supplier->id, 'external_id' => 'canboso-sku-'.$index,
            'name' => 'Private Canboso SKU '.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
            'currency' => 'USD', 'unit_price_micros' => 1000000, 'stock' => 9,
            'orderable' => true, 'requires_input' => false, 'price_tiers' => [], 'bulk_discounts' => [],
            'synced_at' => now(),
        ], $itemAttributes));
        $item->forceFill(['reserved_quantity' => 2])->save();
        $source = ProductSupplierSource::query()->create([
            'product_id' => $product->id, 'supplier_catalog_item_id' => $item->id,
            'priority' => $index, 'is_active' => true,
        ]);
        SupplierPricePolicy::query()->create(['product_id' => $product->id, 'mode' => 'manual', 'rounding_idr' => 1]);

        return [$product, $item, $source];
    }

    private function snapshot(int $size): void
    {
        $rows = [];
        for ($index = 1; $index <= $size; $index++) {
            $rows[] = [
                'productId' => 'canboso-sku-'.$index,
                'name' => 'Synced Canboso SKU '.$index,
                'description' => 'Synthetic private catalog description '.$index,
                'productType' => 'account', 'purchaseRequirements' => [],
                'price' => ['currency' => 'USD', 'amount' => $index % 2 === 1 ? '1.00' : '2.00'],
                'availability' => ['available' => 9 + (($index - 1) % 3)],
            ];
        }
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'https://canboso.com/api/v2/telegram-buyer/balance*' => Http::response(['success' => true, 'walletCurrency' => 'USD', 'balance' => '1000.00']),
            'https://canboso.com/api/v2/telegram-buyer/products*' => Http::response(['success' => true, 'walletCurrency' => 'USD', 'products' => $rows]),
        ]);
    }

    private function assertCatalog(Supplier $supplier, int $size): void
    {
        $items = SupplierCatalogItem::query()->where('supplier_id', $supplier->id)->where('external_id', '!=', 'obsolete')->get()->keyBy('external_id');
        $this->assertCount($size, $items);
        for ($index = 1; $index <= $size; $index++) {
            $item = $items->get('canboso-sku-'.$index);
            $this->assertNotNull($item);
            $this->assertSame('Synced Canboso SKU '.$index, $item->name);
            $this->assertSame('Synthetic private catalog description '.$index, $item->description);
            $this->assertSame('USD', $item->currency);
            $this->assertSame($index % 2 === 1 ? 1000000 : 2000000, $item->unit_price_micros);
            $this->assertSame(9 + (($index - 1) % 3), $item->stock);
            $this->assertTrue($item->orderable);
            $this->assertFalse($item->requires_input);
            $this->assertTrue($item->synced_at->equalTo(now()));
        }
    }

    /** Collect only the operation's SQL; preserve existing listeners and query-log state. */
    private function measure(callable $operation): array
    {
        $connection = DB::connection();
        $original = $connection->getEventDispatcher();
        $this->assertNotNull($original);
        $scoped = clone $original;
        $queries = [];
        $scoped->listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries, $connection): void {
            if ($query->connection === $connection) {
                $queries[] = $query->sql;
            }
        });
        $connection->setEventDispatcher($scoped);
        try {
            $result = $operation();
        } finally {
            $connection->setEventDispatcher($original);
        }

        return [$result, $queries];
    }

    private function selects(array $queries): array
    {
        return array_values(array_filter($queries, fn (string $sql): bool => preg_match('/^\s*select\b/i', $sql) === 1));
    }

    private function forTable(array $queries, string $table): array
    {
        return array_values(array_filter($queries, fn (string $sql): bool => preg_match('/\b(from|join|into|update)\s+'.preg_quote($table, '/').'\b/i', str_replace(['"', '`'], '', $sql)) === 1));
    }

    private function sql(array $queries): string
    {
        return implode("\n", $queries);
    }
}
