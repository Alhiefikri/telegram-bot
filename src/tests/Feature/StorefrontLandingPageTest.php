<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ManualPaymentSetting;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductStockItem;
use App\Storefront\StorefrontResponseCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class StorefrontLandingPageTest extends TestCase
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

    public function test_root_path_returns_public_storefront_without_admin_redirect(): void
    {
        $response = $this->get('/');

        $response
            ->assertOk()
            ->assertSee('Akun Premium &amp; Produk Digital', false)
            ->assertSee('stok transparan, harga jelas')
            ->assertSee('href="'.url('/').'"', false)
            ->assertSee('property="og:title"', false)
            ->assertDontSee('/admin/login', false)
            ->assertDontSee("Let's get started");

        $response->assertHeader('Cache-Control', 'no-store, private');
        $this->assertFalse($response->headers->has('CDN-Cache-Control'));
        $this->assertFalse($response->headers->has('Surrogate-Control'));
        $this->assertFalse($response->headers->has('Set-Cookie'));
        $this->assertStringNotContainsString('name="csrf-token"', (string) $response->getContent());
        $this->assertStringContainsString('/build/assets/storefront-', (string) $response->getContent());
        $this->assertStringContainsString('/build/assets/storefront-entry-', (string) $response->getContent());
        $this->assertStringNotContainsString('fonts.googleapis.com', (string) $response->getContent());
    }

    public function test_search_results_remain_private_and_uncached(): void
    {
        $response = $this->get('/?q=chat');

        $response
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertFalse($response->headers->has('CDN-Cache-Control'));
    }

    public function test_home_keeps_catalog_order_account_guide_support_and_policy_links(): void
    {
        $shopUrl = route('storefront.shop.index');
        $accessUrl = route('storefront.order-access.create');
        $accountUrl = route('customer.account');
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertHtmlContainsLink($html, $shopUrl, 'Katalog Lengkap');
        $this->assertHtmlContainsLink($html, $accountUrl, 'Daftar / Masuk');
        $this->assertHtmlContainsLink($html, $accessUrl, 'Pesanan');
        $this->assertHtmlContainsLink($html, route('storefront.guides.index'), 'Pusat Panduan');
        $this->assertHtmlContainsLink($html, '#support', 'Bantuan');
        $this->assertHtmlContainsLink($html, route('storefront.policies.privacy'), 'Kebijakan Privasi');
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

    public function test_storefront_customer_flow_does_not_render_telegram_destination_ctas(): void
    {
        config([
            'services.telegram.admin_contact_url' => 'https://t.me/lhiestore_support',
        ]);

        $html = $this->get('/')->assertOk()->getContent();
        $telegramLinks = array_filter(
            $this->telegramDestinationLinks($html),
            fn (string $linkHtml): bool => ! str_contains($linkHtml, 'data-support-channel="telegram_admin"')
        );

        $this->assertEmpty($telegramLinks, 'The web checkout flow should not send customers to Telegram outside Telegram Admin support.');
        $this->assertStringContainsString('data-support-channel="telegram_admin"', $html);
        $this->assertStringContainsString('Telegram Admin', $html);
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

    public function test_homepage_cache_key_changes_when_vite_manifest_changes(): void
    {
        $manifestPath = public_path('build/manifest.json');
        $originalManifest = File::get($manifestPath);
        $manifest = json_decode($originalManifest, true, 512, JSON_THROW_ON_ERROR);
        $cacheKey = new \ReflectionMethod(StorefrontResponseCache::class, 'key');
        $cacheKey->setAccessible(true);
        $cache = app(StorefrontResponseCache::class);

        try {
            $before = $cacheKey->invoke($cache, 1, 1, '');

            $manifest['resources/css/app.css']['file'] = 'assets/app-cache-bust.css';
            File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            $after = $cacheKey->invoke($cache, 1, 1, '');

            $this->assertNotSame($before, $after);
        } finally {
            File::put($manifestPath, $originalManifest);
        }
    }

    public function test_storefront_exposes_soft_storefront_design_hooks(): void
    {
        $response = $this->get('/');
        $response->assertOk();
        $html = (string) $response->getContent();

        foreach ([
            'storefront-shell',
            'home-catalog',
            'motion-reduce:animate-none',
        ] as $needle) {
            $this->assertTrue(str_contains($html, $needle), "Missing storefront hook: {$needle}");
        }
        $this->assertFalse(str_contains($html, 'bg-[#09090b]'), 'Legacy dark background token leaked into Storefront.');
    }

    public function test_homepage_uses_a_catalog_first_hierarchy_without_duplicate_marketing_sections(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $catalogStart = strpos($html, '<section id="products"');
        $activityHtml = $this->htmlBetween($html, '<aside id="verified-purchase-activity"', '</aside>');

        $this->assertNotFalse($catalogStart);
        $this->assertLessThan(strpos($html, $activityHtml), $catalogStart);
        $this->assertSame(1, substr_count($html, 'id="products"'));
        $this->assertSame(1, substr_count($html, 'id="cara-beli"'));
        $this->assertSame(1, substr_count($html, 'id="faq"'));
        $this->assertSame(1, substr_count($html, 'id="support"'));
        $this->assertStringNotContainsString('storefront-hero-card', $html);
        $this->assertStringNotContainsString('storefront-banner-carousel', $html);
        $this->assertStringNotContainsString('id="featured-offer"', $html);
        $this->assertStringNotContainsString('id="features"', $html);
        $this->assertStringNotContainsString('neo-marquee', $html);
        $this->assertStringContainsString('data-endpoint="'.route('storefront.verified-purchase-activity').'"', $activityHtml);
        $this->assertStringContainsString('Lihat ringkasan checkout dan akses ulang', $html);
        $this->assertStringContainsString('QRIS final muncul di halaman invoice setelah checkout.', $html);
        $this->assertStringContainsString('/panduan', $html);
        $this->assertStringContainsString('Akses Pesanan', $html);
    }

    public function test_guide_hub_lists_chatgpt_login_methods_and_links_to_the_specific_guide(): void
    {
        $this->get('/panduan')
            ->assertOk()
            ->assertSee('Pilih panduan yang Anda butuhkan.')
            ->assertSee('Panduan login ChatGPT')
            ->assertSee('Outlook')
            ->assertSee('iCloud')
            ->assertSee('Google Authenticator')
            ->assertSee('/gpt/panduan-login-akun', false);
    }

    public function test_login_guide_page_explains_login_security_with_extracted_screenshot_assets(): void
    {
        $response = $this->get('/gpt/panduan-login-akun')
            ->assertOk()
            ->assertSee('Cara login ChatGPT pakai Outlook/iCloud + MFA')
            ->assertSee('Data login yang harus disiapkan')
            ->assertSee('Tambahkan MFA Secret ke Google Authenticator')
            ->assertSee('Checklist keamanan')
            ->assertSee('Semua Panduan')
            ->assertSee('/panduan', false)
            ->assertDontSee('Download PDF')
            ->assertDontSee('/gpt/panduan-login-akun/pdf', false);

        $content = $response->getContent();

        preg_match_all('#storefront/login-guide/(?!page-\d+\.jpg)[^"\']+\.(?:avif|webp|png|jpe?g)#', $content, $assetReferences);

        $this->assertGreaterThanOrEqual(3, count(array_unique($assetReferences[0])), 'Login guide should render multiple extracted screenshot assets instead of full-page PDF screenshots.');
        $this->assertDoesNotMatchRegularExpression('#storefront/login-guide/page-\d+\.jpg#', $content);

        $this->get('/panduan-login-akun')->assertRedirect('/gpt/panduan-login-akun');
        $this->get('/cara-loginoutlook')->assertRedirect('/gpt/panduan-login-akun');
    }

    public function test_storefront_product_cover_uses_database_icon_for_an_arbitrary_product_name(): void
    {
        Product::factory()->create([
            'name' => 'Produk Dinamis',
            'icon' => '🧩',
            'image_path' => null,
            'stock' => 2,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Produk Dinamis')
            ->assertSee('🧩')
            ->assertDontSee('NETFLIX STREAM')
            ->assertDontSee('GEMINI / GOOGLE');
    }

    public function test_empty_catalog_renders_customer_facing_state(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Katalog publik sedang disiapkan')
            ->assertSee('Cek kembali katalog saat stok produk yang Anda cari tersedia.')
            ->assertDontSee('Belum ada data penjualan berbayar selesai untuk menampilkan label produk terlaris.');
    }

    public function test_empty_popularity_keeps_available_catalog_and_hides_popularity_section(): void
    {
        $family = ProductFamily::factory()->create([
            'name' => 'Streaming Family',
            'slug' => 'streaming-family',
        ]);
        Product::factory()->create([
            'product_family_id' => $family->id,
            'name' => 'Streaming Family Option',
            'stock' => 2,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        Product::factory()->create([
            'name' => 'Standalone Available Product',
            'stock' => 2,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $html = $this->get('/')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Trending sekarang', $html);
        $this->assertStringNotContainsString('id="trending"', $html);
        $this->assertStringContainsString('Stok: 2', $html);
        $this->assertStringContainsString('Streaming Family', $html);
        $this->assertStringContainsString('Standalone Available Product', $html);
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
            ->assertSee('Rp125.000')
            ->assertSee('/assets/product-placeholder.svg', false)
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

    public function test_storefront_product_cards_show_public_details_and_page_checkout_urls_without_stock_item_secrets(): void
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
            ->assertSee('Stok: 3')
            ->assertSee(route('storefront.products.show', $product), false)
            ->assertSee(route('storefront.shop.checkout.product', $product), false)
            ->assertSee('Beli sekarang')
            ->assertDontSee('data-product=', false)
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

        $this->get(route('storefront.shop.index'))
            ->assertOk()
            ->assertSeeInOrder(['Akun Premium', 'AI Tools'])
            ->assertDontSee('Kategori Nonaktif')
            ->assertDontSee('Kategori Terhapus')
            ->assertDontSee('Produk Kategori Nonaktif')
            ->assertDontSee('Produk Kategori Terhapus');
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
            ->assertSee('Stok kosong')
            ->assertDontSee('Ready (1)')
            ->assertDontSee('Stok 0')
            ->assertSee('Beli sekarang')
            ->assertSee('Beri tahu saya saat tersedia')
            ->assertSee('href="'.route('storefront.products.show', Product::where('name', 'Akun Kosong')->first()).'?from=', false);
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

        $catalogHtml = $this->htmlBetween($html, '<section id="products"', '<section id="cara-beli"');

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
