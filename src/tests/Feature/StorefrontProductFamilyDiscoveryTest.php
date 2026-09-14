<?php

namespace Tests\Feature;

use App\Jobs\SendProductWaitlistConfirmation;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductFamilySlugRedirect;
use App\Models\ProductWaitlistSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StorefrontProductFamilyDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('services.storefront.telegram_reservation_floor', 0);

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
            ->assertSee('2 pilihan')
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

        $canonical = route('storefront.product-families.show', ['productFamily' => $family->slug]);
        $response = $this->get($canonical);
        $response->assertOk()
            ->assertSee('<link rel="canonical" href="'.$canonical.'">', false)
            ->assertSee('<meta property="og:url" content="'.$canonical.'">', false)
            ->assertSee('<title>CapCut — Pilihan Produk | Lhie Store</title>', false)
            ->assertSee('Pilih paket CapCut sesuai kebutuhan.')
            ->assertSee('Individual 30 Hari')
            ->assertSee('Team 30 Hari')
            ->assertDontSee('Product Family')
            ->assertDontSee('Pilih SKU')
            ->assertDontSee('SKU #')
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

    public function test_family_page_falls_back_to_product_name_for_whitespace_only_option_label(): void
    {
        $family = ProductFamily::factory()->create(['slug' => 'whitespace-family', 'name' => 'Whitespace Family']);
        $product = Product::factory()->create([
            'name' => 'Canonical Family Product',
            'product_family_id' => $family->id,
            'family_option_label' => " \t\n ",
            'stock' => 1,
            'reserved_stock' => 0,
        ]);

        $this->get(route('storefront.product-families.show', ['productFamily' => $family->slug]))
            ->assertOk()
            ->assertSee('Canonical Family Product')
            ->assertSee('href="'.route('storefront.products.show', ['product' => $product]).'?from=', false)
            ->assertSee('name="product_id" value="'.$product->id.'"', false);
    }

    public function test_family_option_direct_checkout_preserves_product_identity_amount_and_return_path(): void
    {
        Queue::fake();
        $this->manualPaymentModeIsActive();
        $family = ProductFamily::factory()->create(['slug' => 'direct-family', 'name' => 'Direct Family']);
        $product = Product::factory()->create([
            'name' => 'Direct Family SKU',
            'product_family_id' => $family->id,
            'family_sort_order' => 1,
            'family_option_label' => 'Direct Package',
            'price_amount' => 32000,
            'stock' => 2,
            'reserved_stock' => 0,
        ]);
        $familyUrl = route('storefront.product-families.show', ['productFamily' => $family->slug]);

        $this->get($familyUrl)
            ->assertOk()
            ->assertSee('name="product_id" value="'.$product->id.'"', false);
        $this->get(route('storefront.shop.checkout.product', [
            'product' => $product,
            'from' => $familyUrl,
        ]))
            ->assertOk()
            ->assertSee('name="product_id" value="'.$product->id.'"', false)
            ->assertSee('action="'.route('storefront.shop.checkout').'?from=', false)
            ->assertSee('href="'.$familyUrl.'"', false);
        $openedEvent = AuditLog::query()->where('action', 'storefront.checkout_opened')->latest('id')->firstOrFail();
        $this->assertSame('family_direct_checkout_page', $openedEvent->new_values['source']);
        $this->assertArrayNotHasKey('buyer_name', $openedEvent->new_values);

        $this->post(route('storefront.shop.checkout').'?from='.rawurlencode($familyUrl), [
            'product_id' => $product->id,
            'quantity' => 2,
            'buyer_name' => 'Family Direct Buyer',
            'payment_method' => 'qris',
            'terms_accepted' => '1',
        ])
            ->assertRedirect();
        $submittedEvent = AuditLog::query()->where('action', 'storefront.checkout_submitted')->latest('id')->firstOrFail();
        $this->assertSame('family_direct_checkout_page', $submittedEvent->new_values['source']);
        $this->assertArrayNotHasKey('buyer_name', $submittedEvent->new_values);

        $order = Order::query()->with('orderItems')->sole();
        $item = $order->orderItems->sole();
        $payment = $order->payments()->sole();
        $this->assertSame($product->id, $item->product_id);
        $this->assertSame($family->id, $item->family_id);
        $this->assertSame(32000, $item->unit_price);
        $this->assertSame(64000, $item->subtotal);
        $this->assertSame(64000, $payment->amount);
        $this->assertSame(2, $product->fresh()->reserved_stock);
    }

    public function test_family_members_can_fill_a_multi_item_cart_and_checkout_with_product_ids(): void
    {
        Queue::fake();
        $this->manualPaymentModeIsActive();
        $family = ProductFamily::factory()->create(['slug' => 'cart-family', 'name' => 'Cart Family']);
        $first = Product::factory()->create([
            'name' => 'Cart Family First',
            'product_family_id' => $family->id,
            'family_sort_order' => 1,
            'price_amount' => 10000,
            'stock' => 3,
            'reserved_stock' => 0,
        ]);
        $second = Product::factory()->create([
            'name' => 'Cart Family Second',
            'product_family_id' => $family->id,
            'family_sort_order' => 2,
            'price_amount' => 15000,
            'stock' => 3,
            'reserved_stock' => 0,
        ]);
        $this->get(route('storefront.product-families.show', ['productFamily' => $family->slug]))
            ->assertOk()
            ->assertSee('name="product_id" value="'.$first->id.'"', false)
            ->assertSee('name="product_id" value="'.$second->id.'"', false);
        $sessionKey = 'family-cart-session';

        $this->addToCart($first, sessionKey: $sessionKey);
        $this->addToCart($second, sessionKey: $sessionKey);
        $review = $this->withSession(['storefront.checkout_client_key' => $sessionKey])
            ->get(route('storefront.shop.checkout.start'))
            ->assertOk();
        preg_match('/name="cart_snapshot" value="([a-f0-9]+)"/', $review->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? null);

        $this->withSession(['storefront.checkout_client_key' => $sessionKey])
            ->post(route('storefront.shop.checkout'), [
                'cart_snapshot' => $matches[1],
                'buyer_name' => 'Family Cart Buyer',
                'payment_method' => 'qris',
                'terms_accepted' => '1',
            ])
            ->assertRedirect();

        $order = Order::query()->with('orderItems')->sole();
        $this->assertSame([$first->id, $second->id], $order->orderItems->pluck('product_id')->sort()->values()->all());
        $this->assertSame(25000, $order->payments()->sole()->amount);
        $this->assertSame(1, $first->fresh()->reserved_stock);
        $this->assertSame(1, $second->fresh()->reserved_stock);
    }

    public function test_family_cart_stale_signed_snapshot_is_rejected_before_order_creation(): void
    {
        Queue::fake();
        $this->manualPaymentModeIsActive();
        $family = ProductFamily::factory()->create(['slug' => 'stale-family', 'name' => 'Stale Family']);
        $product = Product::factory()->create([
            'name' => 'Stale Family SKU',
            'product_family_id' => $family->id,
            'price_amount' => 12000,
            'stock' => 2,
            'reserved_stock' => 0,
        ]);
        $this->get(route('storefront.product-families.show', ['productFamily' => $family->slug]))
            ->assertOk()
            ->assertSee('Stale Family SKU');
        $sessionKey = 'stale-family-cart-session';
        $this->addToCart($product, sessionKey: $sessionKey);
        $review = $this->withSession(['storefront.checkout_client_key' => $sessionKey])
            ->get(route('storefront.shop.checkout.start'))
            ->assertOk();
        preg_match('/name="cart_snapshot" value="([a-f0-9]+)"/', $review->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? null);

        $product->update(['price_amount' => 13000]);

        $this->withSession(['storefront.checkout_client_key' => $sessionKey])
            ->post(route('storefront.shop.checkout'), [
                'cart_snapshot' => $matches[1],
                'buyer_name' => 'Stale Family Buyer',
                'payment_method' => 'qris',
                'terms_accepted' => '1',
            ])
            ->assertRedirect(route('storefront.shop.checkout.start'));

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, InventoryMovement::query()->count());
    }

    public function test_sold_out_family_member_keeps_waitlist_path_product_identity_and_no_commerce_effects(): void
    {
        Queue::fake();
        $family = ProductFamily::factory()->create(['slug' => 'waitlist-family', 'name' => 'Waitlist Family']);
        $product = Product::factory()->create([
            'name' => 'Waitlist Family SKU',
            'product_family_id' => $family->id,
            'family_option_label' => 'Sold Out Package',
            'stock' => 0,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $this->get(route('storefront.product-families.show', ['productFamily' => $family->slug]))
            ->assertOk()
            ->assertSee('Sold Out Package')
            ->assertSee(route('storefront.products.show', ['product' => $product]), false);
        $this->get(route('storefront.products.show', ['product' => $product]))
            ->assertOk()
            ->assertDontSee('id="product-checkout-cta"', false)
            ->assertSee('id="waitlist"', false)
            ->assertSee('action="'.route('storefront.waitlist.store', ['product' => $product]).'"', false);

        $this->post(route('storefront.waitlist.store', ['product' => $product]), [
            'email' => 'family-waitlist@example.test',
        ])->assertRedirect(route('storefront.products.show', ['product' => $product]).'#waitlist');

        $subscription = ProductWaitlistSubscription::query()->sole();
        $this->assertSame($product->id, $subscription->product_id);
        $this->assertSame(ProductWaitlistSubscription::STATUS_PENDING_CONFIRMATION, $subscription->status);
        $this->assertSame(0, Cart::query()->count());
        $this->assertSame(0, Order::query()->count());
        Queue::assertPushed(SendProductWaitlistConfirmation::class);
    }

    public function test_family_option_card_leads_with_price_availability_and_buy_before_long_details(): void
    {
        $family = ProductFamily::factory()->create([
            'slug' => 'decision-order-family',
            'name' => 'Decision Order Family',
            'description' => 'Pilih paket sesuai kebutuhan.',
        ]);
        $product = Product::factory()->create([
            'name' => 'Decision Order SKU',
            'product_family_id' => $family->id,
            'family_sort_order' => 1,
            'family_option_label' => 'Paket 30 Hari',
            'description' => "Baris pertama penawaran.\nBaris panjang kedua yang memuat detail panjang berulang-ulang untuk membuktikan bahwa konten pendukung tidak boleh mengubur tombol Beli pada layar sempit.",
            'product_type' => 'Akun premium',
            'access_duration' => '30 hari',
            'region' => 'Global',
            'account_type' => 'Private',
            'included_items' => "Akun siap pakai\nPanduan login",
            'limitations' => 'Tidak untuk dijual kembali',
            'fulfillment_expectation' => 'Dikirim setelah pembayaran terverifikasi.',
            'warranty_policy' => 'Garansi login 7 hari.',
            'support_policy' => 'Support pada jam operasional.',
            'price_amount' => 99000,
            'stock' => 3,
            'reserved_stock' => 0,
        ]);

        $html = $this->get(route('storefront.product-families.show', ['productFamily' => $family->slug]))
            ->assertOk()
            ->getContent();
        preg_match('/<article id="family-option-'.$product->id.'"(.*?)<\/article>/s', $html, $card);
        $this->assertNotEmpty($card[1] ?? null);
        $cardHtml = $card[1];

        $pricePosition = strpos($cardHtml, 'Rp99.000');
        $durationChipPosition = strpos($cardHtml, 'Durasi 30 hari');
        preg_match('/>\s*Beli(?: sekarang)?\s*</', $cardHtml, $buyMatch, PREG_OFFSET_CAPTURE);
        $summaryPosition = strpos($cardHtml, 'Baca detail lengkap');
        $teaserPosition = strpos($cardHtml, 'Baris pertama penawaran.');
        $merchandisingPosition = strpos($cardHtml, 'Region');

        $this->assertNotFalse($pricePosition, 'Option card should show the price.');
        $this->assertNotFalse($durationChipPosition, 'Option card should show the duration as a decision fact.');
        $this->assertNotFalse($buyPosition = $buyMatch[0][1] ?? false, 'Option card should show the Beli action.');
        $this->assertNotFalse($summaryPosition, 'Option card should keep details in a disclosure.');
        $this->assertNotFalse($teaserPosition, 'Option card should keep the description reachable.');
        $this->assertNotFalse($merchandisingPosition, 'Option card should keep merchandising reachable.');
        $this->assertLessThan($buyPosition, $pricePosition, 'Price should appear above the Beli action.');
        $this->assertLessThan($buyPosition, $durationChipPosition, 'Duration should appear above the Beli action.');
        $this->assertLessThan($summaryPosition, $buyPosition, 'Beli should appear above the details disclosure.');
        $this->assertLessThan($teaserPosition, $summaryPosition, 'Description should live inside the disclosure.');
        $this->assertLessThan($merchandisingPosition, $summaryPosition, 'Merchandising should live inside the disclosure.');

        $this->assertSame(1, preg_match('/<details data-family-option-details/', $cardHtml), 'Disclosure should be a native details element.');
        $this->assertSame(0, preg_match('/<details data-family-option-details[^>]*\bopen\b/', $cardHtml), 'Details should start collapsed.');
        $this->assertStringContainsString('Tersedia', $cardHtml, 'Availability should stay visible on the card.');
        $this->assertStringContainsString('href="'.route('storefront.shop.checkout.product', ['product' => $product]).'?from=', $cardHtml, 'Beli should point at the direct checkout review.');
    }

    public function test_canonical_family_path_and_legacy_slug_aliases_redirect_safely(): void
    {
        $family = ProductFamily::factory()->create(['slug' => 'old-netflix', 'name' => 'Netflix']);
        Product::factory()->create([
            'product_family_id' => $family->id,
            'stock' => 1,
            'reserved_stock' => 0,
        ]);

        $canonical = route('storefront.product-families.show', ['productFamily' => $family->slug]);
        $this->assertSame('/pilihan/old-netflix', parse_url($canonical, PHP_URL_PATH));

        $returnUrl = '/shop?q=netflix&sort=price_asc';
        $legacy = $this->get('/produk-keluarga/old-netflix?from='.rawurlencode($returnUrl).'&utm_source=legacy');
        $legacy->assertStatus(301);
        $this->assertSame($canonical.'?from='.rawurlencode($returnUrl), $legacy->headers->get('Location'));
        $this->assertStringNotContainsString('utm_source', (string) $legacy->headers->get('Location'));

        $this->get('/produk-keluarga/old-netflix?from='.rawurlencode('https://evil.example/phish').'&utm_source=legacy')
            ->assertStatus(301)
            ->assertRedirect($canonical);

        ProductFamilySlugRedirect::query()->create([
            'old_slug' => 'netflix-legacy',
            'product_family_id' => $family->id,
        ]);
        $this->get('/produk-keluarga/netflix-legacy')
            ->assertStatus(301)
            ->assertRedirect($canonical);
    }

    public function test_legacy_family_path_returns_not_found_for_unknown_private_or_deleted_families(): void
    {
        $private = ProductFamily::factory()->create(['slug' => 'private-family']);
        Product::factory()->create([
            'product_family_id' => $private->id,
            'is_active' => false,
            'stock' => 1,
            'reserved_stock' => 0,
        ]);
        $deleted = ProductFamily::factory()->create(['slug' => 'deleted-family']);
        Product::factory()->create([
            'product_family_id' => $deleted->id,
            'stock' => 1,
            'reserved_stock' => 0,
        ]);
        $deleted->delete();

        $this->get('/produk-keluarga/does-not-exist')->assertNotFound();
        $this->get('/produk-keluarga/private-family')->assertNotFound();
        $this->get('/produk-keluarga/deleted-family')->assertNotFound();
    }

    public function test_canonical_family_route_requires_a_public_current_slug(): void
    {
        $public = ProductFamily::factory()->create(['slug' => 'public-family']);
        Product::factory()->create([
            'product_family_id' => $public->id,
            'stock' => 1,
            'reserved_stock' => 0,
        ]);
        $private = ProductFamily::factory()->create(['slug' => 'private-current-family']);
        Product::factory()->create([
            'product_family_id' => $private->id,
            'is_active' => false,
            'stock' => 1,
            'reserved_stock' => 0,
        ]);
        ProductFamilySlugRedirect::query()->create([
            'old_slug' => $private->slug,
            'product_family_id' => $public->id,
        ]);

        $this->get('/pilihan/'.$private->slug)->assertNotFound();
        $this->get('/produk-keluarga/'.$private->slug)->assertNotFound();
        $this->get('/pilihan/'.$public->slug)->assertOk();
    }

    public function test_canonical_family_page_drops_legacy_family_return_context(): void
    {
        $family = ProductFamily::factory()->create([
            'slug' => 'canonical-family',
            'name' => 'Canonical Family',
        ]);
        Product::factory()->create([
            'product_family_id' => $family->id,
            'stock' => 1,
            'reserved_stock' => 0,
        ]);
        $canonical = route('storefront.product-families.show', ['productFamily' => $family->slug]);

        $this->get($canonical.'?from='.rawurlencode('/produk-keluarga/legacy-family'))
            ->assertOk()
            ->assertSee('href="'.$canonical.'"', false)
            ->assertDontSee('/produk-keluarga/');
    }

    private function manualPaymentModeIsActive(): void
    {
        ManualPaymentSetting::query()->create([
            'payment_mode' => 'manual',
            'is_active' => true,
        ]);
    }

    private function addToCart(Product $product, int $quantity = 1, string $sessionKey = 'family-cart-session'): void
    {
        $this->withSession(['storefront.checkout_client_key' => $sessionKey])
            ->post(route('storefront.shop.cart.items.store'), [
                'product_id' => $product->id,
                'quantity' => $quantity,
            ])
            ->assertRedirect(route('storefront.shop.cart'));
    }

    public function test_sitemap_contains_public_family_pages_and_sku_urls(): void
    {
        $family = ProductFamily::factory()->create(['slug' => 'youtube', 'name' => 'YouTube']);
        $product = Product::factory()->create(['product_family_id' => $family->id, 'stock' => 1, 'reserved_stock' => 0]);

        $response = $this->get(route('storefront.sitemap'));
        $response->assertOk()
            ->assertSee(route('storefront.product-families.show', ['productFamily' => $family->slug]), false)
            ->assertSee(route('storefront.products.show', ['product' => $product]), false)
            ->assertDontSee('/produk-keluarga/', false);
    }
}
