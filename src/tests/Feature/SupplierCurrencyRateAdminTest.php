<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductQuantityPriceTier;
use App\Models\ProductSupplierSource;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierCurrencyRate;
use App\Models\SupplierPricePolicy;
use App\Models\User;
use App\Services\Admin\AdminSupplierDashboardData;
use App\Services\Suppliers\SupplierAvailability;
use App\Services\Suppliers\SupplierManager;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\NonTransactionalDatabaseTestCase;

final class SupplierCurrencyRateAdminTest extends NonTransactionalDatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_one_rate_per_currency_propagates_to_every_connection(): void
    {
        $owner = $this->owner();
        $this->seedRates();
        $usd = $this->supplier(['provider' => 'csxstore', 'currency' => 'USD', 'exchange_rate_idr' => '16000.000000']);
        $usdSecond = $this->supplier(['provider' => 'canboso', 'currency' => 'USD', 'exchange_rate_idr' => '16000.000000']);
        $vnd = $this->supplier(['provider' => 'nastele', 'currency' => 'VND', 'exchange_rate_idr' => '0.650001']);
        $usdt = $this->supplier(['provider' => 'prodseller', 'currency' => 'USDT', 'exchange_rate_idr' => '16000.000000']);

        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('admin.suppliers.currency-rates.update'), ['rates' => [
                'USD' => ['exchange_rate_idr' => '17000', 'version' => 1],
                'VND' => ['exchange_rate_idr' => '0.692308', 'version' => 1],
            ]])->assertOk()->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'kapasitas'));

        $this->assertSame('17000', $this->rate(SupplierCurrencyRate::query()->findOrFail('USD')->exchange_rate_idr));
        $this->assertSame('0.692308', $this->rate(SupplierCurrencyRate::query()->findOrFail('VND')->exchange_rate_idr));
        $this->assertSame(2, (int) SupplierCurrencyRate::query()->findOrFail('USD')->version);
        $this->assertNull(SupplierCurrencyRate::query()->find('USDT'), 'USDT shares the USD row.');

        $this->assertSame('17000', $this->rate($usd->fresh()->exchange_rate_idr));
        $this->assertSame(2, (int) $usd->fresh()->version);
        $this->assertSame('17000', $this->rate($usdSecond->fresh()->exchange_rate_idr));
        $this->assertSame(2, (int) $usdSecond->fresh()->version);
        $this->assertSame('0.692308', $this->rate($vnd->fresh()->exchange_rate_idr));
        $this->assertSame(2, (int) $vnd->fresh()->version);
        $this->assertSame('17000', $this->rate($usdt->fresh()->exchange_rate_idr));
        $this->assertSame(2, (int) $usdt->fresh()->version);

        $audits = AuditLog::query()->where('action', 'supplier.rate_corrected')->orderBy('id')->get();
        $this->assertCount(2, $audits);
        $this->assertSame('USD', $audits[0]->subject_id);
        $this->assertSame('17000', $this->rate($audits[0]->new_values['exchange_rate_idr']));
        $this->assertSame('VND', $audits[1]->subject_id);
        $this->assertSame('0.692308', $audits[1]->new_values['exchange_rate_idr']);
        $this->assertSame('admin', $audits[1]->new_values['command']);
    }

    public function test_stale_versions_and_implausible_rates_are_rejected_without_writes(): void
    {
        $owner = $this->owner();
        $this->seedRates();

        $this->actingAs($owner)->postJson(route('admin.suppliers.currency-rates.update'), ['rates' => $this->rateInput(['VND' => ['exchange_rate_idr' => '0.692308', 'version' => 0]])])
            ->assertStatus(428);

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('admin.suppliers.currency-rates.update'), ['rates' => $this->rateInput(['VND' => ['exchange_rate_idr' => '0.692308', 'version' => 0]])])
            ->assertStatus(422)->assertJsonValidationErrors('rates');

        $response = $this->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('admin.suppliers.currency-rates.update'), ['rates' => $this->rateInput(['VND' => ['exchange_rate_idr' => '18000', 'version' => 1]])])
            ->assertStatus(422)->assertJsonValidationErrors('rates.VND.exchange_rate_idr');
        $errors = $response->json('errors');
        $this->assertStringContainsString('VND', (string) $errors['rates.VND.exchange_rate_idr'][0]);
        $this->assertStringNotContainsString('18000', $response->getContent());

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('admin.suppliers.currency-rates.update'), ['rates' => [
                'USD' => ['exchange_rate_idr' => '16000', 'version' => 1],
            ]])->assertStatus(422)->assertJsonValidationErrors('rates');

        $this->assertSame('0.650001', $this->rate(SupplierCurrencyRate::query()->findOrFail('VND')->exchange_rate_idr));
        $this->assertSame(1, (int) SupplierCurrencyRate::query()->findOrFail('VND')->version);
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_a_drifted_connection_cache_is_repaired_from_the_global_rate(): void
    {
        $owner = $this->owner();
        $this->seedRates();
        SupplierCurrencyRate::query()->whereKey('VND')->update(['exchange_rate_idr' => '0.692308']);
        $supplier = $this->supplier(['provider' => 'nastele', 'currency' => 'VND', 'exchange_rate_idr' => '18000']);

        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('admin.suppliers.currency-rates.update'), ['rates' => $this->rateInput([
                'VND' => ['exchange_rate_idr' => '0.692308', 'version' => 1],
            ])])->assertOk();

        $this->assertSame('0.692308', $this->rate($supplier->fresh()->exchange_rate_idr));
        $this->assertSame(2, (int) $supplier->fresh()->version);
        $this->assertSame(1, (int) SupplierCurrencyRate::query()->findOrFail('VND')->version, 'The global rate did not change.');
        $audit = AuditLog::query()->where('action', 'supplier.rate_corrected')->sole();
        $this->assertSame('18000', $this->rate($audit->old_values['exchange_rate_idr']));
        $this->assertSame('0.692308', $audit->new_values['exchange_rate_idr']);
    }

    public function test_non_manager_cannot_change_global_rates(): void
    {
        $this->seedRates();
        $this->actingAs(User::factory()->create(['role' => UserRole::Support]))
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('admin.suppliers.currency-rates.update'), ['rates' => $this->rateInput(['VND' => ['exchange_rate_idr' => '0.692308', 'version' => 1]])])
            ->assertForbidden();

        $this->assertSame('0.650001', $this->rate(SupplierCurrencyRate::query()->findOrFail('VND')->exchange_rate_idr));
    }

    public function test_rate_change_recomputes_capacity_and_keeps_open_commitments(): void
    {
        $owner = $this->owner();
        $this->seedRates();
        // At Rp0,999999 per VND the 69.000 VND SKU costs more than the Rp60.000
        // retail price, so the product starts with no safe capacity.
        SupplierCurrencyRate::query()->whereKey('VND')->update(['exchange_rate_idr' => '0.999999']);
        $supplier = $this->supplier([
            'provider' => 'nastele', 'currency' => 'VND', 'exchange_rate_idr' => '0.999999',
            'balance_micros' => 300_000_000_000, 'reserved_balance_micros' => 5_000_000,
            'synced_at' => now(), 'sync_expires_at' => now()->addMinutes(5),
        ]);
        $item = SupplierCatalogItem::query()->create([
            'supplier_id' => $supplier->id, 'external_id' => '43', 'name' => 'Synthetic SKU', 'currency' => 'VND',
            'unit_price_micros' => 69_000_000_000, 'stock' => 20, 'orderable' => true, 'requires_input' => false,
            'synced_at' => now(), 'price_tiers' => [], 'bulk_discounts' => [],
        ]);
        $product = Product::factory()->create(['price_amount' => 60000, 'stock' => 0]);
        SupplierPricePolicy::query()->create(['product_id' => $product->id, 'mode' => 'manual', 'rounding_idr' => 1]);
        ProductSupplierSource::forceCreate(['product_id' => $product->id, 'supplier_catalog_item_id' => $item->id, 'priority' => 10, 'is_active' => true, 'version' => 1]);
        app(SupplierAvailability::class)->refreshProducts([$product->id]);
        $this->assertSame(0, $product->fresh()->supplier_available_stock);

        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('admin.suppliers.currency-rates.update'), ['rates' => $this->rateInput([
                'VND' => ['exchange_rate_idr' => '0.692308', 'version' => 1],
            ])])->assertOk();

        $this->assertSame(4, $product->fresh()->supplier_available_stock);
        $this->assertSame(5_000_000, (int) $supplier->fresh()->reserved_balance_micros);
        $this->assertSame('0.692308', $this->rate($supplier->fresh()->exchange_rate_idr));
    }

    public function test_dashboard_exposes_one_row_per_currency_and_used_connection_count(): void
    {
        $this->seedRates();
        $this->supplier(['provider' => 'csxstore', 'currency' => 'USD', 'exchange_rate_idr' => '16000.000000']);
        $this->supplier(['provider' => 'prodseller', 'currency' => 'USDT', 'exchange_rate_idr' => '16000.000000']);
        $this->supplier(['provider' => 'nastele', 'currency' => 'VND', 'exchange_rate_idr' => '0.650001']);

        $rates = app(AdminSupplierDashboardData::class)->forTab()['currencyRates'];
        $this->assertSame(['USD', 'VND'], array_column($rates, 'currency'));
        $this->assertSame('USD / USDT', $rates[0]['label']);
        $this->assertSame(['USD', 'USDT'], $rates[0]['currencies']);
        $this->assertSame('16000', $rates[0]['exchange_rate_idr']);
        $this->assertSame(2, $rates[0]['used_by']);
        $this->assertSame('0.650001', $rates[1]['exchange_rate_idr']);
        $this->assertSame(1, $rates[1]['used_by']);

        $html = view('admin.suppliers.connection-form', [
            'connection' => ['provider' => 'nastele', 'currency' => 'VND'],
            'currencyRates' => $rates,
        ])->render();
        $this->assertStringNotContainsString('name="exchange_rate_idr"', $html);
        $this->assertStringContainsString('Kurs global', $html);
        $this->assertStringContainsString('rateText', $html);
    }

    public function test_new_connection_requires_a_global_rate_and_inherits_it(): void
    {
        $owner = $this->owner();
        SupplierCurrencyRate::query()->create(['currency' => 'USD', 'exchange_rate_idr' => '17000.000000', 'version' => 1]);

        try {
            app(SupplierManager::class)->save(null, $this->supplierInput('VND'), $owner, null);
            $this->fail('A connection without a global rate must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('currency', $exception->errors());
        }
        $this->assertSame(0, Supplier::query()->count());

        $supplier = app(SupplierManager::class)->save(null, $this->supplierInput('USD'), $owner, null);
        $this->assertSame('17000', $this->rate($supplier->exchange_rate_idr));
        $this->assertSame(1, (int) $supplier->version);
    }

    public function test_profit_simulation_uses_supplier_wholesale_and_product_quantity_tiers(): void
    {
        $owner = $this->owner();
        $supplier = $this->supplier(['provider' => 'csxstore', 'currency' => 'USD', 'exchange_rate_idr' => '16000.000000']);
        $item = SupplierCatalogItem::query()->create([
            'supplier_id' => $supplier->id, 'external_id' => 'simulation-sku', 'name' => 'Simulation SKU',
            'currency' => 'USD', 'unit_price_micros' => 1_000_000, 'stock' => 100,
            'orderable' => true, 'requires_input' => false, 'synced_at' => now(),
            'price_tiers' => [['min' => 10, 'max' => null, 'unit_price_micros' => 800_000]],
            'bulk_discounts' => [],
        ]);
        $product = Product::factory()->create(['price_amount' => 30000, 'stock' => 0]);
        ProductQuantityPriceTier::query()->create([
            'product_id' => $product->id, 'minimum_quantity' => 10, 'price_amount' => 25000,
        ]);
        ProductSupplierSource::forceCreate([
            'product_id' => $product->id, 'supplier_catalog_item_id' => $item->id,
            'priority' => 10, 'is_active' => true, 'version' => 1,
        ]);

        $this->actingAs($owner)->postJson(route('admin.products.supplier-pricing.preview', $product), [
            'version' => 0, 'mode' => 'manual', 'markup_basis_points' => 0, 'profit_idr' => 0,
            'minimum_profit_idr' => 0, 'rounding_idr' => 1, 'catalog_item_id' => $item->id,
            'quantity' => 10,
        ])->assertOk()
            ->assertJsonPath('quantity', 10)
            ->assertJsonPath('cost_idr', 128000)
            ->assertJsonPath('cost_unit_idr', 12800)
            ->assertJsonPath('retail_idr', 250000)
            ->assertJsonPath('retail_unit_idr', 25000)
            ->assertJsonPath('profit_idr', 122000)
            ->assertJsonPath('profit_unit_idr', 12200)
            ->assertJsonPath('currency', 'USD');
    }

    private function rate(mixed $value): string
    {
        $value = (string) $value;

        return str_contains($value, '.') ? (rtrim(rtrim($value, '0'), '.') ?: '0') : $value;
    }

    public function test_merge_migration_consolidates_usd_and_usdt_at_the_conservative_rate(): void
    {
        $this->seedRates();
        SupplierCurrencyRate::query()->whereKey('USD')->update(['exchange_rate_idr' => '16000.000000']);
        SupplierCurrencyRate::query()->create(['currency' => 'USDT', 'exchange_rate_idr' => '17000.000000', 'version' => 1]);
        $usd = $this->supplier(['provider' => 'csxstore', 'currency' => 'USD', 'exchange_rate_idr' => '16000.000000']);
        $usdt = $this->supplier(['provider' => 'prodseller', 'currency' => 'USDT', 'exchange_rate_idr' => '18000.000000']);

        $migration = require database_path('migrations/2026_10_24_000001_merge_usd_usdt_supplier_currency_rates.php');
        $migration->up();

        $this->assertSame('17000', $this->rate(SupplierCurrencyRate::query()->findOrFail('USD')->exchange_rate_idr));
        $this->assertSame(2, (int) SupplierCurrencyRate::query()->findOrFail('USD')->version);
        $this->assertNull(SupplierCurrencyRate::query()->find('USDT'));
        $this->assertSame('17000', $this->rate($usd->fresh()->exchange_rate_idr));
        $this->assertSame(2, (int) $usd->fresh()->version);
        $this->assertSame('17000', $this->rate($usdt->fresh()->exchange_rate_idr));
        $this->assertSame(2, (int) $usdt->fresh()->version);
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => UserRole::Owner]);
    }

    private function seedRates(): void
    {
        foreach (['USD' => '16000.000000', 'VND' => '0.650001'] as $currency => $rate) {
            SupplierCurrencyRate::query()->create(['currency' => $currency, 'exchange_rate_idr' => $rate, 'version' => 1]);
        }
    }

    private function rateInput(array $overrides = []): array
    {
        return array_replace([
            'USD' => ['exchange_rate_idr' => '16000', 'version' => 1],
            'VND' => ['exchange_rate_idr' => '0.650001', 'version' => 1],
        ], $overrides);
    }

    private function supplier(array $overrides = []): Supplier
    {
        return Supplier::forceCreate(array_replace([
            'name' => 'Synthetic connection', 'provider' => 'csxstore', 'api_key' => 'synthetic-rate-key',
            'currency' => 'USD', 'currency_confirmed' => true, 'exchange_rate_idr' => '16000',
            'is_active' => true, 'version' => 1, 'balance_micros' => 0, 'reserved_balance_micros' => 0,
            'low_balance_threshold_micros' => 0,
        ], $overrides));
    }

    private function supplierInput(string $currency): array
    {
        return [
            'name' => 'Synthetic connection', 'provider' => 'csxstore', 'api_key' => 'synthetic-rate-key',
            'currency' => $currency, 'currency_confirmed' => true, 'is_active' => true,
            'low_balance_threshold_micros' => 0,
        ];
    }
}
