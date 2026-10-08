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
use App\Models\ProductFamilySlugRedirect;
use App\Models\ProductStockItem;
use App\Models\ProductWaitlistSubscription;
use App\Models\User;
use App\Services\Admin\AdminDashboardProductsData;
use App\Services\Catalog\CatalogBrowse;
use App\Services\Catalog\CatalogFamilyIconResolver;
use App\Services\ProductWaitlistEmailHasher;
use App\Services\Telegram\TelegramCatalogProjectionCache;
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
        $this->assertTrue(Schema::hasColumns('products', ['product_family_id', 'family_sort_order', 'family_option_label', 'icon']));

        $family = ProductFamily::factory()->create();
        $product = Product::factory()->create([
            'product_family_id' => $family->id,
            'family_sort_order' => 3,
            'family_option_label' => 'Package',
            'icon' => '🧩',
        ]);

        $this->assertSame($family->id, $product->family->id);
        $this->assertSame($family->id, $product->productFamily->id);
        $this->assertSame($product->id, $family->products->sole()->id);
        $this->assertSame(3, $product->family_order);
        $this->assertSame('Package', $product->option_label);
        $this->assertSame('🧩', $product->icon);
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

    public function test_committed_paid_sales_changes_invalidate_the_public_catalog_projection(): void
    {
        $product = Product::factory()->create(['name' => 'Cached Popular Product']);
        $order = Order::factory()->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Failed,
        ]);
        $cache = app(TelegramCatalogProjectionCache::class);
        $cache->remember('browse', ['page' => 1], static fn (): array => ['entries' => []]);
        $version = (int) Cache::get('telegram.catalog.projection.version');

        $order->forceFill(['payment_status' => PaymentStatus::Paid])->save();
        $afterPayment = (int) Cache::get('telegram.catalog.projection.version');
        $this->assertGreaterThan($version, $afterPayment);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 3,
        ]);
        $this->assertGreaterThan($afterPayment, (int) Cache::get('telegram.catalog.projection.version'));
    }

    public function test_admin_can_set_clear_and_keep_the_family_catalog_icon(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $family = ProductFamily::factory()->create(['name' => 'Icon Family', 'slug' => 'icon-family']);

        $this->actingAs($admin)
            ->patch(route('admin.product-families.update', $family), [
                'name' => 'Icon Family',
                'sort_order' => 0,
                'icon' => ' 🎬 ',
            ])
            ->assertRedirect();

        $this->assertSame('🎬', $family->fresh()->icon, 'Glyph family di-trim dan disimpan pada family.');

        $this->actingAs($admin)
            ->patch(route('admin.product-families.update', $family), [
                'name' => 'Icon Family',
                'sort_order' => 0,
                'icon' => '',
            ])
            ->assertRedirect();

        $this->assertNull($family->fresh()->icon, 'Field ikon kosong menghapus glyph family.');

        $family->forceFill(['icon' => '🎬'])->save();
        $this->actingAs($admin)
            ->patch(route('admin.product-families.update', $family), [
                'name' => 'Icon Family Renamed',
                'sort_order' => 0,
            ])
            ->assertRedirect();

        $this->assertSame('🎬', $family->fresh()->icon, 'PATCH tanpa field ikon tidak menghapus ikon tersimpan.');
    }

    public function test_dashboard_family_payload_and_form_expose_the_stored_icon(): void
    {
        User::factory()->create(['role' => UserRole::Admin]);
        $family = ProductFamily::factory()->create([
            'name' => 'Icon Family',
            'slug' => 'icon-family',
            'icon' => '🎬',
        ]);

        $payload = app(AdminDashboardProductsData::class)->forProducts(true);

        $editPayload = $payload['familyManagement']['families'][0]['edit_payload'];
        $this->assertSame($family->id, $editPayload['id']);
        $this->assertSame('🎬', $editPayload['icon']);

        $response = $this->actingAs(User::query()->firstOrFail())
            ->get(route('admin.dashboard', ['tab' => 'produk']))
            ->assertOk();

        $response->assertSee('name="icon"', false);
        $response->assertSee('familyIcon', false);
    }

    public function test_family_members_without_their_own_icon_inherit_the_family_icon(): void
    {
        $family = ProductFamily::factory()->create([
            'name' => 'Icon Inheritance',
            'slug' => 'icon-inheritance',
            'icon' => '🎬',
            'custom_emoji_id' => '5368324170671202286',
        ]);
        $inheriting = Product::factory()->create([
            'name' => 'Inheriting Plan',
            'product_family_id' => $family->id,
            'family_sort_order' => 1,
            'icon' => null,
            'custom_emoji_id' => null,
            'stock' => 2,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        $override = Product::factory()->create([
            'name' => 'Override Plan',
            'product_family_id' => $family->id,
            'family_sort_order' => 2,
            'icon' => '🚀',
            'custom_emoji_id' => null,
            'stock' => 2,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        $standalone = Product::factory()->create([
            'name' => 'Standalone Plan',
            'icon' => '📩',
            'stock' => 2,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $familyRead = app(CatalogBrowse::class)->showPublicFamily($family->slug);
        $this->assertSame('🎬', $familyRead->icon);
        $this->assertSame('5368324170671202286', $familyRead->customEmojiId);

        $members = collect($familyRead->members)->keyBy('id');
        $this->assertSame('🎬', $members[$inheriting->id]->icon);
        $this->assertSame('5368324170671202286', $members[$inheriting->id]->customEmojiId, 'Varian tanpa ikon sendiri mewarisi ikon family.');
        $this->assertSame('🚀', $members[$override->id]->icon, 'Ikon khusus varian menimpa ikon family.');
        $this->assertNull($members[$override->id]->customEmojiId, 'Override menggantikan seluruh pasangan ikon.');
        $standaloneIcon = app(CatalogFamilyIconResolver::class)->forProduct($standalone);
        $this->assertSame('📩', $standaloneIcon->icon, 'Produk tanpa family memakai ikonnya sendiri.');
        $this->assertNull($standaloneIcon->customEmojiId);
    }

    public function test_a_captured_family_emoji_is_never_borrowed_from_a_member_override(): void
    {
        $family = ProductFamily::factory()->create(['name' => 'No Family Icon', 'slug' => 'no-family-icon']);
        Product::factory()->create([
            'name' => 'Member With Own Emoji',
            'product_family_id' => $family->id,
            'family_sort_order' => 1,
            'icon' => '🎬',
            'custom_emoji_id' => '5368324170671202286',
            'stock' => 2,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $familyRead = app(CatalogBrowse::class)->showPublicFamily($family->slug);

        $this->assertNull($familyRead->icon);
        $this->assertNull($familyRead->customEmojiId);
        $this->assertSame('5368324170671202286', $familyRead->members[0]->customEmojiId, 'Ikon varian tetap milik varian itu.');
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

    public function test_public_family_catalog_orders_families_by_total_paid_units_sold(): void
    {
        $mostSold = ProductFamily::factory()->create(['name' => 'Most Sold Family', 'slug' => 'most-sold', 'sort_order' => 30]);
        $middleSold = ProductFamily::factory()->create(['name' => 'Middle Sold Family', 'slug' => 'middle-sold', 'sort_order' => 20]);
        $leastSold = ProductFamily::factory()->create(['name' => 'Least Sold Family', 'slug' => 'least-sold', 'sort_order' => 10]);
        $createProduct = static fn (ProductFamily $family, string $name, int $order, int $stock = 10): Product => Product::factory()->create([
            'name' => $name,
            'product_family_id' => $family->id,
            'family_sort_order' => $order,
            'stock' => $stock,
            'reserved_stock' => 0,
        ]);
        $mostSoldFirst = $createProduct($mostSold, 'Most Sold First Plan', 1, 0);
        $mostSoldSecond = $createProduct($mostSold, 'Most Sold Second Plan', 2, 0);
        $middleSoldProduct = $createProduct($middleSold, 'Middle Sold Plan', 1);
        $leastSoldProduct = $createProduct($leastSold, 'Least Sold Plan', 1);

        $completedPaidOrder = Order::factory()->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $processingPaidOrder = Order::factory()->create([
            'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $failedOrder = Order::factory()->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Failed,
        ]);
        OrderItem::factory()->create(['order_id' => $completedPaidOrder->id, 'product_id' => $mostSoldFirst->id, 'quantity' => 2]);
        OrderItem::factory()->create(['order_id' => $processingPaidOrder->id, 'product_id' => $mostSoldSecond->id, 'quantity' => 2]);
        OrderItem::factory()->create(['order_id' => $completedPaidOrder->id, 'product_id' => $middleSoldProduct->id, 'quantity' => 3]);
        OrderItem::factory()->create(['order_id' => $processingPaidOrder->id, 'product_id' => $leastSoldProduct->id, 'quantity' => 1]);
        OrderItem::factory()->create(['order_id' => $failedOrder->id, 'product_id' => $leastSoldProduct->id, 'quantity' => 100]);

        $firstPage = app(CatalogBrowse::class)->browsePublic(new CatalogBrowseQuery(page: 1, perPage: 2));
        $secondPage = app(CatalogBrowse::class)->browsePublic(new CatalogBrowseQuery(page: 2, perPage: 2));

        $this->assertSame(
            ['Most Sold Family', 'Middle Sold Family'],
            array_map(static fn ($entry): string => $entry->family?->name ?? '', $firstPage->entries),
        );
        $this->assertSame(
            ['Least Sold Family'],
            array_map(static fn ($entry): string => $entry->family?->name ?? '', $secondPage->entries),
        );
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

    public function test_admin_dashboard_groups_every_product_as_a_collapsed_family_and_retains_product_actions(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $family = ProductFamily::factory()->create(['name' => 'Netflix', 'sort_order' => 1]);
        $member = Product::factory()->create([
            'name' => 'Netflix Individual',
            'product_family_id' => $family->id,
            'family_sort_order' => 1,
            'family_option_label' => 'Individual',
        ]);
        $standalone = Product::factory()->create(['name' => 'Standalone Product', 'product_family_id' => null]);

        $this->actingAs($admin)
            ->get('/admin?tab=produk')
            ->assertOk()
            ->assertSee('Daftar Product Family')
            ->assertSee('Netflix')
            ->assertSee('Individual')
            ->assertSee('Standalone Product')
            ->assertSee('admin-product-family-group-product-'.$standalone->id, false)
            ->assertSee('familyExpanded: false', false)
            ->assertDontSee('Product standalone')
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
                'description' => 'Deskripsi keluarga produk.',
                'sort_order' => '-1',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['name', 'sort_order'], null, 'productFamilyCreate');

        $this->actingAs($admin)
            ->get('/admin?tab=produk&family_form=create')
            ->assertOk()
            ->assertSee('\u0022familyModalOpen\u0022:true', false);
    }

    public function test_admin_can_create_family_with_long_unicode_description(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $description = implode(' ', array_fill(0, 30, '🎬 Pilihan paket keluarga produk dengan detail lengkap.'));

        $this->actingAs($admin)
            ->post(route('admin.product-families.store'), [
                'name' => 'Long Description Family',
                'description' => $description,
                'sort_order' => '0',
            ])
            ->assertRedirect();

        $family = ProductFamily::query()->where('name', 'Long Description Family')->firstOrFail();
        $this->assertSame($description, $family->description);
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

    public function test_family_update_slug_writes_durable_alias_for_legacy_public_url(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $family = ProductFamily::factory()->create(['name' => 'Old Family', 'slug' => 'old-family']);
        Product::factory()->create([
            'product_family_id' => $family->id,
            'stock' => 1,
            'reserved_stock' => 0,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.product-families.update', $family), [
                'name' => 'Old Family',
                'slug' => 'new-family',
                'description' => null,
                'sort_order' => 0,
            ])
            ->assertRedirect();

        $family->refresh();
        $this->assertSame('new-family', $family->slug);
        $this->assertDatabaseHas('product_family_slug_redirects', [
            'old_slug' => 'old-family',
            'product_family_id' => $family->id,
        ]);
        $this->get('/produk-keluarga/old-family')
            ->assertStatus(301)
            ->assertRedirect(route('storefront.product-families.show', ['productFamily' => 'new-family']));
    }

    public function test_new_family_creation_skips_reserved_slug_aliases(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $target = ProductFamily::factory()->create(['slug' => 'existing-family']);
        ProductFamilySlugRedirect::query()->create([
            'old_slug' => 'reserved-family',
            'product_family_id' => $target->id,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.product-families.store'), [
                'name' => 'Reserved Family',
                'description' => null,
                'sort_order' => 0,
            ])
            ->assertRedirect();

        $created = ProductFamily::query()->where('name', 'Reserved Family')->sole();
        $this->assertSame('reserved-family-2', $created->slug);
    }
}
