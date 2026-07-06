<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontShopCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_renders_compact_marketplace_grid(): void
    {
        $category = Category::factory()->create(['name' => 'AI Tools', 'sort_order' => 1]);
        Product::factory()->create(['category_id' => $category->id, 'name' => 'Claude Pro Ready', 'stock' => 3, 'reserved_stock' => 0]);
        Product::factory()->create(['category_id' => $category->id, 'name' => 'ChatGPT Plus Ready', 'stock' => 2, 'reserved_stock' => 0]);

        $this->get(route('storefront.shop.index'))
            ->assertOk()
            ->assertViewIs('storefront.shop')
            ->assertViewHasAll([
                'storefront',
                'brand',
                'copy',
                'pageTitle',
                'pageDescription',
                'canonicalUrl',
                'homeUrl',
                'shopUrl',
                'supportHref',
                'supportDestination',
                'categoriesWithProducts',
                'catalogProducts',
                'popularProducts',
                'searchAction',
                'checkoutUrl',
            ])
            ->assertSee('Belanja Akun AI | Lhie Store')
            ->assertSee('Marketplace akun AI siap pakai')
            ->assertSee('Pilih produk, pilih bayar lewat website atau Telegram, lalu ikuti instruksi sampai data akun diterima.')
            ->assertSee('Mulai belanja')
            ->assertSee('Tentang Lhie Store')
            ->assertSee('action="'.route('storefront.shop.index').'#products"', false)
            ->assertSee('class="category-btn', false)
            ->assertSee('data-filter="'.$category->id.'"', false)
            ->assertSee('aria-controls="storefront-product-grid"', false)
            ->assertSee('grid-cols-2 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4', false)
            ->assertDontSee('storefront.partials.featured-offer')
            ->assertDontSee('Apa kata pelanggan')
            ->assertDontSee('Angka operasional');
    }

    public function test_single_shop_product_is_centered_in_grid(): void
    {
        Product::factory()->create(['name' => 'Produk Tunggal Shop', 'stock' => 1, 'reserved_stock' => 0]);

        $this->get(route('storefront.shop.index'))
            ->assertOk()
            ->assertSee('id="storefront-product-grid"', false)
            ->assertSee('mx-auto max-w-xs grid-cols-1', false)
            ->assertDontSee('grid-cols-2 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4', false);
    }

    public function test_available_shop_products_open_existing_modal_with_checkout_payload(): void
    {
        $product = Product::factory()->create([
            'name' => 'CapCut Pro Web',
            'description' => 'Akun Private dari dashboard',
            'price_amount' => 60000,
            'stock' => 8,
            'reserved_stock' => 0,
        ]);

        $this->get(route('storefront.shop.index'))
            ->assertOk()
            ->assertSee('JSON.parse($el.dataset.product)', false)
            ->assertSee('openProductModal(JSON.parse($el.dataset.product))', false)
            ->assertSee('data-product=', false)
            ->assertSee('"id":'.$product->id, false)
            ->assertSee('"productId":'.$product->id, false)
            ->assertSee('"name":"CapCut Pro Web"', false)
            ->assertSee('"description":"Akun Private dari dashboard"', false)
            ->assertSee('"price_amount":60000', false)
            ->assertSee('"stock":8', false)
            ->assertSee('"checkoutUrl":"'.str_replace('/', '\\/', route('storefront.shop.checkout')).'"', false)
            ->assertSee('"paymentUrl":"'.str_replace('/', '\\/', route('storefront.products.payment', $product)).'"', false)
            ->assertDontSee('&amp;quot;', false);
    }

    public function test_unavailable_shop_products_render_disabled_card_without_modal_trigger(): void
    {
        Product::factory()->create([
            'name' => 'ChatGPT Habis Web',
            'stock' => 1,
            'reserved_stock' => 1,
        ]);

        $this->get(route('storefront.shop.index'))
            ->assertOk()
            ->assertSee('ChatGPT Habis Web')
            ->assertSee('aria-disabled="true"', false)
            ->assertSee('STOK HABIS')
            ->assertSee('Restock segera')
            ->assertSee('Stok kosong')
            ->assertDontSee('openProductModal', false)
            ->assertDontSee('JSON.parse($el.dataset.product)', false)
            ->assertDontSee('data-product=', false)
            ->assertDontSee('"checkoutUrl"', false);
    }

    public function test_shop_empty_state_uses_ready_product_copy(): void
    {
        $this->get(route('storefront.shop.index'))
            ->assertOk()
            ->assertSee('Belum ada produk siap dibeli.')
            ->assertSee('Produk aktif akan muncul otomatis setelah admin mempublikasikan stok.');
    }

    public function test_landing_catalog_behavior_remains_unchanged(): void
    {
        Product::factory()->create(['name' => 'Landing Catalog Product', 'stock' => 2, 'reserved_stock' => 0]);

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('Pilih akun AI sesuai kebutuhan.')
            ->assertSee('action="'.url('/').'#products"', false)
            ->assertSee('Landing Catalog Product')
            ->assertSee('JSON.parse($el.dataset.product)', false)
            ->assertDontSee('Marketplace akun AI siap pakai')
            ->assertDontSee('Belum ada produk siap dibeli.');
    }
}
