<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontProductCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_product_cards_open_modal_with_database_payload(): void
    {
        $available = Product::factory()->create([
            'name' => 'CapCut Pro',
            'description' => 'Akun Private dari dashboard',
            'price_amount' => 60000,
            'stock' => 8,
            'reserved_stock' => 0,
        ]);
        $empty = Product::factory()->create(['name' => 'ChatGPT Habis', 'stock' => 1, 'reserved_stock' => 1]);

        $this->get('/')
            ->assertOk()
            ->assertSee('JSON.parse($el.dataset.product)', false)
            ->assertSee('"name":"CapCut Pro"', false)
            ->assertSee('"description":"Akun Private dari dashboard"', false)
            ->assertSee('"price_amount":60000', false)
            ->assertSee('"stock":8', false)
            ->assertSee('"paymentUrl":"'.str_replace('/', '\\/', route('storefront.products.payment', $available)).'"', false)
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

    public function test_product_detail_shows_buy_button_for_available_product(): void
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
            ->assertSee(route('storefront.products.payment', $product), false);
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
            ->assertDontSee(route('storefront.products.payment', $product), false);
    }

    public function test_payment_page_uses_static_lhie_qris_and_total_amount(): void
    {
        $product = Product::factory()->create([
            'name' => 'CapCut Pro',
            'price_amount' => 60000,
            'stock' => 8,
            'reserved_stock' => 0,
        ]);

        $this->get(route('storefront.products.payment', $product).'?qty=2')
            ->assertOk()
            ->assertSee('Scan QRIS untuk Bayar')
            ->assertSee('/qr-lhie.jpg', false)
            ->assertSee('CapCut Pro × 2')
            ->assertSee('Harga satuan')
            ->assertSee('Rp60.000')
            ->assertSee('Rp120.000')
            ->assertSee('+ Rp401')
            ->assertSee('Rp120.401')
            ->assertSee('Menunggu pembayaran')
            ->assertSee('Hubungi Admin @alhie02')
            ->assertSee('https://t.me/alhie02?text=', false)
            ->assertSee('CapCut Pro');
    }

    public function test_legacy_manual_checkout_url_redirects_to_qris_payment_page(): void
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
}
