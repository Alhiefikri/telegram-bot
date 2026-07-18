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
            'fake_discount_price' => 85000,
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
            ->assertSee('Bayar QRIS di Website')
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
            ->assertSee('mx-auto max-w-xs grid-cols-1', false)
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
            ->assertSee(route('storefront.shop.index').'#product-'.$product->id, false)
            ->assertSee('Beli via Web QRIS')
            ->assertSee(route('storefront.order-access.create'), false)
            ->assertSee('Akses Pesanan')
            ->assertDontSee('Checkout via Telegram Admin');

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
            ->assertDontSee('Checkout via Telegram Admin');
    }

    private function assertNoCheckoutSideEffects(): void
    {
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, InventoryMovement::count());
    }
}
