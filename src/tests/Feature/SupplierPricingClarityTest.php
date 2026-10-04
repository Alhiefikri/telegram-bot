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
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\NonTransactionalDatabaseTestCase as TestCase;

#[Group('postgres-release')]
final class SupplierPricingClarityTest extends TestCase
{
    public function test_product_supplier_page_explains_ordered_eligible_automatic_price_and_sku_simulation(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        $product = Product::factory()->create();
        $this->policy($product, 'fixed');
        $this->source($product, 'Primary SKU', 1000000, 10);
        $this->source($product, 'Backup SKU', 500000, 20);

        $this->get(route('admin.products.suppliers', $product))->assertOk()
            ->assertSee('Harga otomatis tersimpan dihitung dari supplier aktif dan layak pertama menurut urutan (Urutan 1 diprioritaskan). Jika Urutan 1 tidak memenuhi syarat basis harga, source berikutnya yang memenuhi syarat digunakan.')
            ->assertSee('Bukan memilih harga termurah, dan kelayakan basis harga bukan jaminan stok atau saldo cukup saat checkout.')
            ->assertSee('Hitung simulasi berdasarkan SKU')
            ->assertSee('SKU yang dipilih hanya untuk simulasi; tidak memilih source harga tersimpan dan tidak menyimpan kebijakan atau harga jual.')
            ->assertSeeInOrder(['Urutan 1', 'Urutan 2'])
            ->assertDontSee('Harga otomatis tidak aktif pada mode manual.');
    }

    public function test_manual_mode_does_not_imply_automatic_pricing_is_active(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        $product = Product::factory()->create(['price_amount' => 45000]);
        $this->policy($product, 'manual');
        $this->source($product, 'Mapped SKU', 1000000, 10);

        $this->get(route('admin.products.suppliers', $product))->assertOk()
            ->assertSee('Harga otomatis tidak aktif pada mode manual. Harga jual tersimpan mengikuti harga yang kamu isi di halaman Produk.')
            ->assertSee('Jika mode otomatis disimpan')
            ->assertSee('Hitung simulasi berdasarkan SKU');
    }

    public function test_product_without_sources_explains_no_automatic_price_basis_and_has_no_sku_preview(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        $product = Product::factory()->create(['price_amount' => 45000]);
        $this->policy($product, 'fixed');

        $this->get(route('admin.products.suppliers', $product))->assertOk()
            ->assertSee('Belum ada mapping supplier; belum ada source untuk menghitung harga otomatis atau simulasi SKU.')
            ->assertDontSee('Hitung simulasi berdasarkan SKU');
        $this->assertSame(45000, $product->fresh()->price_amount);
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
            'buffer_idr' => 250,
            'rounding_idr' => 1000,
            'catalog_item_id' => $backup->supplier_catalog_item_id,
        ];

        if ($json) {
            $this->postJson(route('admin.products.supplier-pricing.preview', $product), $input)
                ->assertOk()->assertExactJson(['cost_idr' => 8000, 'retail_idr' => 12000]);
        } else {
            $this->post(route('admin.products.supplier-pricing.preview', $product), $input)->assertOk()
                ->assertViewIs('admin.suppliers.product')
                ->assertViewHas('previewItem', fn (SupplierCatalogItem $item): bool => $item->id === $backup->supplier_catalog_item_id)
                ->assertViewHas('policy', fn (SupplierPricePolicy $draft): bool => $draft->mode === 'hybrid' && $draft->buffer_idr === 250 && ! $draft->exists)
                ->assertSee('Hasil simulasi harga untuk 1 produk')
                ->assertSee('Rp8.000')->assertSee('Rp12.000')
                ->assertSee('Simulasi SKU terpilih tidak mengubah urutan supplier atau source basis harga otomatis tersimpan.')
                ->assertSee('Belum membeli produk atau menyimpan perubahan.');
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
        $policy->forceFill(['product_id' => $product->id, 'mode' => $mode, 'markup_basis_points' => 0, 'profit_idr' => 5000, 'minimum_profit_idr' => 0, 'buffer_idr' => 0, 'rounding_idr' => 1, 'version' => 3])->save();

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
