<?php

namespace Tests\Feature;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductSupplierSource;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierCurrencyRate;
use App\Models\SupplierFulfillment;
use App\Models\SupplierPricePolicy;
use App\Models\SupplierPurchase;
use App\Services\Suppliers\SupplierCurrencyRateManager;
use App\Services\Suppliers\SupplierPricing;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RunsPostgresWorkers;
use Tests\Support\NonTransactionalDatabaseTestCase;

final class SupplierCurrencyRateMigrationTest extends NonTransactionalDatabaseTestCase
{
    use RunsPostgresWorkers;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->travelTo(now()->startOfSecond());
    }

    public function test_baseline_backfill_reconciles_retail_and_capacity_before_returning(): void
    {
        $create = require database_path('migrations/2026_10_23_000003_create_supplier_currency_rates.php');
        $create->down();
        [$automatic, $manual, $supplier, $item] = $this->mappedProducts();
        $this->supplier('USD', '20000');
        $history = $this->historicalSnapshots($automatic, $supplier, $item);

        $create->up();

        $this->assertReconciled($automatic, $manual, $supplier, $item);
        $this->assertSnapshotsUnchanged($history);
        $this->assertBaselineRateWriteFenced($supplier, '18000');
    }

    public function test_dollar_merge_reconciles_automatic_retail_and_manual_capacity_without_repricing_history(): void
    {
        SupplierCurrencyRate::query()->create(['currency' => 'USD', 'exchange_rate_idr' => '16000', 'version' => 1]);
        SupplierCurrencyRate::query()->create(['currency' => 'USDT', 'exchange_rate_idr' => '20000', 'version' => 1]);
        [$automatic, $manual, $supplier, $item] = $this->mappedProducts();
        $usdt = $this->supplier('USDT', '20000');
        $history = $this->historicalSnapshots($automatic, $supplier, $item);

        $migration = require database_path('migrations/2026_10_24_000001_merge_usd_usdt_supplier_currency_rates.php');
        $migration->up();

        $this->assertReconciled($automatic, $manual, $supplier, $item);
        $this->assertNull(SupplierCurrencyRate::query()->find('USDT'));
        $this->assertSame('20000.000000', $usdt->fresh()->exchange_rate_idr);
        $this->assertSnapshotsUnchanged($history);
        $this->assertBaselineRateWriteFenced($supplier, '18000');
        $this->assertBaselineRateWriteFenced($usdt, '18000');
    }

    public function test_existing_unfenced_install_is_repaired_and_post_migration_baseline_writer_cannot_diverge(): void
    {
        SupplierCurrencyRate::query()->create(['currency' => 'USD', 'exchange_rate_idr' => '20000', 'version' => 1]);
        [$automatic, $manual, $supplier, $item] = $this->mappedProducts();
        $history = $this->historicalSnapshots($automatic, $supplier, $item);
        $this->cutover();

        $this->assertReconciled($automatic, $manual, $supplier, $item);
        $this->assertSnapshotsUnchanged($history);
        // The baseline request reads the new version after expand migration commits.
        $this->assertBaselineRateWriteFenced($supplier, '18000');
        $this->assertSame(1, DB::table('supplier_currency_rates')->where('currency', 'USD')->value('version'));

        // The current writer updates the authority first, then caches, in its transaction.
        $this->assertSame(1, app(SupplierCurrencyRateManager::class)->save([
            'USD' => ['exchange_rate_idr' => '21000', 'version' => 1],
        ], null, null));
        $this->assertSame('21000.000000', $supplier->fresh()->exchange_rate_idr);
        $this->assertSame(26250, $automatic->fresh()->price_amount);
        $this->assertSame(0, $manual->fresh()->supplier_available_stock);
        $this->assertSnapshotsUnchanged($history);
    }

    public function test_fence_rejects_legacy_inserts_missing_authority_and_currency_changes(): void
    {
        SupplierCurrencyRate::query()->create(['currency' => 'USD', 'exchange_rate_idr' => '20000', 'version' => 1]);
        $supplier = $this->supplier('USD', '20000');
        $this->cutover();

        foreach ([['USD', '18000'], ['VND', '0.7']] as [$currency, $rate]) {
            try {
                $this->supplier($currency, $rate);
                $this->fail('A baseline insert must not bypass the global rate source.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('Supplier rate cache must match', $exception->getMessage());
            }
        }
        try {
            DB::table('suppliers')->where('id', $supplier->id)->update(['currency' => 'VND']);
            $this->fail('Changing wallet currency must not bypass the global rate source.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Supplier rate cache must match', $exception->getMessage());
        }
        $this->assertSame(1, Supplier::query()->count());
        $this->assertSame('USD', $supplier->fresh()->currency);
        $supplier->forceFill(['name' => 'Harmless baseline metadata change'])->save();
        $this->assertSame('Harmless baseline metadata change', $supplier->fresh()->name);
    }

    public function test_reconciliation_is_repeatable_without_rewriting_versions_or_history(): void
    {
        SupplierCurrencyRate::query()->create(['currency' => 'USD', 'exchange_rate_idr' => '20000', 'version' => 1]);
        [$automatic, $manual, $supplier, $item] = $this->mappedProducts();
        $history = $this->historicalSnapshots($automatic, $supplier, $item);
        $this->cutover();
        $version = $supplier->fresh()->version;
        $this->cutover();

        $this->assertSame($version, $supplier->fresh()->version);
        $this->assertReconciled($automatic, $manual, $supplier, $item);
        $this->assertSnapshotsUnchanged($history);
        $this->assertBaselineRateWriteFenced($supplier, '18000');
    }

    public function test_stale_catalog_is_invalidated_until_a_fresh_observation_can_reprice_it(): void
    {
        SupplierCurrencyRate::query()->create(['currency' => 'USD', 'exchange_rate_idr' => '20000', 'version' => 1]);
        [$automatic, $manual, $supplier, $item] = $this->mappedProducts();
        $supplier->forceFill(['sync_expires_at' => now()->subSecond()])->save();
        $history = $this->historicalSnapshots($automatic, $supplier, $item);

        $this->cutover();

        $this->assertSame('20000.000000', $supplier->fresh()->exchange_rate_idr);
        $this->assertNull($automatic->fresh()->supplier_available_until);
        $this->assertFalse($automatic->fresh()->supplier_available_until?->isFuture() ?? false);
        $this->assertFalse(app(SupplierPricing::class)->isEligible(
            $automatic->fresh(), $supplier->fresh(), $item->fresh(), $automatic->supplierPricePolicy, 1, 20000,
        ), 'The stale automatic retail cannot authorize an external purchase.');
        $this->assertSnapshotsUnchanged($history);

        $supplier->forceFill(['sync_expires_at' => now()->addMinutes(5)])->save();
        $this->cutover();
        $this->assertReconciled($automatic, $manual, $supplier, $item);
        $this->assertSnapshotsUnchanged($history);
    }

    #[Group('concurrency')]
    #[Group('postgres-release')]
    public function test_waiting_baseline_update_rechecks_authority_after_global_writer_commits(): void
    {
        $this->requirePostgresConcurrency('Supplier currency cutover');
        SupplierCurrencyRate::query()->create(['currency' => 'USD', 'exchange_rate_idr' => '16000', 'version' => 1]);
        $supplier = $this->supplier('USD', '16000');
        $this->cutover();
        $directory = $this->createWorkspace('supplier-cutover-writer');

        try {
            $results = $this->runConcurrentWorkers(2, function (int $index) use ($supplier, $directory): string {
                DB::statement("SET lock_timeout TO '10s'");
                DB::statement("SET statement_timeout TO '15s'");
                if ($index === 0) {
                    DB::transaction(function () use ($directory): void {
                        app(SupplierCurrencyRateManager::class)->save([
                            'USD' => ['exchange_rate_idr' => '20000', 'version' => 1],
                        ], null, null);
                        touch($directory.'/global-supplier-locked');
                        $this->waitForMarker($directory.'/baseline-pid');
                        $pid = (int) file_get_contents($directory.'/baseline-pid');
                        $deadline = microtime(true) + 8;
                        do {
                            $blocked = (bool) DB::selectOne(
                                'SELECT pg_backend_pid() = ANY(pg_blocking_pids(?)) AS blocked', [$pid],
                            )->blocked;
                            if (! $blocked) {
                                usleep(10_000);
                            }
                        } while (! $blocked && microtime(true) < $deadline);
                        $this->assertTrue($blocked, 'The baseline UPDATE must wait on the global writer Supplier lock.');
                    });

                    return 'global-committed';
                }

                $this->waitForMarker($directory.'/global-supplier-locked');
                // This read occurs after expand migration, but before the global
                // transaction commits. The UPDATE's statement snapshot is older.
                $baseline = DB::table('suppliers')->where('id', $supplier->id)->first();
                $this->assertSame(1, (int) $baseline->version);
                file_put_contents($directory.'/baseline-pid', (string) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
                try {
                    DB::table('suppliers')->where('id', $supplier->id)->update([
                        'exchange_rate_idr' => $baseline->exchange_rate_idr,
                        'version' => DB::raw('version + 1'),
                    ]);
                    $this->fail('A blocked baseline UPDATE must not restore the old cached rate.');
                } catch (QueryException $exception) {
                    $this->assertSame('23514', $exception->errorInfo[0]);
                    $this->assertStringContainsString('Supplier rate cache must match', $exception->getMessage());
                }

                return 'baseline-fenced';
            }, 'supplier-cutover-snapshot-workers');
        } finally {
            $this->cleanupWorkspace($directory);
        }

        $this->assertWorkersSucceeded($results);
        $this->assertSame(['global-committed', 'baseline-fenced'], array_column($results, 'value'));
        $this->assertSame('20000.000000', $supplier->fresh()->exchange_rate_idr);
        $this->assertSame(2, $supplier->fresh()->version);
    }

    private function cutover(): void
    {
        $migration = require database_path('migrations/2026_10_24_000002_fence_supplier_currency_rate_caches.php');
        $migration->up();
    }

    private function supplier(string $currency, string $rate): Supplier
    {
        return Supplier::forceCreate([
            'name' => 'Synthetic upgrade connection', 'provider' => 'csxstore', 'api_key' => 'synthetic-key',
            'currency' => $currency, 'currency_confirmed' => true, 'exchange_rate_idr' => $rate,
            'is_active' => true, 'version' => 1, 'balance_micros' => 10_000_000,
            'reserved_balance_micros' => 1_000_000, 'synced_at' => now(), 'sync_expires_at' => now()->addMinutes(5),
        ]);
    }

    private function mappedProducts(): array
    {
        $supplier = $this->supplier('USD', '16000');
        $item = SupplierCatalogItem::forceCreate([
            'supplier_id' => $supplier->id, 'external_id' => 'upgrade-sku', 'name' => 'Synthetic upgrade SKU',
            'currency' => 'USD', 'unit_price_micros' => 1_000_000, 'stock' => 5, 'reserved_quantity' => 1,
            'orderable' => true, 'requires_input' => false, 'synced_at' => now(), 'price_tiers' => [], 'bulk_discounts' => [],
        ]);
        $products = [];
        foreach (['percent' => 20000, 'manual' => 19000] as $mode => $price) {
            $product = Product::factory()->create([
                'price_amount' => $price, 'stock' => 0, 'supplier_available_stock' => 4,
                'supplier_available_until' => now()->addMinutes(5),
            ]);
            SupplierPricePolicy::query()->create([
                'product_id' => $product->id, 'mode' => $mode, 'markup_basis_points' => 2500,
                'minimum_profit_idr' => 0, 'rounding_idr' => 1,
            ]);
            ProductSupplierSource::forceCreate([
                'product_id' => $product->id, 'supplier_catalog_item_id' => $item->id,
                'priority' => 1, 'is_active' => true, 'version' => 1,
            ]);
            $products[] = $product;
        }

        return [$products[0], $products[1], $supplier, $item];
    }

    private function assertReconciled(Product $automatic, Product $manual, Supplier $supplier, SupplierCatalogItem $item): void
    {
        $this->assertSame('20000.000000', $supplier->fresh()->exchange_rate_idr);
        $this->assertSame(25000, $automatic->fresh()->price_amount);
        $this->assertSame(4, $automatic->fresh()->supplier_available_stock);
        $this->assertNotNull($automatic->fresh()->supplier_available_until);
        $this->assertSame(19000, $manual->fresh()->price_amount);
        $this->assertSame(0, $manual->fresh()->supplier_available_stock);
        $this->assertNull($manual->fresh()->supplier_available_until);
        $this->assertSame(1_000_000, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(1, $item->fresh()->reserved_quantity);
    }

    private function assertBaselineRateWriteFenced(Supplier $supplier, string $rate): void
    {
        $version = $supplier->fresh()->version;
        $before = (array) DB::table('suppliers')->where('id', $supplier->id)->first();
        try {
            DB::table('suppliers')->where('id', $supplier->id)->where('version', $version)->update([
                'exchange_rate_idr' => $rate, 'version' => $version + 1,
            ]);
            $this->fail('The old image must remain fenced after migration commits.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Supplier rate cache must match', $exception->getMessage());
        }
        $this->assertSame($before, (array) DB::table('suppliers')->where('id', $supplier->id)->first());
    }

    private function historicalSnapshots(Product $product, Supplier $supplier, SupplierCatalogItem $item): array
    {
        $orderItem = OrderItem::factory()->create([
            'product_id' => $product->id, 'quantity' => 1, 'local_quantity' => 0, 'supplier_quantity' => 1,
            'unit_price' => 20000, 'subtotal' => 20000,
        ]);
        $fulfillment = SupplierFulfillment::query()->create([
            'order_id' => $orderItem->order_id, 'order_item_id' => $orderItem->id, 'quantity' => 1,
            'state' => 'reserved', 'maximum_cost_idr' => 20000,
            'sources_snapshot' => [['supplier_id' => $supplier->id, 'exchange_rate_idr' => '16000.000000']],
            'reserved_supplier_id' => $supplier->id, 'reserved_catalog_item_id' => $item->id,
            'reserved_cost_micros' => 1_000_000, 'reserved_quantity' => 1,
        ]);
        SupplierPurchase::query()->create([
            'supplier_fulfillment_id' => $fulfillment->id, 'supplier_id' => $supplier->id,
            'supplier_catalog_item_id' => $item->id, 'reference' => (string) Str::uuid(), 'quantity' => 1,
            'expected_cost_micros' => 1_000_000, 'actual_cost_micros' => null,
            'exchange_rate_idr' => '16000.000000', 'state' => 'reserved',
        ]);

        return collect(['order_items', 'supplier_fulfillments', 'supplier_purchases'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all()])->all();
    }

    private function assertSnapshotsUnchanged(array $snapshots): void
    {
        foreach ($snapshots as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(), $table.' snapshots must remain immutable.');
        }
    }
}
