<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

    public function test_catalog_product_cards_use_page_checkout_without_embedded_checkout_payload(): void
    {
        $product = Product::factory()->create([
            'name' => 'CapCut Pro',
            'description' => 'Akun Private dari dashboard',
            'price_amount' => 60000,
            'stock' => 8,
            'reserved_stock' => 0,
        ]);
        Product::factory()->create(['name' => 'ChatGPT Habis', 'stock' => 1, 'reserved_stock' => 1]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('href="'.route('storefront.products.show', $product).'?from=', $html);
        $this->assertStringContainsString('href="'.route('storefront.shop.checkout.product', $product).'?from=', $html);
        $this->assertStringNotContainsString('data-product=', $html);
        $this->assertStringContainsString('CapCut Pro', $html);
        $this->assertStringContainsString('Akun Private dari dashboard', $html);
        $this->assertStringContainsString('Rp60.000', $html);
        $this->assertStringNotContainsString('Rp85.000', $html);
        $this->assertStringNotContainsString('line-through', $html);
        $this->assertStringContainsString('Stok: 8', $html);
        $this->assertStringContainsString('Beli sekarang', $html);
        $this->assertStringContainsString('+ Keranjang', $html);
        $this->assertStringNotContainsString('Bayar via QR Manual', $html);
        $this->assertStringNotContainsString('Beli lewat Telegram', $html);
        $this->assertStringNotContainsString('&amp;quot;', $html);
        $this->assertStringNotContainsString('POPULER', $html);
        $this->assertStringNotContainsString('Best', $html);
        $this->assertStringContainsString('Habis', $html);
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

    public function test_product_detail_exposes_page_checkout_cart_and_order_access_without_checkout_side_effects(): void
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
            ->assertSee(route('storefront.shop.checkout.product', $product), false)
            ->assertSee('Beli sekarang')
            ->assertSee('name="product_id" value="'.$product->id.'"', false)
            ->assertSee('Tambah ke keranjang')
            ->assertSee(route('storefront.order-access.create'), false)
            ->assertSee('Akses Pesanan')
            ->assertDontSee('data-checkout-product=', false)
            ->assertDontSee('data-product=', false)
            ->assertDontSee('Checkout via Telegram Admin');

        $this->assertNoCheckoutSideEffects();
    }

    public function test_retired_product_modal_route_returns_not_found(): void
    {
        $product = Product::factory()->create([
            'stock' => 2,
            'reserved_stock' => 0,
        ]);

        $this->get('/produk/'.$product->id.'/modal')->assertNotFound();
    }

    public function test_landing_keeps_page_checkout_link_without_payment_setting(): void
    {
        ManualPaymentSetting::query()->delete();
        $product = Product::factory()->create([
            'name' => 'No Provider Landing Product',
            'stock' => 2,
            'reserved_stock' => 0,
        ]);

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('No Provider Landing Product')
            ->assertSee('Beli sekarang')
            ->assertSee(route('storefront.shop.checkout.product', $product), false)
            ->assertDontSee('data-product=', false)
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
            ->assertDontSee('data-product=', false);
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
            ->assertSee(route('storefront.products.show', ['product' => $product]), false)
            ->assertSee('from=', false);

        $this->get(route('storefront.products.show', ['product' => $product, 'from' => $returnUrl]))
            ->assertOk()
            ->assertSee('href="'.$returnUrl.'"', false)
            ->assertSee('Kembali ke katalog');
    }

    public function test_shop_product_detail_back_navigation_preserves_full_catalog_context(): void
    {
        foreach (range(1, 25) as $number) {
            Product::factory()->create([
                'name' => sprintf('Shop Return Product %02d', $number),
                'stock' => 2,
                'reserved_stock' => 0,
            ]);
        }
        $product = Product::query()->where('name', 'Shop Return Product 25')->firstOrFail();
        $returnUrl = route('storefront.shop.index', ['page' => 2]).'#product-'.$product->id;

        $this->get(route('storefront.shop.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('Shop Return Product 25')
            ->assertSee(
                'href="'.route('storefront.products.show', ['product' => $product]).'?from='.urlencode($returnUrl).'"',
                false,
            );

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
            ->assertDontSee(route('storefront.shop.checkout.product', ['product' => $product]), false)
            ->assertDontSee('data-checkout-product=', false)
            ->assertDontSee('data-product=', false)
            ->assertDontSee('Checkout via Telegram Admin');
    }

    public function test_product_detail_renders_complete_merchandising_without_private_fulfillment_data(): void
    {
        $product = Product::factory()->create([
            'name' => 'Detail Lengkap',
            'description' => "Akun premium siap pakai.\nBaca informasi sebelum checkout.",
            'product_type' => 'Akun premium',
            'access_duration' => '30 hari',
            'region' => 'Indonesia',
            'account_type' => 'Private',
            'device_limit' => '1 perangkat',
            'included_items' => "Akun siap pakai\nPanduan login",
            'limitations' => "Tidak untuk dijual kembali\nSatu perangkat",
            'fulfillment_expectation' => 'Dikirim setelah pembayaran terverifikasi.',
            'warranty_policy' => 'Garansi login 7 hari.',
            'support_policy' => 'Support pada jam operasional.',
            'delivery_note' => 'PRIVATE DELIVERY NOTE',
            'stock' => 2,
            'reserved_stock' => 0,
        ]);
        ProductStockItem::create([
            'product_id' => $product->id,
            'secret' => 'private-credential@example.test|private-password',
        ]);

        $html = $this->get(route('storefront.products.show', $product))
            ->assertOk()
            ->assertSee('Akun premium siap pakai.')
            ->assertSee('Baca informasi sebelum checkout.')
            ->assertSee('Detail produk')
            ->assertSee('Tipe produk')
            ->assertSee('Akun premium')
            ->assertSee('Durasi akses')
            ->assertSee('30 hari')
            ->assertSee('Region / negara')
            ->assertSee('Indonesia')
            ->assertSee('Tipe akun')
            ->assertSee('Private')
            ->assertSee('Batas perangkat / seat')
            ->assertSee('1 perangkat')
            ->assertSee('Yang termasuk')
            ->assertSee('Panduan login')
            ->assertSee('Batasan penggunaan')
            ->assertSee('Tidak untuk dijual kembali')
            ->assertSee('Ekspektasi pemenuhan')
            ->assertSee('Dikirim setelah pembayaran terverifikasi.')
            ->assertSee('Kebijakan garansi')
            ->assertSee('Garansi login 7 hari.')
            ->assertSee('Dukungan pelanggan')
            ->assertSee('Support pada jam operasional.')
            ->getContent();

        $this->assertStringNotContainsString('PRIVATE DELIVERY NOTE', $html);
        $this->assertStringNotContainsString('private-credential@example.test', $html);
        $this->assertStringNotContainsString('private-password', $html);
    }

    public function test_direct_checkout_review_is_canonical_bounded_private_and_side_effect_free(): void
    {
        $product = Product::factory()->create([
            'name' => 'Canonical Direct Review',
            'price_amount' => 60000,
            'stock' => 4,
            'reserved_stock' => 0,
        ]);
        Http::fake();
        Queue::fake();

        $response = $this->get(route('storefront.shop.checkout.product', [
            'product' => $product,
            'quantity' => 999,
        ]));

        $response
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSee('<meta name="robots" content="noindex, nofollow, noarchive">', false)
            ->assertSee('Canonical Direct Review')
            ->assertSee('action="'.route('storefront.shop.checkout').'?from=', false)
            ->assertSee('name="product_id" value="'.$product->id.'"', false)
            ->assertSee('name="quantity"', false)
            ->assertSee('max="4"', false)
            ->assertSee('value="4"', false)
            ->assertSee('name="terms_accepted"', false);

        $this->assertSame(0, Customer::query()->count());
        $this->assertSame(0, Cart::query()->count());
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, InventoryMovement::query()->count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_direct_review_does_not_probe_sakurupiah_provider(): void
    {
        ManualPaymentSetting::current()->update([
            'payment_mode' => 'sakurupiah',
            'is_active' => true,
        ]);
        $product = Product::factory()->create([
            'stock' => 2,
            'reserved_stock' => 0,
        ]);
        Http::fake();

        $this->get(route('storefront.shop.checkout.product', ['product' => $product]))
            ->assertOk();

        Http::assertNothingSent();
    }

    public function test_hidden_or_inactive_product_cannot_open_direct_checkout_review(): void
    {
        $product = Product::factory()->create([
            'is_active' => false,
            'stock' => 2,
            'reserved_stock' => 0,
        ]);

        $this->get(route('storefront.shop.checkout.product', ['product' => $product]))
            ->assertNotFound();
    }

    public function test_public_unavailable_product_has_no_direct_submit_form(): void
    {
        $product = Product::factory()->create([
            'name' => 'Unavailable Direct Review',
            'stock' => 1,
            'reserved_stock' => 1,
        ]);

        $this->get(route('storefront.shop.checkout.product', ['product' => $product, 'quantity' => 20]))
            ->assertOk()
            ->assertSee('Unavailable Direct Review')
            ->assertSee('Stok produk berubah menjadi 0.')
            ->assertDontSee('Buat satu order dan lanjut bayar')
            ->assertDontSee('action="'.route('storefront.shop.checkout').'"', false)
            ->assertDontSee('name="product_id"', false);
    }

    public function test_direct_checkout_quantity_is_normalized_to_one_for_non_positive_input(): void
    {
        $product = Product::factory()->create([
            'stock' => 30,
            'reserved_stock' => 0,
        ]);

        $this->get(route('storefront.shop.checkout.product', [
            'product' => $product,
            'quantity' => 0,
        ]))
            ->assertOk()
            ->assertSee('max="20"', false)
            ->assertSee('value="1"', false);
    }

    public function test_direct_checkout_review_uses_compact_document_flow_and_single_payment_summary(): void
    {
        $product = Product::factory()->create([
            'name' => 'Compact Direct Review',
            'description' => 'LONG MERCHANDISING DESCRIPTION SHOULD STAY OUT OF REVIEW',
            'price_amount' => 60000,
            'stock' => 4,
            'reserved_stock' => 0,
        ]);

        $html = $this->get(route('storefront.shop.checkout.product', ['product' => $product]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('role="dialog"', $html);
        $this->assertStringNotContainsString('data-storefront-checkout-scroll', $html);
        $this->assertStringContainsString('Tambahkan email atau kontak bantuan', $html);
        $this->assertStringContainsString('data-checkout-quantity-announcement', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertSame(
            0,
            preg_match('/<details id="checkout-contact-details"[^>]*\bopen\b/', $html),
        );
        $this->assertStringContainsString('type="hidden" name="payment_method" value="qris"', $html);
        $this->assertStringNotContainsString('type="radio" name="payment_method"', $html);
        $this->assertStringNotContainsString('LONG MERCHANDISING DESCRIPTION SHOULD STAY OUT OF REVIEW', $html);

        $this->assertTrue(
            strpos($html, 'Produk yang dipilih') < strpos($html, 'Nama pembeli')
                && strpos($html, 'Nama pembeli') < strpos($html, 'Metode pembayaran dipilih')
                && strpos($html, 'Metode pembayaran dipilih') < strpos($html, 'Tambahkan email atau kontak bantuan'),
        );
    }

    public function test_multiple_payment_choices_are_explicit_inside_a_closed_disclosure(): void
    {
        config()->set('services.binance.pay_id', '987654321');
        ManualPaymentSetting::current()->update([
            'binance_pay_enabled' => true,
        ]);
        $product = Product::factory()->create([
            'name' => 'Multiple Payment Review',
            'price_amount' => 60000,
            'usdt_price_amount' => 3_500_000,
            'stock' => 4,
            'reserved_stock' => 0,
        ]);

        $html = $this->get(route('storefront.shop.checkout.product', ['product' => $product]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Ubah metode pembayaran', $html);
        $this->assertStringContainsString('type="radio" name="payment_method"', $html);
        $this->assertStringContainsString('Bayar dengan Binance Pay', $html);
        $this->assertStringContainsString('Total dasar USDT / estimasi', $html);
        $this->assertSame(0, preg_match('/<details id="checkout-payment-disclosure"[^>]*\\bopen\\b/', $html));
    }

    public function test_checkout_error_reopens_contact_disclosure_and_focuses_error_summary(): void
    {
        $product = Product::factory()->create([
            'name' => 'Contact Recovery Review',
            'stock' => 2,
            'reserved_stock' => 0,
        ]);

        $html = $this->followingRedirects()
            ->from(route('storefront.shop.checkout.product', ['product' => $product]))
            ->post(route('storefront.shop.checkout'), [
                'product_id' => $product->id,
                'quantity' => 1,
                'buyer_name' => 'Valid Buyer',
                'email' => 'invalid-email',
                'payment_method' => 'qris',
                'terms_accepted' => '1',
            ])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="checkout-error-summary"', $html);
        $this->assertStringContainsString('autofocus', $html);
        $this->assertStringContainsString('data-focus-target="checkout-email"', $html);
        $this->assertStringContainsString('<details id="checkout-contact-details"', $html);
        $this->assertStringContainsString('id="checkout-contact-details" data-checkout-contact-details', $html);
        $this->assertStringContainsString('value="invalid-email"', $html);
    }

    private function assertNoCheckoutSideEffects(): void
    {
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, InventoryMovement::count());
    }
}
