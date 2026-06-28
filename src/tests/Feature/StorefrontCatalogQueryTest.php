<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Storefront\StorefrontCatalogQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontCatalogQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_is_trimmed_and_filters_public_products(): void
    {
        Product::factory()->create(['name' => 'Chat GPT Plus', 'description' => 'Premium model access.', 'is_active' => true]);
        Product::factory()->create(['name' => 'Canva Pro', 'description' => 'Design tools.', 'is_active' => true]);

        $catalog = app(StorefrontCatalogQuery::class)->get('  chat  ');

        $this->assertSame('chat', $catalog['search']);
        $this->assertSame(['Chat GPT Plus'], $catalog['products']->pluck('name')->all());
    }

    public function test_products_preserve_visibility_and_storefront_order(): void
    {
        $inactiveCategory = Category::factory()->create(['is_active' => false]);
        Product::factory()->create(['name' => 'Akun Kosong', 'stock' => 1, 'reserved_stock' => 1, 'is_active' => true]);
        Product::factory()->create(['name' => 'Zeta Ready', 'stock' => 1, 'reserved_stock' => 0, 'is_active' => true]);
        Product::factory()->create(['name' => 'Hidden Product', 'is_active' => false]);
        Product::factory()->create(['category_id' => $inactiveCategory->id, 'name' => 'Hidden Category Product', 'is_active' => true]);

        $products = app(StorefrontCatalogQuery::class)->get(null)['products'];

        $this->assertSame(['Zeta Ready', 'Akun Kosong'], $products->pluck('name')->all());
    }

    public function test_categories_preserve_public_limit_and_order(): void
    {
        Category::factory()->create(['name' => 'Hidden', 'slug' => 'hidden', 'sort_order' => 0, 'is_active' => false]);
        foreach (range(1, 9) as $number) {
            Category::factory()->create([
                'name' => sprintf('Category %02d', $number),
                'slug' => sprintf('category-%02d', $number),
                'sort_order' => $number,
                'is_active' => true,
            ]);
        }

        $categories = app(StorefrontCatalogQuery::class)->get(null)['categories'];

        $this->assertCount(8, $categories);
        $this->assertSame([
            'Category 01',
            'Category 02',
            'Category 03',
            'Category 04',
            'Category 05',
            'Category 06',
            'Category 07',
            'Category 08',
        ], $categories->pluck('name')->all());
    }

    public function test_featured_product_is_available_public_product_only(): void
    {
        Product::factory()->create(['name' => 'Akun Habis', 'stock' => 1, 'reserved_stock' => 1, 'is_active' => true]);
        Product::factory()->create(['name' => 'Produk Nonaktif Siap', 'stock' => 5, 'reserved_stock' => 0, 'is_active' => false]);
        Product::factory()->create(['name' => 'Canva Ready', 'stock' => 5, 'reserved_stock' => 0, 'is_active' => true]);

        $featuredProduct = app(StorefrontCatalogQuery::class)->get(null)['featuredProduct'];

        $this->assertNotNull($featuredProduct);
        $this->assertSame('Canva Ready', $featuredProduct->name);
    }

    public function test_storefront_controller_normalizes_array_search_parameters(): void
    {
        Product::factory()->create(['name' => 'Chat GPT Plus', 'description' => 'Premium model access.', 'is_active' => true]);

        $this->get('/?q[]=chat')
            ->assertOk()
            ->assertSee('value=""', false);
    }
}
