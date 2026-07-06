<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontProductCheckoutTest extends TestCase
{
    use RefreshDatabase;

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
            ->assertSee('Stok: 8')
            ->assertSee('Bayar QRIS di Website')
            ->assertSee('Beli lewat Telegram')
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
            ->assertSee('mx-auto max-w-xs grid-cols-1', false)
            ->assertDontSee('sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4', false);
    }

    public function test_product_detail_exposes_web_and_telegram_choices_without_checkout_side_effects(): void
    {
        config()->set('services.telegram.admin_contact_url', 'https://t.me/lhiestore_support');
        config()->set('services.telegram.admin_username', 'lhiestore_admin');
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
            ->assertSee(route('storefront.shop.index').'#product-'.$product->id, false)
            ->assertSee('Beli via Web QRIS')
            ->assertSee('https://t.me/lhiestore_support', false)
            ->assertSee('Halo+Admin+%40lhiestore_admin%2C+saya+ingin+tanya+ketersediaan+akun+CapCut+Pro+di+Lhie+Store.', false)
            ->assertSee('Beli lewat Telegram')
            ->assertDontSee(route('storefront.products.payment', $product), false);

        $this->assertNoCheckoutSideEffects();
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
            ->assertDontSee(route('storefront.shop.index').'#product-'.$product->id, false)
            ->assertDontSee(route('storefront.products.payment', $product), false);
    }

    public function test_payment_route_is_support_handoff_not_payable_qris_checkout(): void
    {
        $product = Product::factory()->create([
            'name' => 'CapCut Pro',
            'price_amount' => 60000,
            'stock' => 8,
            'reserved_stock' => 0,
        ]);

        $this->get(route('storefront.products.payment', $product).'?qty=2')
            ->assertOk()
            ->assertSee('Checkout via Telegram Admin')
            ->assertSee('CapCut Pro × 2')
            ->assertSee('Estimasi produk')
            ->assertSee('Rp60.000')
            ->assertSee('Rp120.000')
            ->assertSee('Belum ada order dibuat')
            ->assertSee('https://t.me/alhie02?text=', false)
            ->assertSee('Buka Telegram Admin @alhie02')
            ->assertSee('Halo+Admin+%40alhie02%2C+saya+ingin+checkout+CapCut+Pro+x2+via+Telegram%2FAdmin', false)
            ->assertDontSee('Scan QRIS untuk Bayar')
            ->assertDontSee('/qr-lhie.jpg', false)
            ->assertDontSee('Order ID')
            ->assertDontSee('Total Bayar');

        $this->assertNoCheckoutSideEffects();
    }

    public function test_legacy_manual_checkout_url_redirects_to_support_handoff_page(): void
    {
        $product = Product::factory()->create([
            'name' => 'CapCut Pro',
            'stock' => 8,
            'reserved_stock' => 0,
        ]);

        $this->get(route('storefront.products.checkout-manual', $product))
            ->assertRedirect(route('storefront.products.payment', $product));
    }

    public function test_empty_product_payment_redirects_to_detail(): void
    {
        $product = Product::factory()->create([
            'name' => 'Produk Habis',
            'stock' => 0,
            'reserved_stock' => 0,
        ]);

        $this->get(route('storefront.products.payment', $product))
            ->assertRedirect(route('storefront.products.show', $product));
    }

    private function assertNoCheckoutSideEffects(): void
    {
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, InventoryMovement::count());
    }
}
