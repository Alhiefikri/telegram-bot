<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductSupplierSource;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierPricePolicy;
use App\Models\SupplierPurchase;
use App\Models\User;
use App\Services\Suppliers\SupplierAvailability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\NonTransactionalDatabaseTestCase as TestCase;

#[Group('postgres-release')]
final class SupplierPricingClarityTest extends TestCase
{
    public function test_removing_buffer_preserves_saved_retail_and_rejects_pre_migration_policy_versions(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        $migration = require database_path('migrations/2026_10_15_000001_remove_supplier_price_policy_buffer.php');
        $migration->down();
        $product = Product::factory()->create(['price_amount' => 12345]);
        DB::table('supplier_price_policies')->insert([
            'product_id' => $product->id, 'mode' => 'manual', 'markup_basis_points' => 0,
            'profit_idr' => 3000, 'minimum_profit_idr' => 1000, 'buffer_idr' => 500,
            'rounding_idr' => 1, 'version' => 7, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $migration->up();
        $policy = SupplierPricePolicy::query()->where('product_id', $product->id)->sole();
        $this->assertSame(12345, $product->fresh()->price_amount);
        $this->assertSame(8, $policy->version);
        $this->assertSame(3000, $policy->profit_idr);
        $this->assertSame(1000, $policy->minimum_profit_idr);
        $input = [
            'version' => 7, 'mode' => 'manual', 'markup_basis_points' => 0,
            'profit_idr' => 3000, 'minimum_profit_idr' => 1000, 'rounding_idr' => 1,
        ];
        $this->postJson(route('admin.products.supplier-pricing', $product), $input)
            ->assertStatus(409)->assertJsonValidationErrors('version');
        $this->postJson(route('admin.products.supplier-pricing', $product), array_replace($input, ['version' => 8]))
            ->assertOk()->assertJsonPath('workspace.policy.version', 8);
        $this->assertSame(12345, $product->fresh()->price_amount);
    }

    #[DataProvider('savedModesAndPreviewFormats')]
    public function test_backup_sku_preview_never_changes_saved_price_policy_or_source_selection(string $savedMode, bool $json): void
    {
        $this->freezeTime();
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        Http::fake();
        $product = Product::factory()->create(['price_amount' => 45000, 'stock' => 0, 'reserved_stock' => 0, 'supplier_available_stock' => 0, 'usdt_price_amount' => null]);
        $policy = $this->policy($product, $savedMode);
        $primary = $this->source($product, 'Primary SKU', 1000000, 10);
        $backup = $this->source($product, 'Backup SKU', 500000, 20);

        // Rank 1 remains the automatic basis even though the backup is cheaper
        // and neither source has stock or wallet capacity to authorize checkout.
        app(SupplierAvailability::class)->refreshProducts([$product->id]);
        $savedPrice = $savedMode === 'manual' ? 45000 : 21000;
        $this->assertSame($savedPrice, $product->refresh()->price_amount);
        $this->assertSame(0, $product->supplier_available_stock);
        $savedPolicy = $policy->fresh()->getAttributes();
        $savedPrimary = $primary->fresh()->getAttributes();
        $savedBackup = $backup->fresh()->getAttributes();
        $auditCount = AuditLog::query()->count();
        $input = [
            'version' => $policy->version,
            'mode' => 'hybrid',
            'markup_basis_points' => 1000,
            'profit_idr' => 3000,
            'minimum_profit_idr' => 1000,
            'rounding_idr' => 1000,
            'catalog_item_id' => $backup->supplier_catalog_item_id,
        ];

        if ($json) {
            $this->postJson(route('admin.products.supplier-pricing.preview', $product), $input)
                ->assertOk()->assertExactJson([
                    'quantity' => 1,
                    'cost_idr' => 8000,
                    'retail_idr' => 11000,
                    'profit_idr' => 3000,
                    'cost_unit_idr' => 8000,
                    'retail_unit_idr' => 11000,
                    'profit_unit_idr' => 3000,
                    'currency' => 'USDT',
                ]);
        } else {
            $this->post(route('admin.products.supplier-pricing.preview', $product), $input)->assertOk()
                ->assertViewHas('workspace', fn (array $workspace): bool => $workspace['price_preview'] === [
                    'quantity' => 1,
                    'cost_idr' => 8000,
                    'retail_idr' => 11000,
                    'profit_idr' => 3000,
                    'cost_unit_idr' => 8000,
                    'retail_unit_idr' => 11000,
                    'profit_unit_idr' => 3000,
                    'currency' => 'USDT',
                ]
                    && $workspace['preview_catalog_item_id'] === $backup->supplier_catalog_item_id
                    && $workspace['policy']['mode'] === 'hybrid'
                );
        }

        $this->assertSame($savedPrice, $product->fresh()->price_amount);
        $this->assertSame($savedPolicy, $policy->fresh()->getAttributes());
        $this->assertSame($savedPrimary, $primary->fresh()->getAttributes());
        $this->assertSame($savedBackup, $backup->fresh()->getAttributes());
        $this->assertSame(1, SupplierPricePolicy::query()->where('product_id', $product->id)->count());
        $this->assertSame($auditCount, AuditLog::query()->count());
        $this->assertSame(0, SupplierPurchase::query()->count());
        app(SupplierAvailability::class)->refreshProducts([$product->id]);
        $this->assertSame($savedPrice, $product->fresh()->price_amount);
        Http::assertNothingSent();
    }

    public static function savedModesAndPreviewFormats(): array
    {
        return [
            'automatic browser preview' => ['fixed', false],
            'automatic JSON preview' => ['fixed', true],
            'manual browser preview' => ['manual', false],
            'manual JSON preview' => ['manual', true],
        ];
    }

    private function policy(Product $product, string $mode): SupplierPricePolicy
    {
        $policy = new SupplierPricePolicy;
        $policy->forceFill(['product_id' => $product->id, 'mode' => $mode, 'markup_basis_points' => 0, 'profit_idr' => 5000, 'minimum_profit_idr' => 0, 'rounding_idr' => 1, 'version' => 3])->save();

        return $policy;
    }

    private function source(Product $product, string $name, int $micros, int $priority): ProductSupplierSource
    {
        $supplier = new Supplier;
        $supplier->forceFill(['name' => $name.' supplier', 'provider' => 'prodseller', 'api_key' => 'isolated-pricing-key', 'currency' => 'USDT', 'currency_confirmed' => true, 'exchange_rate_idr' => 16000, 'is_active' => true, 'balance_micros' => 0, 'reserved_balance_micros' => 0, 'low_balance_threshold_micros' => 0, 'synced_at' => now(), 'sync_expires_at' => now()->addMinutes(5), 'version' => 1])->save();
        $item = new SupplierCatalogItem;
        $item->forceFill(['supplier_id' => $supplier->id, 'external_id' => 'sku-'.$priority, 'name' => $name, 'unit_price_micros' => $micros, 'stock' => 0, 'reserved_quantity' => 0, 'orderable' => true, 'requires_input' => false, 'price_tiers' => [], 'bulk_discounts' => [], 'synced_at' => now()])->save();
        $source = new ProductSupplierSource;
        $source->forceFill(['product_id' => $product->id, 'supplier_catalog_item_id' => $item->id, 'priority' => $priority, 'is_active' => true, 'version' => 1])->save();

        return $source;
    }
}
