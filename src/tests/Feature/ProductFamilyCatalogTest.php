<?php

namespace Tests\Feature;

use App\Catalog\CatalogBrowseQuery;
use App\Catalog\CatalogCategoryOptions;
use App\Catalog\CatalogCategoryReadModel;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductStockItem;
use App\Models\ProductWaitlistSubscription;
use App\Models\User;
use App\Services\Catalog\CatalogBrowse;
use App\Services\ProductWaitlistEmailHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductFamilyCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_family_schema_and_relationship_contracts_are_additive(): void
    {
        $this->assertTrue(Schema::hasTable('product_families'));
        $this->assertTrue(Schema::hasColumns('product_families', ['id', 'name', 'slug', 'description', 'image_path', 'sort_order', 'deleted_at']));
        $this->assertTrue(Schema::hasColumns('products', ['product_family_id', 'family_sort_order', 'family_option_label']));

        $family = ProductFamily::factory()->create();
        $product = Product::factory()->create([
            'product_family_id' => $family->id,
            'family_sort_order' => 3,
            'family_option_label' => 'Package',
        ]);

        $this->assertSame($family->id, $product->family->id);
        $this->assertSame($family->id, $product->productFamily->id);
        $this->assertSame($product->id, $family->products->sole()->id);
        $this->assertSame(3, $product->family_order);
        $this->assertSame('Package', $product->option_label);
    }

    public function test_committed_family_and_membership_mutations_invalidate_storefront_cache(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $family = ProductFamily::factory()->create(['name' => 'Cache Family']);
        $product = Product::factory()->create(['name' => 'Cache Product', 'stock' => 2]);
        Cache::put('storefront.response.version', 100, now()->addHour());

        $this->actingAs($admin)
            ->patch(route('admin.product-families.update', $family), [
                'name' => 'Cache Family Updated',
                'description' => null,
                'sort_order' => 0,
            ])
            ->assertRedirect();
        $afterFamily = (int) Cache::get('storefront.response.version');

        $this->actingAs($admin)
            ->patch(route('admin.products.family.update', $product), [
                'product_family_id' => $family->id,
                'family_sort_order' => 1,
            ])
            ->assertRedirect();

        $this->assertGreaterThan(100, $afterFamily);
        $this->assertGreaterThan($afterFamily, (int) Cache::get('storefront.response.version'));
    }

    public function test_public_catalog_collapses_family_members_and_keeps_a_sold_out_family_visible(): void
    {
        $category = Category::factory()->create(['name' => 'Streaming', 'slug' => 'streaming']);
        $family = ProductFamily::factory()->create(['name' => 'Netflix', 'slug' => 'netflix']);
        $available = Product::factory()->create([
            'name' => 'Netflix Individual',
            'description' => 'Individual profile',
            'category_id' => $category->id,
            'product_family_id' => $family->id,
            'family_sort_order' => 2,
            'family_option_label' => 'Individual',
            'price_amount' => 25000,
            'usdt_price_amount' => 500000,
            'stock' => 3,
            'reserved_stock' => 1,
            'is_active' => true,
        ]);
        $soldOut = Product::factory()->create([
            'name' => 'Netflix Family',
            'description' => 'Family profile',
            'category_id' => $category->id,
            'product_family_id' => $family->id,
            'family_sort_order' => 1,
            'family_option_label' => 'Family',
            'price_amount' => 20000,
            'usdt_price_amount' => null,
            'stock' => 0,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        $standalone = Product::factory()->create([
            'name' => 'Spotify Standalone',
            'category_id' => null,
            'stock' => 2,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $result = app(CatalogBrowse::class)->browsePublic(new CatalogBrowseQuery(perPage: 20));

        $this->assertCount(2, $result->entries);
        $this->assertCount(1, $result->families);
        $this->assertCount(1, $result->standaloneProducts);
        $familyRead = $result->families[0];
        $this->assertSame($family->id, $familyRead->id);
        $this->assertSame(2, $familyRead->optionCount);
        $this->assertSame(1, $familyRead->availableOptionCount);
        $this->assertSame(1, $familyRead->soldOutOptionCount);
        $this->assertTrue($familyRead->isAvailable);
        $this->assertSame($soldOut->id, $familyRead->members[0]->id);
        $this->assertSame($available->id, $familyRead->members[1]->id);
        $this->assertSame('Family', $familyRead->members[0]->label);
        $this->assertSame(20000, $familyRead->minimumPriceAmount);
        $this->assertSame(500000, $familyRead->minimumUsdtPriceAmount);
        $this->assertSame($standalone->id, $result->standaloneProducts[0]->id);

        $detail = app(CatalogBrowse::class)->showPublicFamily($family->slug);
        $this->assertSame($family->id, $detail->id);
        $this->assertCount(2, $detail->members);
        $soldOut->forceFill(['stock' => 0, 'reserved_stock' => 0])->save();
        $available->forceFill(['stock' => 0, 'reserved_stock' => 0])->save();
        $soldOutFamily = app(CatalogBrowse::class)->browsePublic(new CatalogBrowseQuery(perPage: 20))->families[0];
        $this->assertFalse($soldOutFamily->isAvailable);
        $this->assertSame(2, $soldOutFamily->soldOutOptionCount);
    }

    public function test_whitespace_only_option_label_falls_back_to_product_name_in_shared_read_models(): void
    {
        $family = ProductFamily::factory()->create(['name' => 'Whitespace Family']);
        $product = Product::factory()->create([
            'name' => 'Canonical Product Name',
            'product_family_id' => $family->id,
            'family_option_label' => " \t\n ",
            'stock' => 1,
            'reserved_stock' => 0,
        ]);

        $member = app(CatalogBrowse::class)->showPublicFamily($family->id)->members[0];

        $this->assertSame($product->name, $member->label);
    }

    public function test_search_collapses_member_matches_category_filters_and_reports_paid_sold_quantity(): void
    {
        $category = Category::factory()->create(['name' => 'Design', 'slug' => 'design']);
        $otherCategory = Category::factory()->create(['name' => 'Office', 'slug' => 'office']);
        $family = ProductFamily::factory()->create(['name' => 'Canva', 'slug' => 'canva']);
        $member = Product::factory()->create([
            'name' => 'Canva Teams',
            'description' => 'Shared design workspace',
            'product_type' => 'Lisensi desain',
            'category_id' => $category->id,
            'product_family_id' => $family->id,
            'family_sort_order' => 3,
            'stock' => 4,
        ]);
        Product::factory()->create([
            'name' => 'Canva Personal',
            'description' => 'Personal design workspace',
            'category_id' => $category->id,
            'product_family_id' => $family->id,
            'family_sort_order' => 1,
            'stock' => 4,
        ]);
        Product::factory()->create(['name' => 'Office Suite', 'category_id' => $otherCategory->id, 'stock' => 4]);
        $order = Order::factory()->create(['order_status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Paid]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $member->id, 'quantity' => 7]);

        $search = app(CatalogBrowse::class)->browsePublic(new CatalogBrowseQuery(search: 'lisensi', perPage: 20));
        $this->assertCount(1, $search->entries);
        $this->assertTrue($search->entries[0]->isFamily());
        $this->assertSame($family->id, $search->entries[0]->family?->id);
        $this->assertSame($member->id, $search->entries[0]->family?->members[0]->id);
        $this->assertSame(7, $search->entries[0]->family?->members[0]->soldQuantity);

        $filtered = app(CatalogBrowse::class)->browsePublic(new CatalogBrowseQuery(category: 'design', perPage: 20));
        $this->assertCount(1, $filtered->entries);
        $this->assertSame($family->id, $filtered->entries[0]->family?->id);
        $this->assertSame('design', $filtered->entries[0]->family?->categorySlug);
    }

    public function test_public_category_options_preserve_storefront_and_product_backed_sets(): void
    {
        $empty = Category::factory()->create([
            'name' => 'Empty Category',
            'slug' => 'empty-category',
            'sort_order' => 1,
        ]);
        $used = Category::factory()->create([
            'name' => 'Used Category',
            'slug' => 'used-category',
            'sort_order' => 2,
        ]);
        $inactive = Category::factory()->create([
            'name' => 'Inactive Category',
            'slug' => 'inactive-category',
            'sort_order' => 3,
            'is_active' => false,
        ]);
        $usedProduct = Product::factory()->create([
            'category_id' => $used->id,
            'name' => 'Visible Used Product',
            'is_active' => true,
        ]);
        Product::factory()->create([
            'category_id' => $inactive->id,
            'name' => 'Hidden Inactive Category Product',
            'is_active' => true,
        ]);

        $catalog = app(CatalogBrowse::class);
        $all = $catalog->publicCategoryOptions();
        $withProductsOnly = $catalog->publicCategoryOptions(withProductsOnly: true);

        $this->assertInstanceOf(CatalogCategoryOptions::class, $all);
        $this->assertSame([$empty->id, $used->id], array_map(
            static fn (CatalogCategoryReadModel $category): int => $category->id,
            $all->categories,
        ));
        $this->assertSame([$used->id], array_map(
            static fn (CatalogCategoryReadModel $category): int => $category->id,
            $withProductsOnly->categories,
        ));
        $this->assertFalse($all->hasUncategorized);
        $this->assertSame([
            'id' => $empty->id,
            'name' => $empty->name,
            'slug' => $empty->slug,
            'sort_order' => $empty->sort_order,
            'description' => $empty->description,
        ], $all->categories[0]->toArray());
        $this->assertSame($usedProduct->category_id, $withProductsOnly->categories[0]->id);
    }

    public function test_public_category_options_report_only_public_uncategorized_products(): void
    {
        $inactive = Product::factory()->create([
            'category_id' => null,
            'is_active' => false,
        ]);
        $this->assertFalse($inactive->is_active);

        $catalog = app(CatalogBrowse::class);
        $this->assertFalse($catalog->publicCategoryOptions()->hasUncategorized);

        $visible = Product::factory()->create([
            'category_id' => null,
            'is_active' => true,
        ]);
        $options = $catalog->publicCategoryOptions();

        $this->assertTrue($options->hasUncategorized);
        $this->assertNull($visible->category_id);
    }

    public function test_find_public_category_accepts_numeric_and_slug_identity_and_rejects_invalid_categories(): void
    {
        $category = Category::factory()->create([
            'name' => 'Streaming',
            'slug' => 'streaming',
            'sort_order' => 4,
        ]);
        $inactive = Category::factory()->create([
            'name' => 'Inactive',
            'slug' => 'inactive',
            'is_active' => false,
        ]);
        $catalog = app(CatalogBrowse::class);

        $byId = $catalog->findPublicCategory($category->id);
        $byNumericString = $catalog->findPublicCategory((string) $category->id);
        $bySlug = $catalog->findPublicCategory(' STREAMING ');

        $this->assertInstanceOf(CatalogCategoryReadModel::class, $byId);
        $this->assertSame($category->id, $byId?->id);
        $this->assertSame($category->id, $byNumericString?->id);
        $this->assertSame($category->slug, $bySlug?->slug);
        $this->assertSame($category->description, $bySlug?->description);
        $this->assertNull($catalog->findPublicCategory($inactive->slug));
        $this->assertNull($catalog->findPublicCategory('missing'));
        $this->assertNull($catalog->findPublicCategory('   '));
    }

    public function test_featured_public_preserves_rank_name_and_id_precedence(): void
    {
        $higherRank = Product::factory()->create([
            'name' => 'Higher Rank',
            'featured_rank' => 2,
            'stock' => 5,
            'reserved_stock' => 0,
        ]);
        $inactiveCategory = Category::factory()->create(['is_active' => false]);
        Product::factory()->create([
            'name' => 'Hidden Product',
            'featured_rank' => 0,
            'stock' => 5,
            'reserved_stock' => 0,
            'is_active' => false,
        ]);
        Product::factory()->create([
            'name' => 'Inactive Category Product',
            'featured_rank' => 0,
            'category_id' => $inactiveCategory->id,
            'stock' => 5,
            'reserved_stock' => 0,
        ]);
        Product::factory()->create([
            'name' => 'Unavailable Product',
            'featured_rank' => 0,
            'stock' => 0,
            'reserved_stock' => 0,
        ]);
        $sameRankFirstId = Product::factory()->create([
            'name' => 'Alpha',
            'featured_rank' => 1,
            'stock' => 5,
            'reserved_stock' => 0,
        ]);
        $sameRankSecondId = Product::factory()->create([
            'name' => 'Alpha',
            'featured_rank' => 1,
            'stock' => 5,
            'reserved_stock' => 0,
        ]);
        $sameRankLaterName = Product::factory()->create([
            'name' => 'Bravo',
            'featured_rank' => 1,
            'stock' => 5,
            'reserved_stock' => 0,
        ]);
        Product::factory()->create([
            'name' => 'Unranked Ready',
            'featured_rank' => null,
            'stock' => 5,
            'reserved_stock' => 0,
        ]);

        $featured = app(CatalogBrowse::class)->featuredPublic();

        $this->assertNotNull($featured);
        $this->assertSame($sameRankFirstId->id, $featured->id);
        $this->assertLessThan($sameRankSecondId->id, $featured->id);
        $this->assertNotSame($sameRankLaterName->id, $featured->id);
        $this->assertSame('Alpha', $featured->name);
    }

    public function test_featured_public_reuses_credential_managed_availability(): void
    {
        $claimed = Product::factory()->create([
            'name' => 'Claimed Credential',
            'featured_rank' => 1,
            'stock' => 5,
            'reserved_stock' => 0,
        ]);
        ProductStockItem::create([
            'product_id' => $claimed->id,
            'order_item_id' => OrderItem::factory()->create(['product_id' => $claimed->id])->id,
            'secret' => 'claimed@example.test|pass',
        ]);
        $available = Product::factory()->create([
            'name' => 'Available Credential',
            'featured_rank' => 2,
            'stock' => 2,
            'reserved_stock' => 0,
        ]);
        ProductStockItem::create([
            'product_id' => $available->id,
            'secret' => 'available@example.test|pass',
        ]);

        $featured = app(CatalogBrowse::class)->featuredPublic();

        $this->assertNotNull($featured);
        $this->assertSame($available->id, $featured->id);
        $this->assertTrue($featured->isAvailable);
        $this->assertSame(1, $featured->availableStock);
        $this->assertArrayNotHasKey('secret', $featured->toArray());
    }

    public function test_owner_can_manage_family_membership_and_delete_family_without_mutating_product_state(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => UserRole::Owner]);
        $category = Category::factory()->create(['name' => 'Streaming']);
        $otherCategory = Category::factory()->create(['name' => 'Design']);
        $product = Product::factory()->create([
            'name' => 'Netflix Individual',
            'category_id' => $category->id,
            'stock' => 8,
            'reserved_stock' => 2,
            'is_active' => true,
        ]);
        $secondProduct = Product::factory()->create(['name' => 'Netflix Family', 'category_id' => $category->id, 'stock' => 4]);
        $conflictingProduct = Product::factory()->create(['name' => 'Canva', 'category_id' => $otherCategory->id]);
        $stockItem = ProductStockItem::create(['product_id' => $product->id, 'secret' => 'family-preserved@example.test|secret']);
        $movement = InventoryMovement::factory()->create(['product_id' => $product->id]);
        $waitlistEmail = 'family-preserved@example.test';
        $waitlist = ProductWaitlistSubscription::create([
            'product_id' => $product->id,
            'email' => $waitlistEmail,
            'email_hash' => app(ProductWaitlistEmailHasher::class)->hash($waitlistEmail),
            'status' => ProductWaitlistSubscription::STATUS_ACTIVE,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.product-families.store'), [
                'name' => '  Netflix  ',
                'description' => '  Pilihan Netflix  ',
                'sort_order' => '2',
                'image' => UploadedFile::fake()->image('netflix.png', 120, 80),
            ])
            ->assertRedirect();
        $family = ProductFamily::query()->where('name', 'Netflix')->firstOrFail();
        $this->assertStringStartsWith('product-families/', $family->image_path);
        Storage::disk('public')->assertExists($family->image_path);

        $this->actingAs($admin)
            ->patch(route('admin.products.family.update', $product), [
                'product_family_id' => $family->id,
                'family_sort_order' => 2,
                'family_option_label' => 'Individual',
            ])
            ->assertRedirect('/admin?tab=produk#product-'.$product->id);
        $this->assertSame($family->id, $product->fresh()->product_family_id);
        $this->assertSame('Individual', $product->fresh()->family_option_label);

        $this->actingAs($admin)
            ->patch(route('admin.products.family.update', $secondProduct), [
                'product_family_id' => $family->id,
                'family_sort_order' => 1,
                'family_option_label' => 'Family',
            ])
            ->assertRedirect();

        $otherFamily = ProductFamily::factory()->create(['name' => 'Netflix Alternative']);
        $this->actingAs($admin)
            ->patch(route('admin.products.family.update', $secondProduct), [
                'product_family_id' => $otherFamily->id,
                'family_sort_order' => 4,
                'family_option_label' => 'Alternative',
            ])
            ->assertRedirect();
        $this->assertSame($otherFamily->id, $secondProduct->fresh()->product_family_id);

        $this->actingAs($admin)
            ->patch(route('admin.products.family.update', $secondProduct), [
                'product_family_id' => null,
                'family_sort_order' => 9,
                'family_option_label' => 'Ignored after unassign',
            ])
            ->assertRedirect();
        $this->assertNull($secondProduct->fresh()->product_family_id);
        $this->assertSame(0, $secondProduct->fresh()->family_sort_order);
        $this->assertNull($secondProduct->fresh()->family_option_label);

        $this->actingAs($admin)
            ->patch(route('admin.products.family.update', $conflictingProduct), [
                'product_family_id' => $family->id,
                'family_sort_order' => 3,
            ])
            ->assertRedirect('/admin?tab=produk#product-'.$conflictingProduct->id)
            ->assertSessionHasErrors('product_family_id', null, 'productFamilyMembership');
        $this->assertNull($conflictingProduct->fresh()->product_family_id);

        $this->actingAs($admin)
            ->delete(route('admin.product-families.destroy', $family), ['confirm_delete' => '1'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSoftDeleted('product_families', ['id' => $family->id]);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'product_family_id' => null,
            'family_sort_order' => 0,
            'family_option_label' => null,
            'stock' => 8,
            'reserved_stock' => 2,
            'is_active' => 1,
        ]);
        $this->assertDatabaseHas('product_waitlist_subscriptions', ['id' => $waitlist->id, 'product_id' => $product->id]);
        $this->assertSame($product->id, $product->fresh()->getKey());
        $this->assertDatabaseHas('product_stock_items', ['id' => $stockItem->id, 'product_id' => $product->id]);
        $this->assertDatabaseHas('inventory_movements', ['id' => $movement->id, 'product_id' => $product->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product_family.created', 'subject_id' => (string) $family->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.family_assigned', 'subject_id' => (string) $product->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product_family.deleted', 'subject_id' => (string) $family->id]);
    }

    public function test_mixed_category_membership_and_product_category_change_fail_without_partial_writes(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $firstCategory = Category::factory()->create(['name' => 'Streaming']);
        $secondCategory = Category::factory()->create(['name' => 'Design']);
        $family = ProductFamily::factory()->create(['name' => 'Netflix']);
        $member = Product::factory()->create(['category_id' => $firstCategory->id, 'product_family_id' => $family->id, 'stock' => 6]);
        $candidate = Product::factory()->create(['category_id' => $secondCategory->id, 'stock' => 4]);
        Product::factory()->create(['category_id' => $firstCategory->id, 'product_family_id' => $family->id, 'family_sort_order' => 2]);

        $this->actingAs($admin)
            ->patch(route('admin.products.family.update', $candidate), [
                'product_family_id' => $family->id,
                'family_sort_order' => 1,
            ])
            ->assertSessionHasErrors('product_family_id', null, 'productFamilyMembership');
        $this->assertNull($candidate->fresh()->product_family_id);

        $payload = [
            'name' => $member->name,
            'price_amount' => (string) $member->price_amount,
            'category_id' => (string) $secondCategory->id,
            'description' => $member->description,
            'is_active' => '1',
        ];
        $this->actingAs($admin)
            ->patch(route('admin.products.update', $member), $payload)
            ->assertRedirect('/admin?tab=produk#product-'.$member->id)
            ->assertSessionHasErrors('category_id', null, 'productUpdate');
        $this->assertSame($firstCategory->id, $member->fresh()->category_id);
    }

    public function test_category_delete_keeps_family_members_coherent_and_only_clears_category_membership(): void
    {

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $category = Category::factory()->create(['name' => 'Streaming']);
        $family = ProductFamily::factory()->create(['name' => 'Netflix']);
        $first = Product::factory()->create(['category_id' => $category->id, 'product_family_id' => $family->id, 'stock' => 8, 'reserved_stock' => 2]);
        $second = Product::factory()->create(['category_id' => $category->id, 'product_family_id' => $family->id, 'stock' => 3, 'reserved_stock' => 1]);

        $this->actingAs($admin)
            ->delete(route('admin.categories.destroy', $category), ['confirm_delete' => '1'])
            ->assertRedirect();

        $this->assertDatabaseHas('products', ['id' => $first->id, 'category_id' => null, 'product_family_id' => $family->id, 'stock' => 8, 'reserved_stock' => 2]);
        $this->assertDatabaseHas('products', ['id' => $second->id, 'category_id' => null, 'product_family_id' => $family->id, 'stock' => 3, 'reserved_stock' => 1]);
        $this->assertSame($family->id, app(CatalogBrowse::class)->showPublicFamily($family->id)->id);
    }

    public function test_family_row_add_action_carries_the_selected_family_assignment_context(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $family = ProductFamily::factory()->create(['name' => 'Target Family']);
        Product::factory()->create(['product_family_id' => $family->id, 'stock' => 1]);
        Product::factory()->create(['name' => 'Standalone Candidate', 'stock' => 1]);

        $this->actingAs($admin)
            ->get('/admin?tab=produk')
            ->assertOk()
            ->assertSee('focusProductFamilyAssignment('.$family->id.')', false);
    }

    public function test_read_only_roles_cannot_mutate_families_or_memberships(): void
    {
        $support = User::factory()->create(['role' => UserRole::Support]);
        $family = ProductFamily::factory()->create();
        $product = Product::factory()->create();

        $this->actingAs($support)
            ->get('/admin?tab=produk')
            ->assertOk()
            ->assertDontSee('Kelola Product Family');
        $this->actingAs($support)
            ->post(route('admin.product-families.store'), ['name' => 'Denied', 'sort_order' => 0])
            ->assertForbidden();
        $this->actingAs($support)
            ->patch(route('admin.products.family.update', $product), ['product_family_id' => $family->id])
            ->assertForbidden();
    }

    public function test_catalog_paginates_family_entries_without_splitting_members_and_supports_uncategorized_filter(): void
    {
        $firstFamily = ProductFamily::factory()->create(['name' => 'A Family', 'sort_order' => 1]);
        $secondFamily = ProductFamily::factory()->create(['name' => 'B Family', 'sort_order' => 2]);
        Product::factory()->create(['product_family_id' => $firstFamily->id, 'family_sort_order' => 1, 'stock' => 2]);
        Product::factory()->create(['product_family_id' => $secondFamily->id, 'family_sort_order' => 1, 'stock' => 2]);
        $standalone = Product::factory()->create(['name' => 'Uncategorized Standalone', 'category_id' => null, 'stock' => 2]);

        $firstPage = app(CatalogBrowse::class)->browsePublic(new CatalogBrowseQuery(perPage: 2, page: 1));
        $secondPage = app(CatalogBrowse::class)->browsePublic(new CatalogBrowseQuery(perPage: 2, page: 2));

        $this->assertSame(3, $firstPage->pagination->total);
        $this->assertSame(2, $firstPage->pagination->lastPage);
        $this->assertCount(2, $firstPage->entries);
        $this->assertCount(1, $secondPage->entries);
        $this->assertSame($standalone->id, $secondPage->standaloneProducts[0]->id);
        $this->assertCount(1, $firstPage->families[0]->members);
        $this->assertCount(1, $firstPage->families[1]->members);

        $uncategorized = app(CatalogBrowse::class)->browsePublic(new CatalogBrowseQuery(category: 'uncategorized', perPage: 20));
        $this->assertSame(3, $uncategorized->pagination->total);
    }

    public function test_admin_dashboard_groups_family_products_and_retains_product_actions(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $family = ProductFamily::factory()->create(['name' => 'Netflix', 'sort_order' => 1]);
        $member = Product::factory()->create(['name' => 'Netflix Individual', 'product_family_id' => $family->id, 'family_sort_order' => 1]);
        $standalone = Product::factory()->create(['name' => 'Standalone Product', 'product_family_id' => null]);

        $this->actingAs($admin)
            ->get('/admin?tab=produk')
            ->assertOk()
            ->assertSee('Product Family')
            ->assertSee('Netflix')
            ->assertSee('Netflix Individual')
            ->assertSee('Standalone Product')
            ->assertSee('Edit family')
            ->assertSee('Simpan grouping')
            ->assertSee(route('admin.products.update', $member), false)
            ->assertSee('Stok');

    }

    public function test_invalid_family_create_reopens_management_modal_with_dedicated_errors(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->from('/admin?tab=produk')
            ->post(route('admin.product-families.store'), [
                'name' => '',
                'description' => str_repeat('x', 1001),
                'sort_order' => '-1',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['name', 'description', 'sort_order'], null, 'productFamilyCreate');

        $this->actingAs($admin)
            ->get('/admin?tab=produk&family_form=create')
            ->assertOk()
            ->assertSee('\u0022familyModalOpen\u0022:true', false);
    }

    public function test_family_update_keeps_slug_replaces_managed_image_and_delete_requires_confirmation(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $family = ProductFamily::factory()->create([
            'name' => 'Old Family',
            'slug' => 'old-family',
            'image_path' => 'product-families/old.webp',
        ]);
        Storage::disk('public')->put('product-families/old.webp', 'old');

        $this->actingAs($admin)
            ->patch(route('admin.product-families.update', $family), [
                'name' => 'New Family',
                'description' => 'Updated description',
                'sort_order' => 7,
                'image' => UploadedFile::fake()->image('new.png', 120, 80),
            ])
            ->assertRedirect();

        $family->refresh();
        $this->assertSame('New Family', $family->name);
        $this->assertSame('old-family', $family->slug);
        $this->assertSame(7, $family->sort_order);
        $this->assertStringStartsWith('product-families/', $family->image_path);
        Storage::disk('public')->assertMissing('product-families/old.webp');
        Storage::disk('public')->assertExists($family->image_path);

        $this->actingAs($admin)
            ->delete(route('admin.product-families.destroy', $family))
            ->assertSessionHasErrors('confirm_delete', null, 'productFamilyDelete');
        $this->assertDatabaseHas('product_families', ['id' => $family->id, 'deleted_at' => null]);
    }
}
