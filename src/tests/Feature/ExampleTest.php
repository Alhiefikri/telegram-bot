<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramUpdate;
use App\Jobs\SendTelegramReceipt;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductStockItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_path_returns_public_storefront_without_admin_redirect(): void
    {
        $response = $this->get('/');

        $response
            ->assertOk()
            ->assertSee('Akun AI Premium Siap Pakai')
            ->assertSee('Katalog akun AI premium')
            ->assertSee('href="'.url('/').'"', false)
            ->assertSee('property="og:title"', false)
            ->assertDontSee('/admin/login', false)
            ->assertDontSee("Let's get started");
    }

    public function test_storefront_mobile_menu_exposes_customer_links_without_admin_ctas(): void
    {
        $response = $this->get('/')->assertOk();

        $mobileMenuHtml = $this->htmlBetween($response->getContent(), '<div id="mobile-menu"', '</header>');

        foreach (['Cara Beli', 'Panduan Login', 'FAQ', 'Support', 'Belanja Web', 'Buka Bot Telegram'] as $customerLinkText) {
            $this->assertStringContainsString($customerLinkText, $mobileMenuHtml);
        }

        $this->assertStringNotContainsString('/admin/login', $mobileMenuHtml);
        $this->assertStringNotContainsString('Login Admin', $mobileMenuHtml);
        $this->assertStringNotContainsString('Dashboard toko', $mobileMenuHtml);
    }

    public function test_landing_header_and_hero_expose_web_shop_and_configured_telegram_bot_paths(): void
    {
        $botUrl = 'https://t.me/lhiestore_bot?start=issue122';
        config(['storefront.links.telegram_bot_url' => $botUrl]);

        $shopUrl = route('storefront.shop.index');
        $html = $this->get('/')->assertOk()->getContent();
        $headerHtml = $this->htmlBetween($html, '<header', '</header>');
        $mobileMenuHtml = $this->htmlBetween($html, '<div id="mobile-menu"', '</header>');
        $heroHtml = $this->htmlBetween($html, '<section class="storefront-hero-card', '</section>');

        $this->assertHtmlContainsLink($headerHtml, $shopUrl, 'Produk', 'Desktop product nav should send customers to the web shop grid.');
        $this->assertHtmlContainsLink($headerHtml, $shopUrl, 'Belanja Web', 'Desktop header should expose the website checkout path.');
        $headerTelegramLink = $this->matchingLinkHtml($headerHtml, $botUrl, 'Buka Bot Telegram');
        $this->assertNotNull($headerTelegramLink, 'Desktop header should expose the configured Telegram bot path.');
        $this->assertStringContainsString('data-telegram-link', $headerTelegramLink);

        $this->assertHtmlContainsLink($mobileMenuHtml, $shopUrl, 'Belanja Web', 'Mobile menu should expose the website checkout path.');
        $mobileTelegramLink = $this->matchingLinkHtml($mobileMenuHtml, $botUrl, 'Buka Bot Telegram');
        $this->assertNotNull($mobileTelegramLink, 'Mobile menu should expose the configured Telegram bot path.');
        $this->assertStringContainsString('data-telegram-link', $mobileTelegramLink);

        $this->assertHtmlContainsLink($heroHtml, $shopUrl, 'Belanja Web', 'Hero primary CTA should send customers to the web shop.');
        $heroTelegramLink = $this->matchingLinkHtml($heroHtml, $botUrl, 'Buka Bot Telegram');
        $this->assertNotNull($heroTelegramLink, 'Hero secondary CTA should keep the Telegram checkout path visible.');
        $this->assertStringContainsString('data-telegram-link', $heroTelegramLink);
    }

    public function test_landing_qris_card_is_explainer_not_static_payable_checkout(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $qrisHtml = $this->htmlBetween($html, '<aside class="qris-card', '</aside>');

        $this->assertStringContainsString('aria-label="Ilustrasi QRIS Pakasir Lhie Store"', $qrisHtml);
        $this->assertStringContainsString('QRIS Pakasir', $qrisHtml);
        $this->assertStringContainsString('QRIS web dibuat per order', $qrisHtml);
        $this->assertStringContainsString('Di jalur website, QRIS final muncul di halaman invoice setelah checkout. Jalur Telegram tetap mengikuti instruksi bot/admin.', $qrisHtml);
        $this->assertStringContainsString('Pakasir', $qrisHtml);
        $this->assertHtmlContainsLink($qrisHtml, route('storefront.shop.index'), 'Belanja Web via QRIS', 'QRIS explainer CTA should start website checkout instead of Telegram support.');
        $this->assertStringNotContainsString('data-telegram-link', $qrisHtml);
        $this->assertStringNotContainsString('Konfirmasi ke Admin', $qrisHtml);
        $this->assertStringNotContainsString('QRIS pembayaran manual', $qrisHtml);
        $this->assertStringNotContainsString('/qr-lhie.jpg', $qrisHtml);
        $this->assertStringNotContainsString('Scan QRIS untuk Bayar', $qrisHtml);
    }

    public function test_storefront_omits_admin_dashboard_ctas_from_public_landing_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertDontSee('/admin/login', false)
            ->assertDontSee('>Admin</a>', false)
            ->assertDontSee('Login Admin')
            ->assertDontSee('Dashboard toko');
    }

    public function test_storefront_telegram_destination_ctas_expose_telegram_marker(): void
    {
        config([
            'services.telegram.admin_contact_url' => 'https://t.me/lhiestore_support',
            'storefront.links.telegram_bot_url' => 'https://t.me/lhiestore_bot?start=landing',
        ]);

        $html = $this->get('/')->assertOk()->getContent();
        $telegramLinks = $this->telegramDestinationLinks($html);

        $this->assertNotEmpty($telegramLinks, 'Expected storefront to render Telegram-destination CTAs.');

        foreach ($telegramLinks as $linkHtml) {
            $this->assertTrue(
                $this->hasTelegramMarker($linkHtml),
                'Telegram-destination CTA is missing a visible or semantic Telegram marker: '.$linkHtml,
            );
        }
    }

    public function test_storefront_uses_host_relative_vite_assets(): void
    {
        config(['app.url' => 'https://stale-ngrok.example.test']);

        $this->get('/')
            ->assertOk()
            ->assertSee('href="/build/assets/', false)
            ->assertSee('src="/build/assets/', false)
            ->assertDontSee('https://stale-ngrok.example.test/build/assets/', false);
    }

    public function test_storefront_exposes_light_warung_ai_landing_design_hooks(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('storefront-shell', false)
            ->assertSee('storefront-hero-card', false)
            ->assertSee('storefront-popular-card', false)
            ->assertSee('hero-preview-panel', false)
            ->assertSee('motion-safe:animate-storefront-reveal', false)
            ->assertSee('motion-reduce:animate-none', false)
            ->assertDontSee('bg-[#09090b]', false);
    }

    public function test_root_path_renders_trust_payment_telegram_and_faq_content_without_dispatching_webhook_jobs(): void
    {
        Bus::fake([ProcessTelegramUpdate::class]);
        Queue::fake([SendTelegramReceipt::class]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Produk')
            ->assertSee('Cara Beli')
            ->assertSee('Panduan Login')
            ->assertSee('FAQ')
            ->assertSee('Support')
            ->assertSee('Belanja akun AI dalam tiga langkah')
            ->assertSee('/panduan-login-akun', false)
            ->assertSee('Pilih produk dari katalog, pilih bayar QRIS website atau Telegram')
            ->assertSee('Pengiriman digital')
            ->assertSee('Telegram tetap berjalan untuk order dari bot')
            ->assertSee('Akun siap pakai')
            ->assertSee('Tanpa tebak stok')
            ->assertSee('Order dibantu manusia')
            ->assertSee('Bagaimana cara membeli akun AI di Lhie Store?')
            ->assertSee('Apakah bot Telegram memproses order dari halaman ini?');

        Bus::assertNotDispatched(ProcessTelegramUpdate::class);
        Queue::assertNotPushed(SendTelegramReceipt::class);
    }

    public function test_login_guide_page_explains_login_security_with_extracted_screenshot_assets(): void
    {
        $response = $this->get('/panduan-login-akun')
            ->assertOk()
            ->assertSee('Cara login ChatGPT pakai Outlook/iCloud + MFA')
            ->assertSee('Data login yang harus disiapkan')
            ->assertSee('Tambahkan MFA Secret ke Google Authenticator')
            ->assertSee('Checklist keamanan')
            ->assertDontSee('Download PDF')
            ->assertDontSee('/panduan-login-akun/pdf', false);

        $content = $response->getContent();

        preg_match_all('#storefront/login-guide/(?!page-\d+\.jpg)[^"\']+\.(?:avif|webp|png|jpe?g)#', $content, $assetReferences);

        $this->assertGreaterThanOrEqual(3, count(array_unique($assetReferences[0])), 'Login guide should render multiple extracted screenshot assets instead of full-page PDF screenshots.');
        $this->assertDoesNotMatchRegularExpression('#storefront/login-guide/page-\d+\.jpg#', $content);

        $this->get('/cara-loginoutlook')->assertRedirect('/panduan-login-akun');
    }

    public function test_empty_catalog_renders_customer_facing_state(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Katalog publik sedang disiapkan')
            ->assertSee('Kategori akan segera hadir')
            ->assertSee('pelanggan bisa menghubungi support')
            ->assertSee('Produk populer akan tampil');
    }

    public function test_active_products_render_while_inactive_and_deleted_products_stay_hidden(): void
    {
        $category = Category::factory()->create([
            'name' => 'AI Tools',
            'slug' => 'ai-tools',
            'is_active' => true,
        ]);
        Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Canva Pro Bulanan',
            'description' => 'Akses desain premium untuk kebutuhan konten harian.',
            'price_amount' => 125000,
            'stock' => 7,
            'reserved_stock' => 2,
            'image_path' => null,
            'is_active' => true,
        ]);
        $inactiveCategory = Category::factory()->create(['name' => 'Kategori Produk Nonaktif', 'slug' => 'kategori-produk-nonaktif', 'is_active' => false]);
        Product::factory()->create(['category_id' => $inactiveCategory->id, 'name' => 'Produk Dari Kategori Nonaktif']);
        $deletedCategory = Category::factory()->create(['name' => 'Kategori Produk Terhapus', 'slug' => 'kategori-produk-terhapus']);
        Product::factory()->create(['category_id' => $deletedCategory->id, 'name' => 'Produk Dari Kategori Terhapus']);
        $deletedCategory->delete();
        Product::factory()->create(['name' => 'Produk Nonaktif', 'is_active' => false]);
        $deleted = Product::factory()->create(['name' => 'Produk Terhapus']);
        $deleted->delete();

        $this->get('/')
            ->assertOk()
            ->assertSee('Canva Pro Bulanan')
            ->assertSee('AI Tools')
            ->assertSee('Rp125.000')
            ->assertSee('Akses desain premium untuk kebutuhan konten harian.')
            ->assertSee('Tersedia')
            ->assertSee('Gambar produk menyusul')
            ->assertDontSee('Produk Nonaktif')
            ->assertDontSee('Produk Terhapus')
            ->assertDontSee('Produk Dari Kategori Nonaktif')
            ->assertDontSee('Produk Dari Kategori Terhapus');
    }

    public function test_storefront_response_cache_is_invalidated_when_catalog_products_change(): void
    {
        $product = Product::factory()->create([
            'name' => 'Canva Pro Lama',
            'description' => 'Produk awal.',
            'stock' => 2,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Canva Pro Lama');

        $product->update(['name' => 'Canva Pro Baru']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Canva Pro Baru')
            ->assertDontSee('Canva Pro Lama');
    }

    public function test_storefront_product_cards_show_public_details_links_and_hide_stock_item_secrets(): void
    {
        config()->set('services.telegram.admin_contact_url', 'https://t.me/lhiestore');
        $product = Product::factory()->create([
            'name' => 'Claude Pro Bulanan',
            'description' => 'Akses Claude premium untuk kerja harian.',
            'price_amount' => 175000,
            'stock' => 4,
            'reserved_stock' => 1,
            'is_active' => true,
        ]);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'claude-user@example.test | password: Secret-333']);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'claude-user-2@example.test | password: Secret-444']);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'claude-user-3@example.test | password: Secret-555']);
        Product::factory()->create(['name' => 'Produk Nonaktif Rahasia', 'is_active' => false]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Claude Pro Bulanan')
            ->assertSee('Rp175.000')
            ->assertSee('Akses Claude premium untuk kerja harian.')
            ->assertSee('Tersedia')
            ->assertSee('Stok: 3')
            ->assertSee(route('storefront.products.show', $product), false)
            ->assertSee('Support Telegram')
            ->assertDontSee('Produk Nonaktif Rahasia')
            ->assertDontSee('claude-user@example.test')
            ->assertDontSee('Secret-333');
    }

    public function test_active_categories_render_in_sort_order_while_inactive_and_deleted_stay_hidden(): void
    {
        $later = Category::factory()->create(['name' => 'AI Tools', 'slug' => 'ai-tools', 'sort_order' => 20, 'is_active' => true]);
        $first = Category::factory()->create(['name' => 'Akun Premium', 'slug' => 'akun-premium', 'sort_order' => 10, 'is_active' => true]);
        $inactive = Category::factory()->create(['name' => 'Kategori Nonaktif', 'slug' => 'kategori-nonaktif', 'is_active' => false]);
        $deleted = Category::factory()->create(['name' => 'Kategori Terhapus', 'slug' => 'kategori-terhapus']);
        Product::factory()->create(['category_id' => $later->id, 'name' => 'Produk AI', 'is_active' => true]);
        Product::factory()->create(['category_id' => $first->id, 'name' => 'Produk Premium', 'is_active' => true]);
        Product::factory()->create(['category_id' => $inactive->id, 'name' => 'Produk Kategori Nonaktif', 'is_active' => true]);
        Product::factory()->create(['category_id' => $deleted->id, 'name' => 'Produk Kategori Terhapus', 'is_active' => true]);
        $deleted->delete();

        $this->get('/')
            ->assertOk()
            ->assertSeeInOrder(['Akun Premium', 'AI Tools'])
            ->assertDontSee('Kategori Nonaktif')
            ->assertDontSee('Kategori Terhapus')
            ->assertDontSee('Produk Kategori Nonaktif')
            ->assertDontSee('Produk Kategori Terhapus');
    }

    public function test_featured_offer_selects_available_public_product_only(): void
    {
        $category = Category::factory()->create([
            'name' => 'Akun Premium',
            'slug' => 'akun-premium',
            'is_active' => true,
        ]);
        Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Akun Habis',
            'description' => 'Produk aktif tetapi stoknya tidak tersedia.',
            'stock' => 1,
            'reserved_stock' => 1,
            'is_active' => true,
        ]);
        Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Produk Nonaktif',
            'stock' => 5,
            'reserved_stock' => 0,
            'is_active' => false,
        ]);
        $deleted = Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Produk Terhapus',
            'stock' => 5,
            'reserved_stock' => 0,
        ]);
        $deleted->delete();
        Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Canva Pro Promo',
            'description' => 'Offer utama dengan stok siap kirim.',
            'price_amount' => 99000,
            'stock' => 4,
            'reserved_stock' => 1,
            'is_active' => true,
        ]);

        $html = $this->get('/')
            ->assertOk()
            ->getContent();

        $featuredHtml = $this->htmlBetween($html, '<section id="featured-offer"', '<section id="features"');

        $this->assertStringContainsString('Canva Pro Promo', $featuredHtml);
        $this->assertStringContainsString('Rp99.000', $featuredHtml);
        $this->assertStringContainsString('Sisa 3', $featuredHtml);
        $this->assertStringContainsString('Pilih Checkout', $featuredHtml);
        $this->assertStringNotContainsString('Akun Habis', $featuredHtml);
        $this->assertStringNotContainsString('Produk Nonaktif', $featuredHtml);
        $this->assertStringNotContainsString('Produk Terhapus', $featuredHtml);
    }

    public function test_featured_offer_has_safe_empty_state_without_suitable_product(): void
    {
        Product::factory()->create([
            'name' => 'Akun Habis',
            'stock' => 2,
            'reserved_stock' => 2,
            'is_active' => true,
        ]);
        Product::factory()->create([
            'name' => 'Produk Nonaktif Siap',
            'stock' => 5,
            'reserved_stock' => 0,
            'is_active' => false,
        ]);

        $html = $this->get('/')
            ->assertOk()
            ->getContent();

        $featuredHtml = $this->htmlBetween($html, '<section id="featured-offer"', '<section id="features"');

        $this->assertStringContainsString('Promo pilihan menunggu stok siap', $featuredHtml);
        $this->assertStringContainsString('Minta rekomendasi ke support', $featuredHtml);
        $this->assertStringNotContainsString('Pesan via Admin', $featuredHtml);
        $this->assertStringNotContainsString('Akun Habis', $featuredHtml);
        $this->assertStringNotContainsString('Produk Nonaktif Siap', $featuredHtml);
    }

    public function test_category_controls_render_ordered_accessible_filter_buttons(): void
    {
        $later = Category::factory()->create(['name' => 'AI Tools', 'slug' => 'ai-tools', 'sort_order' => 20, 'is_active' => true]);
        $first = Category::factory()->create(['name' => 'Akun Premium', 'slug' => 'akun-premium', 'sort_order' => 10, 'is_active' => true]);
        Product::factory()->create(['category_id' => $later->id, 'name' => 'Produk AI', 'is_active' => true]);
        Product::factory()->create(['category_id' => $first->id, 'name' => 'Produk Premium', 'is_active' => true]);

        $this->get('/')
            ->assertOk()
            ->assertSee('aria-label="Filter kategori produk"', false)
            ->assertSee('aria-controls="storefront-product-grid"', false)
            ->assertSeeInOrder([
                'data-filter="'.$first->id.'"',
                'Akun Premium',
                'data-filter="'.$later->id.'"',
                'AI Tools',
            ], false)
            ->assertDontSee('href="#category-akun-premium"', false)
            ->assertDontSee('id="category-akun-premium"', false);
    }

    public function test_out_of_stock_products_are_deprioritized_and_not_presented_as_ready_to_buy(): void
    {
        Product::factory()->create([
            'name' => 'Akun Kosong',
            'description' => 'Produk tanpa stok siap kirim.',
            'stock' => 1,
            'reserved_stock' => 1,
            'is_active' => true,
        ]);
        Product::factory()->create([
            'name' => 'Zeta Ready',
            'description' => 'Produk dengan stok siap kirim.',
            'stock' => 1,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSeeInOrder(['Zeta Ready', 'Akun Kosong'])
            ->assertSee('Tersedia')
            ->assertSee('STOK HABIS')
            ->assertSee('Habis')
            ->assertDontSee('Ready (1)')
            ->assertDontSee('Stok 0')
            ->assertDontSee('Belanja Sekarang')
            ->assertSee('Pilih Checkout')
            ->assertDontSee('href="'.route('storefront.products.show', Product::where('name', 'Akun Kosong')->first()).'"', false);
    }

    public function test_storefront_avoids_inline_assets_and_mock_only_presentation(): void
    {
        Product::factory()->create([
            'name' => 'Claude Pro Bulanan',
            'description' => 'Akses Claude premium untuk kerja harian.',
            'stock' => 4,
            'reserved_stock' => 1,
            'is_active' => true,
        ]);

        $html = $this->get('/')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<style>', $html);
        $this->assertStringNotContainsString('onclick=', $html);
        $this->assertStringNotContainsString('Terjual', $html);
        $this->assertStringNotContainsString('★', $html);
        $this->assertStringContainsString('Stok: 3', $html);
    }

    public function test_catalog_search_filters_public_products(): void
    {
        Product::factory()->create([
            'name' => 'Chat GPT Plus',
            'description' => 'Akses model premium.',
            'is_active' => true,
        ]);
        Product::factory()->create([
            'name' => 'Canva Pro',
            'description' => 'Desain premium.',
            'is_active' => true,
        ]);

        $html = $this->get('/?q=chat')
            ->assertOk()
            ->assertSee('value="chat"', false)
            ->getContent();

        $catalogHtml = $this->htmlBetween($html, '<section id="products"', '<section id="featured-offer"');

        $this->assertStringContainsString('Chat GPT Plus', $catalogHtml);
        $this->assertStringNotContainsString('Canva Pro', $catalogHtml);
    }

    /**
     * @return list<string>
     */
    private function telegramDestinationLinks(string $html): array
    {
        preg_match_all('/<a\b(?=[^>]*\bhref="https:\/\/t\.me\/[^"]*")[^>]*>.*?<\/a>/s', $html, $matches);

        return $matches[0];
    }

    private function hasTelegramMarker(string $linkHtml): bool
    {
        $visibleText = trim((string) preg_replace('/\s+/', ' ', strip_tags($linkHtml)));

        return str_contains(strtolower($visibleText), 'telegram')
            || preg_match('/\b(?:aria-label|title|class|data-icon)="[^"]*\btelegram\b/i', $linkHtml) === 1;
    }

    private function assertHtmlContainsLink(string $html, string $expectedHref, string $expectedText, ?string $message = null): void
    {
        $this->assertNotNull(
            $this->matchingLinkHtml($html, $expectedHref, $expectedText),
            $message ?? "Expected link [{$expectedText}] to point to [{$expectedHref}].",
        );
    }

    private function matchingLinkHtml(string $html, string $expectedHref, string $expectedText): ?string
    {
        preg_match_all('/<a\b(?=[^>]*\bhref="([^"]+)")[^>]*>.*?<\/a>/s', $html, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $href = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim((string) preg_replace(
                '/\s+/',
                ' ',
                html_entity_decode(strip_tags($match[0]), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ));

            if ($href === $expectedHref && $text === $expectedText) {
                return $match[0];
            }
        }

        return null;
    }

    private function htmlBetween(string $html, string $start, string $end): string
    {
        $startPosition = strpos($html, $start);
        $this->assertNotFalse($startPosition, "Missing start marker [{$start}].");

        $endPosition = strpos($html, $end, $startPosition);
        $this->assertNotFalse($endPosition, "Missing end marker [{$end}].");

        return substr($html, $startPosition, $endPosition - $startPosition);
    }
}
