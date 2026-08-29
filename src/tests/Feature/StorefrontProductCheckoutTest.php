<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontProductCheckoutTest extends TestCase
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

    public function test_catalog_product_cards_open_modal_with_checkout_choices(): void
    {
        Product::factory()->create([
            'name' => 'CapCut Pro',
            'description' => 'Akun Private dari dashboard',
            'price_amount' => 60000,
            'stock' => 8,
            'reserved_stock' => 0,
        ]);
        Product::factory()->create(['name' => 'ChatGPT Habis', 'stock' => 1, 'reserved_stock' => 1]);

        $this->get('/')
            ->assertOk()
            ->assertSee('JSON.parse($el.dataset.product)', false)
            ->assertSee('CapCut Pro')
            ->assertSee('Akun Private dari dashboard')
            ->assertSee('Rp60.000')
            ->assertDontSee('Rp85.000')
            ->assertDontSee('line-through', false)
            ->assertSee('Stok: 8')
            ->assertSee('Bayar via QR Manual')
            ->assertSee('data-storefront-qris-mark', false)
            ->assertSee('Bayar via QR Manual', false)
            ->assertDontSee('Beli lewat Telegram')
            ->assertDontSee('&amp;quot;', false)
            ->assertSee('Habis');
    }

    public function test_single_catalog_product_is_centered_in_grid(): void
    {
        Product::factory()->create([
            'name' => 'Produk Tunggal',
            'stock' => 1,
            'reserved_stock' => 0,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('id="storefront-product-grid"', false)
            ->assertSee('storefront-product-grid--single mx-auto max-w-xs', false)
            ->assertDontSee('sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4', false);
    }

    public function test_product_detail_exposes_web_checkout_and_order_access_without_checkout_side_effects(): void
    {
        $product = Product::factory()->create([
            'name' => 'CapCut Pro',
            'description' => 'Akun Private',
            'price_amount' => 60000,
            'stock' => 8,
            'reserved_stock' => 0,
        ]);

        $this->get(route('storefront.products.show', $product))
            ->assertOk()
            ->assertSee('CapCut Pro')
            ->assertSee('Rp60.000')
            ->assertSee('Stok: 8')
            ->assertSee(route('storefront.products.show', ['product' => $product, 'checkout' => 1]), false)
            ->assertSee('data-checkout-restore=', false)
            ->assertSee('data-product=', false)
            ->assertSee('x-on:click.prevent="openProductModal', false)
            ->assertSee('name="product_id" value="'.$product->id.'"', false)
            ->assertSee('Bayar via QR Manual')
            ->assertSee(route('storefront.order-access.create'), false)
            ->assertSee('Akses Pesanan')
            ->assertDontSee('Checkout via Telegram Admin');

        $this->assertNoCheckoutSideEffects();
    }

    public function test_product_detail_shows_safe_unavailable_state_without_payment_setting(): void
    {
        ManualPaymentSetting::query()->delete();
        $product = Product::factory()->create([
            'name' => 'No Provider Product',
            'stock' => 2,
            'reserved_stock' => 0,
        ]);

        $this->get(route('storefront.products.show', $product))
            ->assertOk()
            ->assertSee('Checkout belum tersedia')
            ->assertSee('Checkout pembayaran belum aktif. Hubungi admin atau coba lagi nanti.')
            ->assertDontSee('Beli via Web QRIS')
            ->assertDontSee('data-checkout-product=', false)
            ->assertDontSee('x-on:click.prevent="openProductModal', false);
    }

    public function test_landing_hides_checkout_actions_without_payment_setting(): void
    {
        ManualPaymentSetting::query()->delete();
        $product = Product::factory()->create([
            'name' => 'No Provider Landing Product',
            'stock' => 2,
            'reserved_stock' => 0,
        ]);

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('Checkout belum tersedia')
            ->assertDontSee('data-product=', false)
            ->assertDontSee('action="'.route('storefront.shop.checkout').'"', false)
            ->assertDontSee('Bayar via QR Manual')
            ->assertDontSee('Bayar via KlikQRIS');
    }

    public function test_product_detail_disables_native_qris_when_klikqris_readiness_fails(): void
    {
        ManualPaymentSetting::current()->update(['payment_mode' => 'klikqris']);
        config()->set('services.klikqris.sandbox_enabled', false);
        config()->set('services.klikqris.production_enabled', false);
        $product = Product::factory()->create([
            'name' => 'KlikQRIS Unready Product',
            'stock' => 2,
            'reserved_stock' => 0,
        ]);

        $this->get(route('storefront.products.show', $product))
            ->assertOk()
            ->assertSee('KlikQRIS belum siap')
            ->assertSee('QRIS otomatis KlikQRIS sedang belum siap. Coba lagi nanti atau hubungi admin.')
            ->assertDontSee('Bayar QRIS di Website')
            ->assertDontSee('data-checkout-product=', false)
            ->assertDontSee('x-on:click.prevent="openProductModal', false);
    }

    public function test_product_detail_back_navigation_preserves_catalog_search_context(): void
    {
        $product = Product::factory()->create([
            'name' => 'Search Context Product',
            'stock' => 2,
            'reserved_stock' => 0,
        ]);
        $returnUrl = url('/?q=search#product-'.$product->id);

        $this->get(route('storefront.home', ['q' => 'search']))
            ->assertOk()
            ->assertSee('href="'.route('storefront.products.show', ['product' => $product]).'?from='.urlencode($returnUrl).'"', false);

        $this->get(route('storefront.products.show', ['product' => $product, 'from' => $returnUrl]))
            ->assertOk()
            ->assertSee('href="'.$returnUrl.'"', false)
            ->assertSee('Kembali ke katalog');
    }

    public function test_product_detail_disables_buy_button_for_empty_product(): void
    {
        $product = Product::factory()->create([
            'name' => 'Kiro PRO',
            'stock' => 1,
            'reserved_stock' => 1,
        ]);

        $this->get(route('storefront.products.show', $product))
            ->assertOk()
            ->assertSee('Stok habis')
            ->assertSee('Restock segera')
            ->assertDontSee(route('storefront.products.show', ['product' => $product, 'checkout' => 1]), false)
            ->assertDontSee('data-checkout-product=', false)
            ->assertDontSee('Checkout via Telegram Admin');
    }

    private function assertNoCheckoutSideEffects(): void
    {
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, InventoryMovement::count());
    }
}
