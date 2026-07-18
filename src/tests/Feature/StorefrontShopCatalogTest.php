<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Storefront\StorefrontCatalogPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontShopCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_renders_compact_marketplace_grid(): void
    {
        $category = Category::factory()->create(['name' => 'AI Tools', 'sort_order' => 1, 'is_active' => true]);
        Product::factory()->create(['category_id' => $category->id, 'name' => 'Claude Pro Ready', 'stock' => 3, 'reserved_stock' => 0, 'is_active' => true]);
        Product::factory()->create(['category_id' => $category->id, 'name' => 'ChatGPT Plus Ready', 'stock' => 2, 'reserved_stock' => 0, 'is_active' => true]);
        Product::factory()->create(['category_id' => $category->id, 'name' => 'ChatGPT Team Restock', 'stock' => 1, 'reserved_stock' => 1, 'is_active' => true]);

        $this->get(route('storefront.shop.index'))
            ->assertOk()
            ->assertViewIs('storefront.shop')
            ->assertViewHas('catalogPage', fn (StorefrontCatalogPage $page) => $page->isShop()
                && $page->productCount() === 3
                && $page->categoryFilters->pluck('name')->all() === ['AI Tools']
                && $page->popularProducts->map(fn ($entry) => $entry->presentation->name)->all() === ['ChatGPT Plus Ready', 'Claude Pro Ready'])
            ->assertSee('Belanja Produk Digital | Lhie Store')
            ->assertSee('Marketplace produk digital siap pakai')
            ->assertSee('Pilih produk, bayar QRIS di website, lalu simpan invoice dan Recovery Key khusus pesanan website untuk akses ulang.')
            ->assertSee('Mulai belanja')
            ->assertSee('Tentang Lhie Store')
            ->assertSee('action="'.route('storefront.shop.index').'#products"', false)
            ->assertSee('Katalog aktif · 3 produk publik')
            ->assertSee('Pilih akun, cek stok, lanjut checkout.')
            ->assertSee('Produk aktif dari Admin Dashboard muncul otomatis di sini. Filter kategori atau cari produk yang siap dibeli.')
            ->assertSee('class="category-btn', false)
            ->assertSee('data-filter="'.$category->id.'"', false)
            ->assertSee('aria-controls="storefront-product-grid"', false)
            ->assertSee('grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4', false)
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
            ->assertDontSee('grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4', false);
    }

    public function test_available_shop_products_open_existing_modal_with_dual_checkout_contract(): void
    {
        $product = Product::factory()->create([
            'name' => 'CapCut Pro Web',
            'description' => 'Akun Private dari dashboard',
            'price_amount' => 60000,
            'stock' => 8,
            'reserved_stock' => 0,
        ]);

        $response = $this->get(route('storefront.shop.index'));

        $response
            ->assertOk()
            ->assertSee('JSON.parse($el.dataset.product)', false)
            ->assertSee('openProductModal(JSON.parse($el.dataset.product))', false)
            ->assertSee('data-product=', false)
            ->assertSee('CapCut Pro Web')
            ->assertSee('Akun Private dari dashboard')
            ->assertSee('Rp60.000')
            ->assertSee('Terjual: 0')
            ->assertSee('Stok: 8')
            ->assertSee('Pilih Checkout')
            ->assertSee('Total produk')
            ->assertSee('method="POST"', false)
            ->assertSee('action="'.route('storefront.shop.checkout').'"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('name="product_id"', false)
            ->assertSee("x-bind:value=\"selectedProduct?.checkout?.websiteQris?.productId || selectedProduct?.id || ''\"", false)
            ->assertSee('name="quantity"', false)
            ->assertSee('x-bind:value="quantity"', false)
            ->assertSee('Nama pembeli')
            ->assertSee('name="buyer_handle"', false)
            ->assertSee('x-model.trim="buyerHandle"', false)
            ->assertSee('required', false)
            ->assertSee('placeholder="@username atau Nama Pembeli"', false)
            ->assertSee('Checkout tidak perlu akun. Nama ini hanya dipakai untuk menandai invoice Anda.')
            ->assertSee('type="submit"', false)
            ->assertSee('Bayar QRIS di Website')
            ->assertDontSee('@click="checkoutManual()"', false)
            ->assertDontSee('Beli lewat Telegram')
            ->assertDontSee('Pakai alur lama lewat bot/admin Telegram. Cocok kalau kamu sudah biasa order dari Telegram.')
            ->assertSee('Stok kosong')
            ->assertDontSee('&amp;quot;', false);
    }

    public function test_unavailable_shop_products_render_disabled_card_without_modal_trigger(): void
    {
        $category = Category::factory()->create(['name' => 'Unavailable Products', 'is_active' => true]);
        Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Claude Ready Web',
            'stock' => 2,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        $unavailableProduct = Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'ChatGPT Habis Web',
            'stock' => 1,
            'reserved_stock' => 1,
            'is_active' => true,
        ]);

        $response = $this->get(route('storefront.shop.index'));
        $unavailableCard = $this->productArticleHtml($response->getContent(), $unavailableProduct);

        $response
            ->assertOk()
            ->assertSee('Claude Ready Web')
            ->assertSee('openProductModal(JSON.parse($el.dataset.product))', false)
            ->assertSee('ChatGPT Habis Web');

        $this->assertStringContainsString('class="product-card', $unavailableCard);
        $this->assertStringContainsString('aria-disabled="true"', $unavailableCard);
        $this->assertStringContainsString('ChatGPT Habis Web', $unavailableCard);
        $this->assertStringContainsString('STOK HABIS', $unavailableCard);
        $this->assertStringContainsString('Restock segera', $unavailableCard);
        $this->assertStringContainsString('Stok kosong', $unavailableCard);
        $this->assertStringNotContainsString('openProductModal', $unavailableCard);
        $this->assertStringNotContainsString('JSON.parse($el.dataset.product)', $unavailableCard);
        $this->assertStringNotContainsString('data-product=', $unavailableCard);
        $this->assertStringNotContainsString('"checkoutUrl"', $unavailableCard);
        $this->assertStringNotContainsString('method="POST"', $unavailableCard);
        $this->assertStringNotContainsString(route('storefront.shop.checkout'), $unavailableCard);
    }

    public function test_shop_empty_state_uses_ready_product_copy(): void
    {
        $this->get(route('storefront.shop.index'))
            ->assertOk()
            ->assertSee('Belum ada produk siap dibeli.')
            ->assertSee('Produk aktif akan muncul otomatis setelah admin mempublikasikan stok.')
            ->assertSee('Katalog aktif · 0 produk publik');
    }

    public function test_landing_catalog_behavior_remains_unchanged(): void
    {
        Product::factory()->create(['name' => 'Landing Catalog Product', 'stock' => 2, 'reserved_stock' => 0]);

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('Pilih produk digital sesuai kebutuhan.')
            ->assertSee('action="'.url('/').'#products"', false)
            ->assertSee('label for="storefront-search" class="sr-only">Cari produk</label>', false)
            ->assertSee('input id="storefront-search"', false)
            ->assertSee('stroke-width="3" aria-hidden="true"', false)
            ->assertSee('id="faq-question-0"', false)
            ->assertSee('aria-controls="faq-answer-0"', false)
            ->assertSee('id="faq-answer-0"', false)
            ->assertSee('role="region" aria-labelledby="faq-question-0"', false)
            ->assertSee('Landing Catalog Product')
            ->assertSee('JSON.parse($el.dataset.product)', false)
            ->assertSee('Produk digital siap pakai, checkout tanpa ribet.')
            ->assertSee('Belanja produk digital dalam tiga langkah.')
            ->assertDontSee('Pilih akun, cek stok, lanjut checkout.')
            ->assertDontSee('Marketplace produk digital siap pakai')
            ->assertDontSee('Belum ada produk siap dibeli.');
    }

    private function productArticleHtml(string $html, Product $product): string
    {
        $start = strpos($html, '<article id="product-'.$product->id.'"');
        $this->assertNotFalse($start, 'Expected unavailable product article for product '.$product->id.' to be rendered.');

        $end = strpos($html, '</article>', $start);
        $this->assertNotFalse($end, 'Expected unavailable product article for product '.$product->id.' to be closed.');

        return substr($html, $start, $end + strlen('</article>') - $start);
    }
}
