<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductPriceChangeEvent;
use App\Models\ProductStockItem;
use App\Models\ProductSupplierSource;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierPricePolicy;
use App\Models\User;
use App\Services\Admin\AdminProductWorkspaceData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class AdminProductWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'workspace-secret-canary-17b';

    public function test_workspace_keeps_all_category_and_family_choices_including_selected_relations_after_500(): void
    {
        $this->manager();
        $categories = [];
        $families = [];
        for ($index = 0; $index < 500; $index++) {
            $categories[] = ['name' => sprintf('A category %03d', $index), 'slug' => 'workspace-category-'.$index];
            $families[] = ['name' => sprintf('A family %03d', $index), 'slug' => 'workspace-family-'.$index];
        }
        Category::query()->insert($categories);
        ProductFamily::query()->insert($families);
        $category = Category::factory()->create(['name' => 'Z selected category']);
        $family = ProductFamily::factory()->create(['name' => 'Z selected family']);
        $product = Product::factory()->create(['category_id' => $category->id, 'product_family_id' => $family->id]);
        $response = $this->get(route('admin.products.workspace', $product))->assertOk();
        $options = $response->viewData('workspace');
        $this->assertGreaterThan(500, count($options['category_options']));
        $this->assertGreaterThan(500, count($options['family_options']));
        $this->assertContains($category->id, array_column($options['category_options'], 'id'));
        $this->assertContains($family->id, array_column($options['family_options'], 'id'));
        $this->saveSection($product, 'detail', ['name' => 'Still grouped'])->assertOk();
        $this->assertSame($category->id, $product->fresh()->category_id);
        $this->assertSame($family->id, $product->fresh()->product_family_id);
    }

    public function test_manager_can_read_html_and_json_workspace_without_credentials(): void
    {
        $this->manager();
        $product = Product::factory()->create();
        ProductStockItem::query()->create(['product_id' => $product->id, 'secret' => self::SECRET]);
        $item = $this->catalogItem();
        $this->source($product, $item);

        $this->get(route('admin.products.workspace', $product))->assertOk()->assertViewIs('admin.products.workspace')->assertDontSee(self::SECRET);
        $response = $this->getJson(route('admin.products.workspace', $product))->assertOk();
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $response
            ->assertJsonPath('product.id', $product->id)
            ->assertJsonPath('edit_payload.stockItems', [])
            ->assertJsonPath('stock.summary.available', 1)
            ->assertJsonPath('sources.0.supplier_version', 1)
            ->assertDontSee(self::SECRET)->assertJsonMissingPath('sources.0.supplier.api_key')
            ->assertJsonMissingPath('sources.0.catalog.description');
    }

    public function test_support_cannot_read_workspace_or_catalog_or_write_sections(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Support]));
        $product = Product::factory()->create();
        $this->get(route('admin.products.workspace', $product))->assertForbidden();
        $this->getJson(route('admin.products.workspace.catalog', $product))->assertForbidden();
        $this->patchJson(route('admin.products.update', $product), ['workspace_section' => 'detail', 'workspace_version' => str_repeat('0', 64), 'name' => 'Forbidden'])->assertForbidden();
    }

    public function test_deleted_product_has_no_workspace(): void
    {
        $this->manager();
        $product = Product::factory()->create();
        $product->delete();
        $this->getJson(route('admin.products.workspace', $product))->assertNotFound();
    }

    public function test_detail_save_preserves_prices_tiers_visibility_images_and_stock(): void
    {
        $this->manager();
        $product = $this->tieredProduct();
        $before = $product->only(['price_amount', 'usdt_price_amount', 'icon', 'is_active', 'featured_rank', 'image_path', 'stock', 'reserved_stock']);
        $this->saveSection($product, 'detail', ['name' => 'Updated name', 'description_en' => 'English description', 'delivery_note' => 'Private delivery instructions', 'price_amount' => 1, 'is_active' => false, 'quantity_price_tiers_present' => true, 'quantity_price_tiers' => []])
            ->assertOk()->assertJsonPath('workspace.product.name', 'Updated name');
        $this->assertSame($before, $product->fresh()->only(array_keys($before)));
        $this->assertSame(1, $product->quantityPriceTiers()->count());
        $this->assertSame('Private delivery instructions', $product->fresh()->delivery_note);
        $this->assertSame(0, ProductPriceChangeEvent::query()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.updated', 'subject_id' => (string) $product->id]);
    }

    public function test_appearance_save_preserves_all_detail_and_pricing_fields(): void
    {
        $this->manager();
        $product = $this->tieredProduct();
        $before = $product->only(['name', 'description', 'description_en', 'warranty_policy', 'support_policy', 'delivery_note', 'price_amount', 'usdt_price_amount', 'stock', 'reserved_stock']);
        $this->saveSection($product, 'appearance', ['icon' => 'A', 'is_active' => false, 'featured_rank' => 4, 'description' => 'Not owned', 'image_path' => 'unmanaged/attack.webp'])
            ->assertOk()->assertJsonPath('workspace.product.featured_rank', 4);
        $this->assertSame($before, $product->fresh()->only(array_keys($before)));
        $this->assertNull($product->fresh()->image_path);
        $this->assertSame(1, $product->quantityPriceTiers()->count());
    }

    public function test_manual_price_save_preserves_detail_appearance_and_omitted_tiers(): void
    {
        $this->manager();
        $product = $this->tieredProduct();
        $before = $product->only(['name', 'description', 'delivery_note', 'is_active', 'featured_rank', 'stock', 'reserved_stock']);
        $this->saveSection($product, 'price', ['price_amount' => 120000, 'usdt_price_amount' => '12.345', 'name' => 'Not owned'])
            ->assertOk()->assertJsonPath('workspace.product.usdt_price_amount', '12.345');
        $this->assertSame(12345000, $product->fresh()->usdt_price_amount);
        $this->assertSame($before, $product->fresh()->only(array_keys($before)));
        $this->assertSame(1, $product->quantityPriceTiers()->count());
    }

    public function test_repricing_cannot_invalidate_preserved_quantity_schedule(): void
    {
        $this->manager();
        $product = $this->tieredProduct();
        $before = $product->only(['price_amount', 'usdt_price_amount', 'stock', 'reserved_stock']);
        $auditCount = AuditLog::query()->count();
        $this->saveSection($product, 'price', ['price_amount' => 80000])->assertUnprocessable()->assertJsonValidationErrors('quantity_price_tiers');
        $this->assertSame($before, $product->fresh()->only(array_keys($before)));
        $this->assertSame(90000, $product->quantityPriceTiers()->sole()->price_amount);
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    public function test_price_save_can_explicitly_clear_tiers_but_not_unrelated_fields(): void
    {
        $this->manager();
        $product = $this->tieredProduct();
        $this->saveSection($product, 'price', ['quantity_price_tiers_present' => true, 'quantity_price_tiers' => [], 'usdt_price_amount' => null])->assertOk();
        $this->assertSame(0, $product->quantityPriceTiers()->count());
        $this->assertNull($product->fresh()->usdt_price_amount);
        $this->assertTrue($product->fresh()->is_active);
    }

    public function test_price_schedule_validation_remains_atomic(): void
    {
        $this->manager();
        $product = $this->tieredProduct();
        $this->saveSection($product, 'price', ['price_amount' => 150000, 'quantity_price_tiers_present' => true, 'quantity_price_tiers' => [['minimum_quantity' => 2, 'price_amount' => 160000, 'usdt_price_amount' => '9']]])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity_price_tiers');
        $this->assertSame(100000, $product->fresh()->price_amount);
        $this->assertSame(90000, $product->quantityPriceTiers()->sole()->price_amount);
    }

    public function test_same_section_stale_save_conflicts_without_mutating_or_auditing(): void
    {
        $this->manager();
        $product = Product::factory()->create();
        $token = $this->version($product, 'detail');
        $this->saveSection($product, 'detail', ['name' => 'First writer'], $token)->assertOk();
        $auditCount = AuditLog::query()->count();
        $this->saveSection($product, 'detail', ['name' => 'Stale writer'], $token)->assertConflict();
        $this->assertSame('First writer', $product->fresh()->name);
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    public function test_independent_section_versions_allow_concurrent_edits(): void
    {
        $this->manager();
        $product = Product::factory()->create(['price_amount' => 1000]);
        $detailToken = $this->version($product, 'detail');
        $appearanceToken = $this->version($product, 'appearance');
        $this->saveSection($product, 'price', ['price_amount' => 2000])->assertOk();
        $this->saveSection($product, 'detail', ['name' => 'Independent detail'], $detailToken)->assertOk();
        $this->saveSection($product, 'appearance', ['icon' => 'B'], $appearanceToken)->assertOk();
        $this->assertSame(2000, $product->fresh()->price_amount);
        $this->assertSame('Independent detail', $product->fresh()->name);
    }

    public function test_stock_and_supplier_projection_refresh_do_not_conflict_with_detail_draft(): void
    {
        $this->manager();
        $product = Product::factory()->create(['stock' => 4, 'reserved_stock' => 1]);
        $token = $this->version($product, 'detail');
        $product->forceFill(['stock' => 7, 'supplier_available_stock' => 8, 'supplier_available_until' => now()->addMinute()])->save();
        $this->saveSection($product, 'detail', ['name' => 'Still safe'], $token)->assertOk();
        $this->assertSame(7, $product->fresh()->stock);
        $this->assertSame(1, $product->fresh()->reserved_stock);
        $this->assertSame(8, $product->fresh()->supplier_available_stock);
    }

    public function test_tier_change_invalidates_price_version_even_without_product_timestamp_change(): void
    {
        $this->manager();
        $product = $this->tieredProduct();
        $token = $this->version($product, 'price');
        $product->quantityPriceTiers()->update(['price_amount' => 85000]);
        $this->saveSection($product, 'price', ['price_amount' => 110000], $token)->assertConflict();
        $this->assertSame(100000, $product->fresh()->price_amount);
        $this->assertSame(85000, $product->quantityPriceTiers()->sole()->price_amount);
    }

    public function test_missing_version_unknown_section_and_unsafe_guide_are_rejected_as_json(): void
    {
        $this->manager();
        $product = Product::factory()->create();
        $this->patchJson(route('admin.products.update', $product), ['workspace_section' => 'detail', 'name' => 'No token'])->assertUnprocessable()->assertJsonValidationErrors('workspace_version');
        $this->patchJson(route('admin.products.update', $product), ['workspace_section' => 'stock', 'workspace_version' => str_repeat('0', 64)])->assertUnprocessable()->assertJsonValidationErrors('workspace_section');
        $this->saveSection($product, 'detail', ['usage_guide_title' => 'Guide', 'usage_guide_url' => 'javascript:alert(1)'])->assertUnprocessable()->assertJsonValidationErrors('usage_guide_url');
    }

    public function test_localized_budget_checks_preserved_counterpart_when_only_description_changes(): void
    {
        $this->manager();
        $product = Product::factory()->create(['warranty_policy_en' => str_repeat('w', 2500)]);
        $this->saveSection($product, 'detail', ['description_en' => str_repeat('d', 2500)])->assertUnprocessable()->assertJsonValidationErrors('description_en');
        $this->assertSame(str_repeat('w', 2500), $product->fresh()->warranty_policy_en);
    }

    public function test_catalog_search_is_supplier_scoped_bounded_and_secret_free(): void
    {
        $this->manager();
        $product = Product::factory()->create();
        $first = $this->catalogItem();
        for ($index = 0; $index < 29; $index++) {
            $this->catalogItem($first->supplier, ['external_id' => 'search-sku-'.$index, 'name' => 'Matching SKU '.$index]);
        }
        $this->catalogItem(null, ['name' => 'Matching other supplier']);
        $response = $this->getJson(route('admin.products.workspace.catalog', ['product' => $product, 'supplier_id' => $first->supplier_id, 'q' => 'Matching']));
        $response->assertOk()->assertJsonCount(25, 'items')->assertJsonPath('pagination.total', 29)->assertJsonPath('pagination.per_page', 25)->assertDontSee(self::SECRET)->assertDontSee('Matching other supplier');
        foreach ($response->json('items') as $item) {
            $this->assertSame($first->supplier_id, $item['supplier']['id']);
            $this->assertSame(0, $item['version']);
        }
    }

    public function test_catalog_excludes_this_products_mappings_but_not_other_products_mappings(): void
    {
        $this->manager();
        $product = Product::factory()->create();
        $other = Product::factory()->create();
        $item = $this->catalogItem();
        $this->source($other, $item, ['version' => 9]);
        $this->getJson(route('admin.products.workspace.catalog', ['product' => $product, 'supplier_id' => $item->supplier_id]))->assertOk()->assertJsonPath('items.0.version', 0);
        $this->source($product, $item, ['version' => 3]);
        $this->getJson(route('admin.products.workspace.catalog', ['product' => $product, 'supplier_id' => $item->supplier_id]))->assertOk()->assertJsonCount(0, 'items')->assertJsonPath('pagination.total', 0);
    }

    public function test_catalog_search_matches_supplier_name_and_keeps_stale_items_selectable(): void
    {
        $this->manager();
        $product = Product::factory()->create();
        $item = $this->catalogItem();
        $item->supplier->forceFill(['name' => 'Searchable Acme Supplier', 'synced_at' => now()->subHours(2), 'sync_expires_at' => now()->subHour()])->save();
        $this->catalogItem(null, ['name' => 'Other catalog item']);
        $this->getJson(route('admin.products.workspace.catalog', ['product' => $product, 'q' => 'Acme']))
            ->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $item->id)
            ->assertJsonPath('items.0.supplier.is_fresh', false)->assertDontSee(self::SECRET);
    }

    public function test_workspace_separates_stale_supplier_catalog_stock_from_available_projection(): void
    {
        $this->manager();
        $product = Product::factory()->create(['stock' => 4, 'reserved_stock' => 1]);
        $product->forceFill(['supplier_available_stock' => 12, 'supplier_available_until' => now()->subMinute()])->save();
        $item = $this->catalogItem(null, ['stock' => 99]);
        $item->supplier->forceFill(['synced_at' => now()->subHours(2), 'sync_expires_at' => now()->subHour()])->save();
        $this->source($product, $item, ['is_active' => true]);
        $this->getJson(route('admin.products.workspace', $product))->assertOk()
            ->assertJsonPath('sources.0.supplier.is_fresh', false)
            ->assertJsonPath('sources.0.catalog.stock', 99)
            ->assertJsonPath('stock.supplier_available_stock', 0)
            ->assertJsonPath('stock.available_stock', 3)
            ->assertJsonPath('stock.total_available_stock', 3)
            ->assertJsonStructure(['sources' => [['supplier' => ['synced_at', 'sync_expires_at']]], 'stock' => ['supplier_available_until']]);

        $product->forceFill(['supplier_available_until' => now()->addMinute()])->save();
        $item->supplier->forceFill(['sync_expires_at' => now()->addHour()])->save();
        $this->getJson(route('admin.products.workspace', $product))->assertOk()
            ->assertJsonPath('sources.0.supplier.is_fresh', true)
            ->assertJsonPath('stock.supplier_available_stock', 12)
            ->assertJsonPath('stock.total_available_stock', 15);
    }

    public function test_mapping_requires_equivalence_and_returns_refreshed_workspace_without_stock_changes(): void
    {
        $this->manager();
        $product = Product::factory()->create(['stock' => 7, 'reserved_stock' => 2]);
        $item = $this->catalogItem();
        $input = ['product_id' => $product->id, 'supplier_version' => 1, 'version' => 0, 'priority' => 10, 'is_active' => false];
        $this->postJson(route('admin.suppliers.map', $item), $input)->assertUnprocessable()->assertJsonValidationErrors('equivalent_confirmed');
        $this->postJson(route('admin.suppliers.map', $item), $input + ['equivalent_confirmed' => true])->assertOk()->assertJsonPath('workspace.sources.0.version', 1)->assertJsonPath('workspace.sources.0.supplier_version', 2)->assertDontSee(self::SECRET);
        $this->assertSame(7, $product->fresh()->stock);
        $this->assertSame(2, $product->fresh()->reserved_stock);
        $this->postJson(route('admin.suppliers.map', $item), $input + ['equivalent_confirmed' => true])->assertConflict();
        $this->assertSame(1, $product->supplierSources()->count());
    }

    public function test_detach_cannot_target_another_products_mapping(): void
    {
        $this->manager();
        $product = Product::factory()->create();
        $item = $this->catalogItem();
        $source = $this->source(Product::factory()->create(), $item);
        $this->deleteJson(route('admin.suppliers.detach', $item), ['product_id' => $product->id, 'source_id' => $source->id, 'version' => 1, 'supplier_version' => 1])->assertUnprocessable();
        $this->assertNotNull($source->fresh());
    }

    public function test_import_redirects_directly_to_draft_workspace_and_is_repeatable(): void
    {
        $this->manager();
        $item = $this->catalogItem();
        $input = ['supplier_version' => 1, 'name' => 'Store-owned draft', 'description' => 'Store-owned description', 'price_amount' => 20000];
        $this->post(route('admin.suppliers.import', $item), $input)->assertRedirect(route('admin.products.workspace', ['product' => Product::query()->sole(), 'section' => 'detail']));
        $product = Product::query()->sole();
        $this->postJson(route('admin.suppliers.import', $item), $input)->assertOk()->assertJsonPath('workspace.product.id', $product->id)->assertJsonPath('workspace.product.is_active', false);
        $this->assertSame(1, Product::query()->count());
        $this->assertSame(0, $product->stock);
    }

    public function test_supplier_policy_save_and_noop_keep_stock_and_return_current_versions(): void
    {
        $this->manager();
        $product = Product::factory()->create(['stock' => 8, 'reserved_stock' => 3]);
        $input = ['version' => 0, 'mode' => 'manual', 'markup_basis_points' => 1250, 'profit_idr' => 0, 'minimum_profit_idr' => 0, 'buffer_idr' => 0, 'rounding_idr' => 100];
        $this->postJson(route('admin.products.supplier-pricing', $product), $input)->assertOk()->assertJsonPath('workspace.policy.version', 1)->assertJsonPath('workspace.policy.markup_basis_points', 1250);
        $auditCount = AuditLog::query()->count();
        $this->postJson(route('admin.products.supplier-pricing', $product), array_replace($input, ['version' => 1]))->assertOk()->assertJsonPath('workspace.policy.version', 1);
        $this->assertSame($auditCount, AuditLog::query()->count());
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertSame(3, $product->fresh()->reserved_stock);
        $this->postJson(route('admin.products.supplier-pricing', $product), $input)->assertConflict();
        $this->postJson(route('admin.products.supplier-pricing', $product), array_replace($input, ['version' => 1, 'markup_basis_points' => 100001]))->assertUnprocessable();
    }

    public function test_preview_rejects_unmapped_sku_without_mutating_price_or_policy(): void
    {
        $this->manager();
        $product = Product::factory()->create(['price_amount' => 12345]);
        $item = $this->catalogItem();
        $this->postJson(route('admin.products.supplier-pricing.preview', $product), ['version' => 0, 'mode' => 'percent', 'markup_basis_points' => 1000, 'profit_idr' => 0, 'minimum_profit_idr' => 0, 'buffer_idr' => 0, 'rounding_idr' => 1, 'catalog_item_id' => $item->id])->assertUnprocessable();
        $this->assertSame(12345, $product->fresh()->price_amount);
        $this->assertSame(0, SupplierPricePolicy::query()->count());
    }

    public function test_quick_stock_json_save_and_validation_do_not_flash_secrets_or_redirect(): void
    {
        $this->manager();
        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);
        $this->postJson(route('admin.products.stock.store', $product), ['stock_items' => self::SECRET, 'stock_format' => 'legacy'])->assertOk()->assertJsonPath('workspace.stock.stock', 1)->assertDontSee(self::SECRET);
        $this->postJson(route('admin.products.stock.store', $product), ['stock_items' => ''])->assertUnprocessable()->assertJsonValidationErrors('stock_items');
        $this->assertNull(session()->get('_old_input.stock_items'));
        $this->assertSame(1, $product->fresh()->stock);
    }

    public function test_automatic_policy_cannot_break_existing_quantity_price_constraints(): void
    {
        $this->manager();
        $product = $this->tieredProduct();
        $item = $this->catalogItem();
        $item->supplier->forceFill(['is_active' => true, 'sync_expires_at' => now()->addHour()])->save();
        $this->source($product, $item, ['is_active' => true]);
        $this->postJson(route('admin.products.supplier-pricing', $product), ['version' => 0, 'mode' => 'fixed', 'markup_basis_points' => 0, 'profit_idr' => 0, 'minimum_profit_idr' => 0, 'buffer_idr' => 0, 'rounding_idr' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('mode');
        $this->assertSame(100000, $product->fresh()->price_amount);
        $this->assertSame(90000, $product->quantityPriceTiers()->sole()->price_amount);
        $this->assertSame(0, SupplierPricePolicy::query()->count());
        $this->assertSame(9, $product->fresh()->stock);
    }

    public function test_workspace_price_change_retains_notification_event_and_noop_does_not_duplicate_it(): void
    {
        $this->manager();
        $product = $this->tieredProduct();
        $this->saveSection($product, 'price', ['price_amount' => 120000])->assertOk();
        $event = ProductPriceChangeEvent::query()->sole();
        $this->assertSame(100000, $event->old_price_amount);
        $this->assertSame(120000, $event->new_price_amount);
        $this->saveSection($product, 'price', ['price_amount' => 120000])->assertOk();
        $this->assertSame(1, ProductPriceChangeEvent::query()->count());
    }

    public function test_stock_edit_and_delete_json_refresh_without_exposing_credentials(): void
    {
        $this->manager();
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0]);
        $stock = ProductStockItem::query()->create(['product_id' => $product->id, 'secret' => 'original-private-stock']);
        $this->patchJson(route('admin.products.stock-items.update', ['product' => $product, 'stockItem' => $stock]), ['secret' => self::SECRET])
            ->assertOk()->assertJsonPath('workspace.stock.stock', 1)->assertDontSee(self::SECRET);
        $this->assertSame(self::SECRET, $stock->fresh()->secret);
        $this->deleteJson(route('admin.products.stock-items.destroy', ['product' => $product, 'stockItem' => $stock]))
            ->assertOk()->assertJsonPath('workspace.stock.stock', 0)->assertDontSee(self::SECRET);
        $this->assertSame(0, $product->stockItems()->count());
    }

    private function manager(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
    }

    private function version(Product $product, string $section): string
    {
        return app(AdminProductWorkspaceData::class)->version($product->fresh(), $section);
    }

    private function saveSection(Product $product, string $section, array $input, ?string $version = null)
    {
        return $this->patchJson(route('admin.products.update', $product), ['workspace_section' => $section, 'workspace_version' => $version ?? $this->version($product, $section)] + $input);
    }

    private function tieredProduct(): Product
    {
        $product = Product::factory()->create(['price_amount' => 100000, 'usdt_price_amount' => 10000000, 'is_active' => true, 'stock' => 9, 'reserved_stock' => 2, 'image_path' => null, 'support_policy' => 'Support unchanged', 'delivery_note' => 'Delivery unchanged']);
        $product->quantityPriceTiers()->create(['minimum_quantity' => 2, 'price_amount' => 90000, 'usdt_price_amount' => 9000000]);

        return $product;
    }

    private function catalogItem(?Supplier $supplier = null, array $overrides = []): SupplierCatalogItem
    {
        if ($supplier === null) {
            $supplier = new Supplier;
            $supplier->forceFill(['name' => 'Private supplier', 'provider' => 'prodseller', 'api_key' => self::SECRET, 'currency' => 'USDT', 'currency_confirmed' => true, 'exchange_rate_idr' => 16000, 'is_active' => false, 'low_balance_threshold_micros' => 0, 'version' => 1, 'reserved_balance_micros' => 0])->save();
        }
        $item = new SupplierCatalogItem;
        $item->forceFill(array_replace(['supplier_id' => $supplier->id, 'external_id' => 'sku-1', 'name' => 'Private catalog SKU', 'description' => self::SECRET, 'unit_price_micros' => 1000000, 'stock' => 10, 'reserved_quantity' => 0, 'orderable' => true, 'requires_input' => false, 'price_tiers' => [], 'bulk_discounts' => []], $overrides))->save();

        return $item;
    }

    private function source(Product $product, SupplierCatalogItem $item, array $overrides = []): ProductSupplierSource
    {
        $source = new ProductSupplierSource;
        $source->forceFill(array_replace(['product_id' => $product->id, 'supplier_catalog_item_id' => $item->id, 'priority' => 10, 'is_active' => false, 'version' => 1], $overrides))->save();

        return $source;
    }
}
