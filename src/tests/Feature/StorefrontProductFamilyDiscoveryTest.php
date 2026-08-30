<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontProductFamilyDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_home_and_shop_render_one_family_card_with_standalone_entries(): void
    {
        $category = Category::factory()->create(['slug' => 'streaming', 'name' => 'Streaming']);
        $family = ProductFamily::factory()->create(['slug' => 'netflix', 'name' => 'Netflix']);
        Product::factory()->create([
            'name' => 'Netflix Individual',
            'category_id' => $category->id,
            'product_family_id' => $family->id,
            'family_sort_order' => 2,
            'family_option_label' => 'Individual',
            'stock' => 2,
            'reserved_stock' => 0,
        ]);
        Product::factory()->create([
            'name' => 'Netflix Family',
            'category_id' => $category->id,
            'product_family_id' => $family->id,
            'family_sort_order' => 1,
            'family_option_label' => 'Family',
            'stock' => 0,
            'reserved_stock' => 0,
        ]);
        $standalone = Product::factory()->create(['name' => 'Spotify Standalone', 'stock' => 2, 'reserved_stock' => 0]);

        $shop = $this->get(route('storefront.shop.index'));
        $shop->assertOk()
            ->assertSee('Netflix')
            ->assertSee('Spotify Standalone')
            ->assertSee('data-family-id="'.$family->id.'"', false)
            ->assertSee('2 pilihan produk')
            ->assertSee('1 tersedia');

        $search = $this->get(route('storefront.shop.index', ['q' => 'Netflix Individual', 'category' => 'streaming', 'sort' => 'price_asc', 'page' => 1]));
        $search->assertOk()
            ->assertSee('Netflix')
            ->assertDontSee('Netflix Individual</h3>', false)
            ->assertSee('name="q"', false)
            ->assertSee('value="netflix individual"', false)
            ->assertSee('name="category"', false)
            ->assertSee('value="streaming"', false);
    }

    public function test_family_page_preserves_member_order_labels_product_urls_and_distinct_seo(): void
    {
        $family = ProductFamily::factory()->create([
            'slug' => 'capcut',
            'name' => 'CapCut',
            'description' => 'Pilih paket CapCut sesuai kebutuhan.',
        ]);
        $later = Product::factory()->create([
            'name' => 'CapCut Team 30 Days',
            'product_family_id' => $family->id,
            'family_sort_order' => 2,
            'family_option_label' => 'Team 30 Hari',
            'price_amount' => 35000,
            'stock' => 2,
            'reserved_stock' => 0,
        ]);
        $first = Product::factory()->create([
            'name' => 'CapCut Individual 30 Days',
            'product_family_id' => $family->id,
            'family_sort_order' => 1,
            'family_option_label' => 'Individual 30 Hari',
            'price_amount' => 25000,
            'stock' => 0,
            'reserved_stock' => 0,
        ]);

        $response = $this->get(route('storefront.product-families.show', ['productFamily' => $family->slug]));
        $response->assertOk()
            ->assertSee('<title>CapCut — Pilihan Produk | Lhie Store</title>', false)
            ->assertSee('Pilih paket CapCut sesuai kebutuhan.')
            ->assertSee('Individual 30 Hari')
            ->assertSee('Team 30 Hari')
            ->assertSee('SKU #'.$first->id)
            ->assertSee('SKU #'.$later->id)
            ->assertSee('1 tersedia')
            ->assertSee('Stok habis')
            ->assertSee('data-product-id="'.$first->id.'"', false)
            ->assertSee(route('storefront.products.show', ['product' => $first]), false)
            ->assertSee(route('storefront.products.show', ['product' => $later]), false)
            ->assertSee('from=', false)
            ->assertSee('name="product_id" value="'.$later->id.'"', false);
        $event = AuditLog::query()->where('action', 'storefront.family_opened')->latest('id')->firstOrFail();
        $this->assertSame($family->id, $event->new_values['family_id']);
        $this->assertSame('CapCut', $event->new_values['family_name']);

        $content = $response->getContent();
        $firstPosition = strpos($content, 'Individual 30 Hari');
        $laterPosition = strpos($content, 'Team 30 Hari');
        $this->assertIsInt($firstPosition);
        $this->assertIsInt($laterPosition);
        $this->assertLessThan($laterPosition, $firstPosition);
    }

    public function test_sitemap_contains_public_family_pages_and_sku_urls(): void
    {
        $family = ProductFamily::factory()->create(['slug' => 'youtube', 'name' => 'YouTube']);
        $product = Product::factory()->create(['product_family_id' => $family->id, 'stock' => 1, 'reserved_stock' => 0]);

        $response = $this->get(route('storefront.sitemap'));
        $response->assertOk()
            ->assertSee(route('storefront.product-families.show', ['productFamily' => $family->slug]), false)
            ->assertSee(route('storefront.products.show', ['product' => $product]), false);
    }
}
