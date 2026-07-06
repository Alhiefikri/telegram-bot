<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Storefront\StorefrontCatalogPageBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StorefrontProductScenarios;
use Tests\TestCase;

class StorefrontCatalogPageBuilderTest extends TestCase
{
    use RefreshDatabase;
    use StorefrontProductScenarios;

    public function test_shop_context_trims_search_and_filters_public_product_entries(): void
    {
        $this->availableProduct(['name' => 'Chat GPT Plus', 'description' => 'Premium model access.']);
        $this->availableProduct(['name' => 'Canva Pro', 'description' => 'Design tools.']);

        $page = app(StorefrontCatalogPageBuilder::class)->shop('  chat  ');

        $this->assertTrue($page->isShop());
        $this->assertSame('chat', $page->search);
        $this->assertSame(route('storefront.shop.index').'#products', $page->searchAction);
        $this->assertSame(route('storefront.shop.index').'#products', $page->clearSearchUrl);
        $this->assertSame(['Chat GPT Plus'], $page->products->map(fn ($entry) => $entry->presentation->name)->all());
        $this->assertTrue($page->products->first()->presentation->canOpenModal);
    }

    public function test_home_context_exposes_landing_actions_and_empty_state_inputs(): void
    {
        $page = app(StorefrontCatalogPageBuilder::class)->home('  ');

        $this->assertTrue($page->isHome());
        $this->assertSame('', $page->search);
        $this->assertSame(url('/').'#products', $page->searchAction);
        $this->assertSame(url('/').'#products', $page->clearSearchUrl);
        $this->assertSame('Katalog publik sedang disiapkan.', $page->emptyProductsTitle);
        $this->assertSame('Produk populer akan tampil setelah stok aktif tersedia.', $page->popularEmptyMessage);
    }

    public function test_shop_context_exposes_marketplace_empty_state_inputs(): void
    {
        $page = app(StorefrontCatalogPageBuilder::class)->shop(null);

        $this->assertSame('Belum ada produk siap dibeli.', $page->emptyProductsTitle);
        $this->assertSame('Produk aktif akan muncul otomatis setelah admin mempublikasikan stok.', $page->emptyProductsBody);
        $this->assertNull($page->popularEmptyMessage);
    }

    public function test_products_preserve_visibility_and_storefront_order(): void
    {
        $inactiveCategory = Category::factory()->create(['is_active' => false]);
        $this->unavailableProduct(['name' => 'Akun Kosong']);
        $this->availableProduct(['name' => 'Zeta Ready', 'stock' => 1]);
        Product::factory()->create(['name' => 'Hidden Product', 'is_active' => false]);
        Product::factory()->create(['category_id' => $inactiveCategory->id, 'name' => 'Hidden Category Product', 'is_active' => true]);

        $products = app(StorefrontCatalogPageBuilder::class)->home(null)->products;

        $this->assertSame(['Zeta Ready', 'Akun Kosong'], $products->map(fn ($entry) => $entry->presentation->name)->all());
    }

    public function test_category_filters_preserve_public_limit_order_and_only_include_categories_with_products(): void
    {
        Category::factory()->create(['name' => 'Hidden', 'slug' => 'hidden', 'sort_order' => 0, 'is_active' => false]);

        $categories = collect(range(1, 9))->map(fn (int $number) => Category::factory()->create([
            'name' => sprintf('Category %02d', $number),
            'slug' => sprintf('category-%02d', $number),
            'sort_order' => $number,
            'is_active' => true,
        ]));

        $this->availableProduct(['category_id' => $categories[0]->id, 'name' => 'Alpha Ready']);
        $this->availableProduct(['category_id' => $categories[2]->id, 'name' => 'Charlie Ready']);
        $this->availableProduct(['category_id' => $categories[8]->id, 'name' => 'Outside Category Limit']);

        $page = app(StorefrontCatalogPageBuilder::class)->home(null);

        $this->assertTrue($page->hasPublicCategories);
        $this->assertSame(['Category 01', 'Category 03'], $page->categoryFilters->pluck('name')->all());
    }

    public function test_featured_product_is_available_public_product_only(): void
    {
        $this->unavailableProduct(['name' => 'Akun Habis']);
        Product::factory()->create(['name' => 'Produk Nonaktif Siap', 'stock' => 5, 'reserved_stock' => 0, 'is_active' => false]);
        $this->availableProduct(['name' => 'Canva Ready', 'stock' => 5]);

        $featuredProduct = app(StorefrontCatalogPageBuilder::class)->home(null)->featuredProduct;

        $this->assertNotNull($featuredProduct);
        $this->assertSame('Canva Ready', $featuredProduct->presentation->name);
        $this->assertTrue($featuredProduct->presentation->isAvailable);
    }

    public function test_featured_product_skips_credential_managed_product_without_unclaimed_credentials(): void
    {
        $this->credentialManagedProductWithClaimedCredentials(
            secrets: ['claimed@example.test|pass'],
            attributes: [
                'name' => 'Akun Managed Kosong',
                'stock' => 5,
            ],
        );

        $this->availableProduct([
            'name' => 'Canva Ready',
            'stock' => 2,
        ]);

        $featuredProduct = app(StorefrontCatalogPageBuilder::class)->home(null)->featuredProduct;

        $this->assertNotNull($featuredProduct);
        $this->assertSame('Canva Ready', $featuredProduct->presentation->name);
    }

    public function test_products_order_credential_managed_products_by_available_product_invariant(): void
    {
        $this->credentialManagedProductWithClaimedCredentials(
            secrets: ['claimed@example.test|pass'],
            attributes: [
                'name' => 'A Managed Numeric Only',
                'stock' => 5,
            ],
        );
        $this->availableProductWithCredentials(
            secrets: ['ready@example.test|pass'],
            attributes: [
                'name' => 'Z Managed Ready',
                'stock' => 5,
            ],
        );
        $this->unavailableProduct(['name' => 'B Numeric Empty']);

        $products = app(StorefrontCatalogPageBuilder::class)->home(null)->products;

        $this->assertSame(['Z Managed Ready', 'A Managed Numeric Only', 'B Numeric Empty'], $products->map(fn ($entry) => $entry->presentation->name)->all());
    }

    public function test_popular_products_are_prepared_available_entries_from_current_product_list(): void
    {
        $this->availableProduct(['name' => 'Alpha Ready', 'stock' => 2]);
        $this->availableProduct(['name' => 'Bravo Ready', 'stock' => 2]);
        $this->availableProduct(['name' => 'Charlie Ready', 'stock' => 2]);
        $this->availableProduct(['name' => 'Delta Ready', 'stock' => 2]);
        $this->unavailableProduct(['name' => 'Echo Empty']);

        $page = app(StorefrontCatalogPageBuilder::class)->home(null);

        $this->assertSame(['Alpha Ready', 'Bravo Ready', 'Charlie Ready'], $page->popularProducts->map(fn ($entry) => $entry->presentation->name)->all());
        $this->assertSame([
            'Alpha Ready' => true,
            'Bravo Ready' => true,
            'Charlie Ready' => true,
            'Delta Ready' => false,
            'Echo Empty' => false,
        ], $page->products->mapWithKeys(fn ($entry) => [$entry->presentation->name => $entry->isPopular])->all());
    }

    public function test_storefront_controller_normalizes_array_search_parameters(): void
    {
        $this->availableProduct(['name' => 'Chat GPT Plus', 'description' => 'Premium model access.']);

        $this->get('/?q[]=chat')
            ->assertOk()
            ->assertSee('value=""', false);
    }
}
