<?php

namespace Tests\Feature;

use App\Enums\InventoryMovementType;
use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductSupplierSource;
use App\Models\StockNotificationEvent;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierFulfillment;
use App\Models\SupplierPricePolicy;
use App\Models\SupplierPurchase;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\NonTransactionalDatabaseTestCase;

final class SupplierRateAuditCommandTest extends NonTransactionalDatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_dry_run_reports_an_implausible_connection_and_changes_nothing(): void
    {
        $supplier = $this->connection();
        $this->mappedProduct($this->sku($supplier));

        $this->artisan('suppliers:rate-audit')
            ->expectsOutputToContain('Koneksi #'.$supplier->id.' nastele VND: kurs 18000.000000 di luar rentang wajar. Produk terkait: 1.')
            ->expectsOutputToContain('Ringkasan: 1 dari 1 koneksi diperiksa memiliki kurs di luar rentang wajar.')
            ->assertExitCode(Command::SUCCESS);

        $stored = $supplier->fresh();
        $this->assertSame('18000.000000', $stored->exchange_rate_idr);
        $this->assertSame(1, (int) $stored->version);
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_fix_applies_the_replacement_rate_and_recomputes_capacity_without_touching_purchases(): void
    {
        $supplier = $this->connection();
        $item = $this->sku($supplier);
        $product = $this->mappedProduct($item);
        $purchase = $this->settledPurchase($supplier, $item);
        $before = (array) DB::table('supplier_purchases')->where('id', $purchase->id)->first();
        $this->assertSame(0, $product->fresh()->supplier_available_stock);

        $this->artisan('suppliers:rate-audit', ['--fix' => true, '--to' => '0.692308', '--force' => true])
            ->assertExitCode(Command::SUCCESS);

        $corrected = $supplier->fresh();
        $this->assertSame('0.692308', $corrected->exchange_rate_idr);
        $this->assertSame(2, (int) $corrected->version);

        $audit = AuditLog::query()->where('action', 'supplier.rate_corrected')->sole();
        $this->assertSame('18000.000000', $audit->old_values['exchange_rate_idr']);
        $this->assertSame('0.692308', $audit->new_values['exchange_rate_idr']);
        $this->assertSame('suppliers:rate-audit', $audit->new_values['command']);

        // 300.000 VND wallet / 69.000 VND per unit = four units at the corrected rate.
        $this->assertSame(4, $product->fresh()->supplier_available_stock);
        // The corrected rate turns a zero safe capacity into a genuine restock signal.
        $movement = InventoryMovement::query()->sole();
        $this->assertSame(InventoryMovementType::Availability, $movement->type);
        $this->assertSame(4, $movement->available_after);
        $this->assertSame(1, StockNotificationEvent::query()->count());
        $this->assertEquals($before, (array) DB::table('supplier_purchases')->where('id', $purchase->id)->first());
    }

    public function test_a_second_fix_run_changes_nothing_and_writes_no_more_audit_rows(): void
    {
        $supplier = $this->connection();
        $this->mappedProduct($this->sku($supplier));

        $arguments = ['--fix' => true, '--to' => '0.692308', '--force' => true];
        $this->assertSame(Command::SUCCESS, Artisan::call('suppliers:rate-audit', $arguments));
        $this->assertSame(1, AuditLog::query()->count());

        $this->assertSame(Command::SUCCESS, Artisan::call('suppliers:rate-audit', $arguments));

        $stored = $supplier->fresh();
        $this->assertSame('0.692308', $stored->exchange_rate_idr);
        $this->assertSame(2, (int) $stored->version);
        $this->assertSame(1, AuditLog::query()->count());
    }

    public function test_missing_or_implausible_replacement_rates_fail_without_changes(): void
    {
        $supplier = $this->connection();
        $item = $this->sku($supplier);
        $product = $this->mappedProduct($item);

        $this->assertSame(Command::INVALID, Artisan::call('suppliers:rate-audit', ['--fix' => true]));
        $this->assertSame(Command::FAILURE, Artisan::call('suppliers:rate-audit', ['--fix' => true, '--to' => '18000', '--force' => true]));

        $stored = $supplier->fresh();
        $this->assertSame('18000.000000', $stored->exchange_rate_idr);
        $this->assertSame(1, (int) $stored->version);
        $this->assertSame(0, AuditLog::query()->count());
        $this->assertSame(0, $product->fresh()->supplier_available_stock);
    }

    public function test_declined_confirmation_leaves_every_connection_untouched(): void
    {
        $supplier = $this->connection();
        $this->mappedProduct($this->sku($supplier));

        $this->artisan('suppliers:rate-audit', ['--fix' => true, '--to' => '0.692308'])
            ->expectsConfirmation('Terapkan kurs 0.692308 ke seluruh koneksi di atas?', 'no')
            ->expectsOutput('Tidak ada perubahan.')
            ->assertExitCode(Command::FAILURE);

        $stored = $supplier->fresh();
        $this->assertSame('18000.000000', $stored->exchange_rate_idr);
        $this->assertSame(1, (int) $stored->version);
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_query_count_does_not_grow_with_the_number_of_mapped_products(): void
    {
        // The connection was never observed, so the after-fix refresh recomputes the
        // same zero capacity for every mapped product: any growth in query count is
        // the audit itself walking products one by one.
        $supplier = $this->connection('18000', ['synced_at' => null, 'sync_expires_at' => null]);
        $item = $this->sku($supplier);

        $this->mapProducts($item, 1);
        $single = $this->measuredFixQueryCount($supplier);

        $this->mapProducts($item, 99, 1);
        $many = $this->measuredFixQueryCount($supplier);

        $this->assertGreaterThan(0, $single);
        $this->assertSame($single, $many);
    }

    public function test_a_connection_with_an_open_commitment_is_reported_but_left_unchanged(): void
    {
        $supplier = $this->connection('18000', ['reserved_balance_micros' => 5_000_000]);
        $this->mappedProduct($this->sku($supplier));

        $this->artisan('suppliers:rate-audit', ['--fix' => true, '--to' => '0.692308', '--force' => true])
            ->expectsOutputToContain('Koneksi #'.$supplier->id.' nastele VND: kurs 18000.000000 di luar rentang wajar.')
            ->expectsOutputToContain('Komitmen supplier masih aktif. Selesaikan pembelian sebelum mengubah kredensial atau mata uang.')
            ->assertExitCode(Command::FAILURE);

        $stored = $supplier->fresh();
        $this->assertSame('18000.000000', $stored->exchange_rate_idr);
        $this->assertSame(1, (int) $stored->version);
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_the_audit_actor_must_be_an_existing_positive_integer_id(): void
    {
        $supplier = $this->connection();
        $this->mappedProduct($this->sku($supplier));

        foreach (['0', '-1', 'abc', '999999'] as $actor) {
            $this->assertSame(
                Command::INVALID,
                Artisan::call('suppliers:rate-audit', ['--fix' => true, '--to' => '0.692308', '--force' => true, '--actor' => $actor]),
            );
            $this->assertSame('18000.000000', $supplier->fresh()->exchange_rate_idr);
        }

        $admin = User::factory()->create();
        $this->assertSame(
            Command::SUCCESS,
            Artisan::call('suppliers:rate-audit', ['--fix' => true, '--to' => '0.692308', '--force' => true, '--actor' => (string) $admin->id]),
        );

        $audit = AuditLog::query()->where('action', 'supplier.rate_corrected')->sole();
        $this->assertSame((int) $admin->id, (int) $audit->user_id);
    }

    private function connection(string $rate = '18000', array $overrides = []): Supplier
    {
        return Supplier::forceCreate(array_replace([
            'name' => 'Nastele VND',
            'provider' => 'nastele',
            'api_key' => 'private-rate-audit-key',
            'currency' => 'VND',
            'currency_confirmed' => true,
            'exchange_rate_idr' => $rate,
            'is_active' => true,
            'version' => 1,
            'balance_micros' => 300_000_000_000,
            'reserved_balance_micros' => 0,
            'low_balance_threshold_micros' => 0,
            'synced_at' => now(),
            'sync_expires_at' => now()->addMinutes(5),
        ], $overrides));
    }

    private function sku(Supplier $supplier, array $overrides = []): SupplierCatalogItem
    {
        return SupplierCatalogItem::forceCreate(array_replace([
            'supplier_id' => $supplier->id,
            'external_id' => '43',
            'name' => 'Nastele SKU',
            'currency' => 'VND',
            'unit_price_micros' => 69_000_000_000,
            'stock' => 20,
            'reserved_quantity' => 0,
            'orderable' => true,
            'requires_input' => false,
            'price_tiers' => [],
            'bulk_discounts' => [],
            'synced_at' => now(),
        ], $overrides));
    }

    private function mappedProduct(SupplierCatalogItem $item, array $overrides = []): Product
    {
        $product = Product::factory()->create(array_replace(['price_amount' => 60000, 'stock' => 0], $overrides));
        SupplierPricePolicy::query()->create(['product_id' => $product->id, 'mode' => 'manual', 'rounding_idr' => 1]);
        ProductSupplierSource::forceCreate(['product_id' => $product->id, 'supplier_catalog_item_id' => $item->id, 'priority' => 10, 'is_active' => true, 'version' => 1]);

        return $product;
    }

    private function mapProducts(SupplierCatalogItem $item, int $count, int $offset = 0): void
    {
        for ($index = $offset; $index < $offset + $count; $index++) {
            $this->mappedProduct($item, ['name' => 'Audited product '.$index]);
        }
    }

    private function settledPurchase(Supplier $supplier, SupplierCatalogItem $item): SupplierPurchase
    {
        $orderItem = OrderItem::factory()->create();
        $fulfillment = SupplierFulfillment::query()->create([
            'order_id' => $orderItem->order_id,
            'order_item_id' => $orderItem->id,
            'quantity' => 1,
            'state' => 'delivered',
            'maximum_cost_idr' => 20000,
            'sources_snapshot' => [],
        ]);

        return SupplierPurchase::query()->create([
            'supplier_fulfillment_id' => $fulfillment->id,
            'supplier_id' => $supplier->id,
            'supplier_catalog_item_id' => $item->id,
            'reference' => (string) Str::uuid(),
            'supplier_order_id' => '2244',
            'quantity' => 1,
            'observed_quantity' => 1,
            'expected_cost_micros' => 69_000_000_000,
            'actual_cost_micros' => 69_000_000_000,
            'exchange_rate_idr' => 18000,
            'state' => 'delivered',
            'committed_at' => now(),
        ]);
    }

    private function measuredFixQueryCount(Supplier $supplier): int
    {
        // Every measured run starts from the broken stored rate and its original version.
        $supplier->newQuery()->whereKey($supplier->id)->update(['exchange_rate_idr' => '18000', 'version' => 1]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $exitCode = Artisan::call('suppliers:rate-audit', ['--fix' => true, '--to' => '0.692308', '--force' => true]);
            $count = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame('0.692308', $supplier->fresh()->exchange_rate_idr);

        return $count;
    }
}
