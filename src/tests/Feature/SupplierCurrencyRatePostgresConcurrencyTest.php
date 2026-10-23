<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exceptions\SupplierRateMappingChanged;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductSupplierSource;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierCurrencyRate;
use App\Models\SupplierPricePolicy;
use App\Models\User;
use App\Services\Suppliers\ProductSupplierManager;
use App\Services\Suppliers\SupplierCurrencyRateManager;
use App\Services\Suppliers\SupplierExchangeRate;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RunsPostgresWorkers;
use Tests\Support\PostgresTestDatabase;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
final class SupplierCurrencyRatePostgresConcurrencyTest extends TestCase
{
    use RunsPostgresWorkers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requirePostgresConcurrency('Supplier currency rates');
        $this->assertSame(0, PostgresTestDatabase::migrateFresh());
        Queue::fake();
        Http::preventStrayRequests();
    }

    public function test_first_setup_race_has_one_winner_and_one_stale_version_without_partial_writes(): void
    {
        SupplierCurrencyRate::create(['currency' => 'VND', 'exchange_rate_idr' => '0.6', 'version' => 1]);
        $supplier = $this->supplier('VND', '0.6');
        $this->installCacheFence();
        $directory = $this->createWorkspace('supplier-rate-initialization');
        $inputs = [
            ['USD' => ['exchange_rate_idr' => '16000', 'version' => 0], 'VND' => ['exchange_rate_idr' => '0.65', 'version' => 1]],
            ['USD' => ['exchange_rate_idr' => '17000', 'version' => 0], 'VND' => ['exchange_rate_idr' => '0.7', 'version' => 1]],
        ];

        try {
            $results = $this->runConcurrentWorkers(2, function (int $index) use ($inputs, $directory): string {
                $this->boundWorkerQueries();
                $firstRateQuery = true;
                $initializationAttempted = false;
                DB::connection()->beforeExecuting(function (string $sql) use ($index, $directory, &$firstRateQuery, &$initializationAttempted): void {
                    if (! str_contains($sql, 'supplier_currency_rates')) {
                        return;
                    }
                    $initializationAttempted = $initializationAttempted || str_starts_with(strtolower($sql), 'insert');
                    if ($firstRateQuery) {
                        $firstRateQuery = false;
                        touch($directory.'/initial-'.$index);
                        $this->waitForMarker($directory.'/initial-'.(1 - $index));
                    }
                });
                DB::listen(function (QueryExecuted $query) use ($index, $directory, &$initializationAttempted): void {
                    // Keep the old missing-row SELECT race deterministic without
                    // blocking the conflict-safe INSERT, which serializes setup.
                    if (! $initializationAttempted && str_starts_with(strtolower($query->sql), 'select')
                        && str_contains($query->sql, 'supplier_currency_rates')) {
                        touch($directory.'/missing-read-'.$index);
                        $this->waitForMarker($directory.'/missing-read-'.(1 - $index));
                    }
                });

                try {
                    $this->assertSame(2, app(SupplierCurrencyRateManager::class)->save($inputs[$index], null, null));

                    return 'saved';
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('rates', $exception->errors());

                    return 'stale';
                }
            }, 'supplier-rate-first-setup-workers');
        } finally {
            $this->cleanupWorkspace($directory);
        }

        $this->assertWorkersSucceeded($results);
        $outcomes = array_column($results, 'value');
        $this->assertEqualsCanonicalizing(['saved', 'stale'], $outcomes);
        $winner = array_search('saved', $outcomes, true);
        $this->assertSame(2, SupplierCurrencyRate::count());
        foreach ($inputs[$winner] as $currency => $input) {
            $rate = SupplierCurrencyRate::findOrFail($currency);
            $this->assertTrue(SupplierExchangeRate::equals($input['exchange_rate_idr'], $rate->exchange_rate_idr));
            $this->assertSame($input['version'] + 1, (int) $rate->version);
        }
        $this->assertTrue(SupplierExchangeRate::equals($inputs[$winner]['VND']['exchange_rate_idr'], $supplier->fresh()->exchange_rate_idr));
        $this->assertSame(2, (int) $supplier->fresh()->version);
        $audits = AuditLog::where('action', 'supplier.rate_corrected')->get();
        $this->assertCount(2, $audits);
        $this->assertEqualsCanonicalizing(['USD', 'VND'], $audits->pluck('subject_id')->all());
        foreach ($audits as $audit) {
            $this->assertTrue(SupplierExchangeRate::equals($inputs[$winner][$audit->subject_id]['exchange_rate_idr'], $audit->new_values['exchange_rate_idr']));
        }
    }

    public function test_stale_multi_rate_setup_rolls_back_initialization_and_all_cache_and_audit_writes(): void
    {
        SupplierCurrencyRate::create(['currency' => 'VND', 'exchange_rate_idr' => '0.6', 'version' => 1]);
        $supplier = $this->supplier('VND', '0.6');
        $this->installCacheFence();

        try {
            app(SupplierCurrencyRateManager::class)->save([
                'USD' => ['exchange_rate_idr' => '16000', 'version' => 0],
                'VND' => ['exchange_rate_idr' => '0.65', 'version' => 0],
            ], null, null);
            $this->fail('A stale rate must reject the entire setup transaction.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rates', $exception->errors());
        }

        $this->assertNull(SupplierCurrencyRate::find('USD'));
        $this->assertTrue(SupplierExchangeRate::equals('0.6', SupplierCurrencyRate::findOrFail('VND')->exchange_rate_idr));
        $this->assertSame(1, (int) SupplierCurrencyRate::findOrFail('VND')->version);
        $this->assertTrue(SupplierExchangeRate::equals('0.6', $supplier->fresh()->exchange_rate_idr));
        $this->assertSame(1, (int) $supplier->fresh()->version);
        $this->assertSame(0, AuditLog::where('action', 'supplier.rate_corrected')->count());
    }

    public function test_repeated_mapping_conflicts_are_bounded_and_reject_without_writes(): void
    {
        SupplierCurrencyRate::create(['currency' => 'USD', 'exchange_rate_idr' => '20000', 'version' => 1]);
        $supplier = $this->supplier('USD', '20000');
        $this->installCacheFence();
        $conflicts = 0;
        $injectConflicts = true;
        DB::listen(function (QueryExecuted $query) use (&$conflicts, &$injectConflicts): void {
            if ($injectConflicts && str_starts_with(strtolower($query->sql), 'select distinct "product_id"')
                && str_contains($query->sql, 'product_supplier_sources')) {
                $conflicts++;
                throw new SupplierRateMappingChanged;
            }
        });

        try {
            app(SupplierCurrencyRateManager::class)->save([
                'USD' => ['exchange_rate_idr' => '10000', 'version' => 1],
            ], null, null);
            $this->fail('Continual mapping conflicts must require a reload.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rates', $exception->errors());
        } finally {
            $injectConflicts = false;
        }

        $this->assertSame(5, $conflicts);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertTrue(SupplierExchangeRate::equals('20000', SupplierCurrencyRate::findOrFail('USD')->exchange_rate_idr));
        $this->assertSame(1, (int) SupplierCurrencyRate::findOrFail('USD')->version);
        $this->assertTrue(SupplierExchangeRate::equals('20000', $supplier->fresh()->exchange_rate_idr));
        $this->assertSame(1, (int) $supplier->fresh()->version);
        $this->assertSame(0, AuditLog::where('action', 'supplier.rate_corrected')->count());
    }

    public function test_mapping_expansion_restarts_before_writes_and_refreshes_every_product_in_lock_order(): void
    {
        SupplierCurrencyRate::create(['currency' => 'USD', 'exchange_rate_idr' => '20000', 'version' => 1]);
        $supplier = $this->supplier('USD', '20000');
        $item = SupplierCatalogItem::create([
            'supplier_id' => $supplier->id, 'external_id' => 'rate-race-sku', 'name' => 'Rate race SKU',
            'currency' => 'USD', 'unit_price_micros' => 1_000_000, 'stock' => 10,
            'orderable' => true, 'requires_input' => false, 'synced_at' => now(), 'price_tiers' => [], 'bulk_discounts' => [],
        ]);
        $products = collect([0, 1])->map(function (): Product {
            $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0, 'price_amount' => 25000]);
            SupplierPricePolicy::create([
                'product_id' => $product->id, 'mode' => 'percent', 'markup_basis_points' => 2500,
                'profit_idr' => 0, 'minimum_profit_idr' => 0, 'rounding_idr' => 1, 'version' => 1,
            ]);

            return $product;
        });
        ProductSupplierSource::create([
            'product_id' => $products[0]->id, 'supplier_catalog_item_id' => $item->id,
            'is_active' => true, 'priority' => 10, 'version' => 1,
        ]);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->installCacheFence();
        $directory = $this->createWorkspace('supplier-rate-mapping-expansion');

        try {
            $results = $this->runConcurrentWorkers(2, function (int $index) use ($directory, $supplier, $item, $products, $owner): string {
                $this->boundWorkerQueries();
                if ($index === 1) {
                    $this->waitForMarker($directory.'/mapping-read');
                    app(ProductSupplierManager::class)->map($products[1], $item, [
                        'supplier_version' => 1, 'version' => 0, 'equivalent_confirmed' => true,
                        'is_active' => true, 'priority' => 10,
                    ], $owner, null);
                    $this->assertSame(25000, (int) $products[1]->fresh()->price_amount);
                    $this->assertSame(0, AuditLog::where('action', 'supplier.rate_corrected')->count());
                    $this->assertTrue(SupplierExchangeRate::equals('20000', $supplier->fresh()->exchange_rate_idr));
                    touch($directory.'/mapping-refreshed');

                    return 'mapped';
                }

                $mappingReads = 0;
                $transactions = 0;
                $supplierLocked = false;
                $recording = true;
                Event::listen(TransactionBeginning::class, function () use (&$supplierLocked, &$transactions): void {
                    $supplierLocked = false;
                    $transactions++;
                });
                DB::listen(function (QueryExecuted $query) use ($directory, &$mappingReads, &$supplierLocked, &$recording): void {
                    if (! $recording) {
                        return;
                    }
                    $sql = strtolower($query->sql);
                    if (str_starts_with($sql, 'select distinct "product_id"') && str_contains($sql, 'product_supplier_sources')) {
                        $mappingReads++;
                        if ($mappingReads === 1) {
                            touch($directory.'/mapping-read');
                            $this->waitForMarker($directory.'/mapping-refreshed');
                        }
                    }
                    if (str_contains($sql, 'for update')) {
                        if (str_contains($sql, 'from "products"')) {
                            $this->assertFalse($supplierLocked, 'A retry must release Supplier locks before acquiring more Product locks.');
                        }
                        if (str_contains($sql, 'from "suppliers"')) {
                            $supplierLocked = true;
                        }
                    }
                });
                $this->assertSame(1, app(SupplierCurrencyRateManager::class)->save([
                    'USD' => ['exchange_rate_idr' => '10000', 'version' => 1],
                ], null, null));
                $recording = false;
                $this->assertGreaterThanOrEqual(3, $mappingReads, 'Expansion requires a locked recheck and a new transaction pre-read.');
                $this->assertGreaterThanOrEqual(2, $transactions);
                foreach ($products as $product) {
                    $this->assertSame(12500, (int) $product->fresh()->price_amount);
                    $this->assertSame(8, (int) $product->fresh()->supplier_available_stock);
                }

                return 'saved';
            }, 'supplier-rate-mapping-workers');
        } finally {
            $this->cleanupWorkspace($directory);
        }

        $this->assertWorkersSucceeded($results);
        $this->assertSame(['saved', 'mapped'], array_column($results, 'value'));
        $this->assertSame(2, (int) SupplierCurrencyRate::findOrFail('USD')->version);
        $this->assertTrue(SupplierExchangeRate::equals('10000', $supplier->fresh()->exchange_rate_idr));
        $this->assertSame(3, (int) $supplier->fresh()->version, 'Only mapping and the committed propagation may increment the cache version.');
        $this->assertSame(2_000_000, (int) $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(1, AuditLog::where('action', 'supplier.rate_corrected')->count());
        $this->assertSame(1, AuditLog::where('action', 'supplier.source_saved')->count());
        foreach ($products as $product) {
            $this->assertSame(12500, (int) $product->fresh()->price_amount);
            $this->assertSame(0, (int) $product->fresh()->reserved_stock);
        }
    }

    private function supplier(string $currency, string $rate): Supplier
    {
        return Supplier::forceCreate([
            'name' => 'Rate race connection', 'provider' => $currency === 'VND' ? 'nastele' : 'csxstore',
            'api_key' => 'synthetic-rate-race-key', 'currency' => $currency, 'currency_confirmed' => true,
            'exchange_rate_idr' => $rate, 'version' => 1, 'balance_micros' => 10_000_000,
            'reserved_balance_micros' => 2_000_000, 'low_balance_threshold_micros' => 0,
            'is_active' => true, 'synced_at' => now(), 'sync_expires_at' => now()->addMinutes(5),
        ]);
    }

    private function installCacheFence(): void
    {
        $migration = require database_path('migrations/2026_10_24_000002_fence_supplier_currency_rate_caches.php');
        $migration->up();
    }

    private function boundWorkerQueries(): void
    {
        DB::statement("SET lock_timeout TO '8s'");
        DB::statement("SET statement_timeout TO '12s'");
    }
}
