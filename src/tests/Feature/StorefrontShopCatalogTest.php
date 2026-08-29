<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Cart;
use App\Models\CheckoutMaintenanceSetting;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Product;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\OrderStateMachine;
use App\Storefront\StorefrontCatalogSearch;
use App\Storefront\StorefrontResponseCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StorefrontShopCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'is_active' => true,
        ]);
    }

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

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('Cached Homepage Product');

        $cacheHitQueries = [];
        DB::listen(static function ($query) use (&$cacheHitQueries): void {
            $cacheHitQueries[] = strtolower($query->sql);
        });

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('Cached Homepage Product');

        $this->assertFalse(
            collect($cacheHitQueries)->contains(fn (string $sql): bool => str_contains($sql, 'products')),
            'A cache hit must not re-query the catalog.',
        );

        Product::factory()->create(['name' => 'Invalidated Homepage Product', 'stock' => 2, 'reserved_stock' => 0]);

        app(StorefrontResponseCache::class)->invalidate();

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('Cached Homepage Product')
            ->assertSee('Invalidated Homepage Product');
    }

    public function test_product_update_invalidates_homepage_once(): void
    {
        $product = Product::factory()->create([
            'name' => 'Single Invalidation Product',
            'stock' => 1,
            'reserved_stock' => 0,
        ]);

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('Single Invalidation Product');
        $versionBeforeUpdate = (int) Cache::get('storefront.response.version');

        $product->update(['name' => 'Renamed Invalidation Product']);

        $this->assertSame($versionBeforeUpdate + 1, (int) Cache::get('storefront.response.version'));
        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('Renamed Invalidation Product')
            ->assertDontSee('Single Invalidation Product');
    }

    public function test_committed_checkout_reservation_invalidates_homepage_cache(): void
    {
        $product = Product::factory()->create([
            'name' => 'Reservation Cache Product',
            'stock' => 1,
            'reserved_stock' => 0,
        ]);

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('Reservation Cache Product')
            ->assertSee('Stok: 1');

        $this->checkoutProduct($product);

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('Reservation Cache Product')
            ->assertSee('Stok kosong')
            ->assertDontSee('Stok: 1');
    }

    public function test_committed_cancellation_release_invalidates_homepage_cache(): void
    {
        $product = Product::factory()->create([
            'name' => 'Cancellation Cache Product',
            'stock' => 1,
            'reserved_stock' => 0,
        ]);
        $order = $this->checkoutProduct($product);

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('Stok kosong');

        app(OrderStateMachine::class)->applyPendingCancellation($order);

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('Cancellation Cache Product')
            ->assertSee('Stok: 1');
    }

    public function test_committed_expiry_release_invalidates_homepage_cache(): void
    {
        $product = Product::factory()->create([
            'name' => 'Expiry Cache Product',
            'stock' => 1,
            'reserved_stock' => 0,
        ]);
        $order = $this->checkoutProduct($product);

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('Stok kosong');

        $order->update(['expires_at' => now()->subMinute()]);
        app(OrderStateMachine::class)->applyPendingExpiry($order->fresh());

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('Expiry Cache Product')
            ->assertSee('Stok: 1');
    }

    public function test_committed_paid_sale_refreshes_homepage_stock_and_sold_projection(): void
    {
        $product = Product::factory()->create([
            'name' => 'Paid Sale Cache Product',
            'stock' => 1,
            'reserved_stock' => 0,
        ]);
        $order = $this->checkoutProduct($product);

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('Stok kosong')
            ->assertSee('Terjual: 0');

        app(OrderStateMachine::class)->applyManualCompletion($order);

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('Paid Sale Cache Product')
            ->assertSee('Stok kosong')
            ->assertSee('Terjual: 1');
    }

    public function test_rolled_back_checkout_does_not_invalidate_homepage_cache(): void
    {
        $product = Product::factory()->create([
            'name' => 'Rollback Cache Product',
            'stock' => 1,
            'reserved_stock' => 0,
        ]);
        $cart = $this->cartForProduct($product);

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('Rollback Cache Product')
            ->assertSee('Stok: 1');

        try {
            DB::transaction(function () use ($cart): void {
                app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::manualQris());

                throw new \RuntimeException('roll back checkout');
            });
            $this->fail('Expected the transaction to roll back.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('roll back checkout', $exception->getMessage());
        }

        $this->assertSame(0, $product->fresh()->reserved_stock);

        $productQueries = 0;
        DB::listen(static function ($query) use (&$productQueries): void {
            if (str_contains(strtolower($query->sql), 'products')) {
                $productQueries++;
            }
        });

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('Rollback Cache Product')
            ->assertSee('Stok: 1');

        $this->assertSame(0, $productQueries, 'A rolled-back transition must retain the cached homepage.');
    }

    public function test_unfiltered_landing_cache_reflects_maintenance_version_changes(): void
    {
        Product::factory()->create(['name' => 'Maintenance Cache Product', 'stock' => 2, 'reserved_stock' => 0]);

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertDontSee('Checkout sedang maintenance');

        CheckoutMaintenanceSetting::query()->firstOrFail()->update([
            'is_active' => true,
            'version' => 1,
            'activated_at' => now(),
        ]);

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('Checkout sedang maintenance');

        CheckoutMaintenanceSetting::query()->firstOrFail()->update([
            'is_active' => false,
            'version' => 2,
            'activated_at' => null,
        ]);

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertDontSee('Checkout sedang maintenance');
    }

    public function test_maintenance_renders_escaped_notice_and_disables_catalog_purchase_surfaces(): void
    {
        $product = Product::factory()->create([
            'name' => 'Maintenance Surface Product',
            'stock' => 2,
            'reserved_stock' => 0,
        ]);
        $note = '<strong>Provider sedang disinkronkan.</strong>';
        CheckoutMaintenanceSetting::query()->firstOrFail()->update([
            'is_active' => true,
            'public_note' => $note,
            'version' => 1,
            'activated_at' => now(),
        ]);

        foreach ([
            route('storefront.home'),
            route('storefront.home', ['q' => 'maintenance surface']),
        ] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('Checkout sedang maintenance')
                ->assertSee($note)
                ->assertDontSee($note, false)
                ->assertDontSee('action="'.route('storefront.shop.checkout').'"', false);
        }

        $this->get(route('storefront.products.show', $product))
            ->assertOk()
            ->assertSee('Checkout sedang maintenance')
            ->assertSee($note)
            ->assertDontSee($note, false)
            ->assertDontSee('action="'.route('storefront.shop.checkout').'"', false);
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
            ->assertSee('storefront-product-grid', false)
            ->assertSee('id="featured-offer"', false)
            ->assertDontSee('Katalog produk digital siap pakai');
    }

    public function test_single_landing_product_is_centered_in_grid(): void
    {
        Product::factory()->create(['name' => 'Produk Tunggal Shop', 'stock' => 1, 'reserved_stock' => 0]);

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('id="storefront-product-grid"', false)
            ->assertSee('storefront-product-grid--single mx-auto max-w-xs', false)
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
            ->assertSee("x-bind:value=\"selectedCheckoutChoice()?.productId || selectedProduct?.id || ''\"", false)
            ->assertSee('name="quantity"', false)
            ->assertSee('x-bind:value="quantity"', false)
            ->assertSee('Nama pembeli')
            ->assertSee('name="buyer_name"', false)
            ->assertSee('x-model.trim="buyerName"', false)
            ->assertSee('required', false)
            ->assertSee('placeholder="Nama pembeli"', false)
            ->assertSee('Checkout tidak perlu akun. Nama ini hanya dipakai untuk menandai invoice Anda.')
            ->assertSee('type="submit"', false)
            ->assertSee('Bayar via QR Manual')
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

    private function checkoutProduct(Product $product): Order
    {
        return app(OrderCheckoutService::class)->checkout(
            $this->cartForProduct($product),
            CheckoutPaymentIntents::manualQris(),
        );
    }

    private function cartForProduct(Product $product): Cart
    {
        $customer = Customer::factory()->create();
        $cartService = app(CartService::class);
        $cart = $cartService->activeCartFor($customer);
        $cartService->setItemQuantity($cart, $product, 1);

        return $cart;
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
