<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Storefront\StorefrontCatalogSearch;
use App\Storefront\StorefrontResponseCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StorefrontShopCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_redirects_to_the_landing_catalog_surface(): void
    {
        $this->get(route('storefront.shop.index'))
            ->assertRedirect(url('/#products'));

        $this->get(route('storefront.shop.index', ['q' => '  CHAT   GPT  ']))
            ->assertRedirect(url('/?q=chat%20gpt#products'));
    }

    public function test_landing_search_is_normalized_bounded_and_case_appropriate(): void
    {
        Product::factory()->create(['name' => 'Chat GPT Plus Ready', 'description' => 'Akun premium AI', 'stock' => 2, 'reserved_stock' => 0]);
        Product::factory()->create(['name' => 'Claude Pro Ready', 'description' => 'Akun premium AI', 'stock' => 2, 'reserved_stock' => 0]);

        $this->get(route('storefront.home', ['q' => '  CHAT   GPT  ']))
            ->assertOk()
            ->assertSee('value="chat gpt"', false)
            ->assertSee('maxlength="'.StorefrontCatalogSearch::MAX_LENGTH.'"', false)
            ->assertSee('Chat GPT Plus Ready')
            ->assertDontSee('Claude Pro Ready');

        $queryCount = 0;
        DB::listen(static function () use (&$queryCount): void {
            $queryCount++;
        });

        $this->get(route('storefront.home', ['q' => str_repeat('a', StorefrontCatalogSearch::MAX_LENGTH + 1)]))
            ->assertStatus(422);

        $this->assertSame(0, $queryCount, 'Over-limit search input should fail before catalog queries run.');
    }

    public function test_search_requests_are_not_stored_as_full_page_cache_entries(): void
    {
        Product::factory()->create(['name' => 'Chat GPT Search Cache Probe', 'description' => 'Akun premium AI', 'stock' => 2, 'reserved_stock' => 0]);

        $queryCount = 0;
        DB::listen(static function () use (&$queryCount): void {
            $queryCount++;
        });

        $this->get(route('storefront.home', ['q' => 'chat gpt']))
            ->assertOk()
            ->assertSee('Chat GPT Search Cache Probe');
        $firstSearchQueries = $queryCount;

        $queryCount = 0;

        $this->get(route('storefront.home', ['q' => ' CHAT   GPT ']))
            ->assertOk()
            ->assertSee('Chat GPT Search Cache Probe');

        $this->assertGreaterThan(0, $firstSearchQueries);
        $this->assertGreaterThan(0, $queryCount, 'Equivalent search requests should be normalized but still render live instead of using a full-page cache row.');
    }

    public function test_unfiltered_landing_cache_retains_hits_and_invalidation(): void
    {
        Product::factory()->create(['name' => 'Cached Homepage Product', 'stock' => 2, 'reserved_stock' => 0]);

        $queryCount = 0;
        DB::listen(static function () use (&$queryCount): void {
            $queryCount++;
        });

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('Cached Homepage Product');
        $firstHomepageQueries = $queryCount;

        $queryCount = 0;

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('Cached Homepage Product');

        $this->assertGreaterThan(0, $firstHomepageQueries);
        $this->assertSame(0, $queryCount, 'The unfiltered homepage should keep serving the cached HTML on a cache hit.');

        Product::factory()->create(['name' => 'Invalidated Homepage Product', 'stock' => 2, 'reserved_stock' => 0]);
        app(StorefrontResponseCache::class)->invalidate();

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('Cached Homepage Product')
            ->assertSee('Invalidated Homepage Product');
    }

    public function test_landing_renders_single_catalog_grid(): void
    {
        $category = Category::factory()->create(['name' => 'AI Tools', 'sort_order' => 1, 'is_active' => true]);
        Product::factory()->create(['category_id' => $category->id, 'name' => 'Claude Pro Ready', 'stock' => 3, 'reserved_stock' => 0, 'is_active' => true]);
        Product::factory()->create(['category_id' => $category->id, 'name' => 'ChatGPT Plus Ready', 'stock' => 2, 'reserved_stock' => 0, 'is_active' => true]);
        Product::factory()->create(['category_id' => $category->id, 'name' => 'ChatGPT Team Restock', 'stock' => 1, 'reserved_stock' => 1, 'is_active' => true]);

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('Lhie Store | Akun Premium &amp; Produk Digital', false)
            ->assertSee('Pilih produk digital sesuai kebutuhan.')
            ->assertSee('action="'.url('/#products').'"', false)
            ->assertSee('Katalog aktif · 3 produk publik')
            ->assertSee('class="category-btn', false)
            ->assertSee('data-filter="'.$category->id.'"', false)
            ->assertSee('aria-controls="storefront-product-grid"', false)
            ->assertSee('grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4', false)
            ->assertSee('id="featured-offer"', false)
            ->assertDontSee('Katalog produk digital siap pakai');
    }

    public function test_single_landing_product_is_centered_in_grid(): void
    {
        Product::factory()->create(['name' => 'Produk Tunggal Shop', 'stock' => 1, 'reserved_stock' => 0]);

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('id="storefront-product-grid"', false)
            ->assertSee('mx-auto max-w-xs grid-cols-1', false)
            ->assertDontSee('sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4', false);
    }

    public function test_available_landing_products_open_existing_modal_with_dual_checkout_contract(): void
    {
        $product = Product::factory()->create([
            'name' => 'CapCut Pro Web',
            'description' => 'Akun Private dari dashboard',
            'price_amount' => 60000,
            'stock' => 8,
            'reserved_stock' => 0,
        ]);

        $response = $this->get(route('storefront.home'));

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
            ->assertSee('name="buyer_name"', false)
            ->assertSee('x-model.trim="buyerName"', false)
            ->assertSee('required', false)
            ->assertSee('placeholder="Nama pembeli"', false)
            ->assertSee('Checkout tidak perlu akun. Nama ini hanya dipakai untuk menandai invoice Anda.')
            ->assertSee('type="submit"', false)
            ->assertSee('Bayar QRIS di Website')
            ->assertDontSee('@click="checkoutManual()"', false)
            ->assertDontSee('Beli lewat Telegram')
            ->assertDontSee('Pakai alur lama lewat bot/admin Telegram. Cocok kalau kamu sudah biasa order dari Telegram.')
            ->assertSee('Stok kosong')
            ->assertDontSee('&amp;quot;', false);
    }

    public function test_unavailable_landing_products_render_disabled_card_without_modal_trigger(): void
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

        $response = $this->get(route('storefront.home'));
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

    public function test_landing_empty_state_uses_catalog_copy(): void
    {
        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('Katalog publik sedang disiapkan.')
            ->assertSee('Produk aktif akan tampil otomatis di sini setelah dipublikasikan dari admin.')
            ->assertSee('Katalog aktif · 0 produk publik');
    }

    public function test_landing_catalog_behavior_remains_unchanged(): void
    {
        Product::factory()->create(['name' => 'Landing Catalog Product', 'stock' => 2, 'reserved_stock' => 0]);

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('Pilih produk digital sesuai kebutuhan.')
            ->assertSee('action="'.url('/#products').'"', false)
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
            ->assertDontSee('Katalog produk digital siap pakai')
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
