<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class StorefrontSeoTest extends TestCase
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

    public function test_product_metadata_contains_safe_product_offer_and_image_data(): void
    {
        $product = Product::factory()->create([
            'name' => 'SEO <Product>',
            'description' => "Public description <safe>\nInternal line must not be used.",
            'price_amount' => 60_000,
            'currency' => 'IDR',
            'stock' => 2,
            'image_path' => '/storage/products/seo-product.jpg?signature=private',
        ]);

        $response = $this->get(route('storefront.products.show', $product));
        $response->assertOk();
        $html = (string) $response->getContent();
        $documents = $this->jsonLdDocuments($html);
        $productData = collect($documents)->firstWhere('@type', 'Product');

        $this->assertIsArray($productData);
        $this->assertSame('SEO <Product>', $productData['name']);
        $this->assertSame(route('storefront.products.show', $product), $productData['url']);
        $this->assertSame(60_000, $productData['offers']['price']);
        $this->assertSame('IDR', $productData['offers']['priceCurrency']);
        $this->assertSame('https://schema.org/InStock', $productData['offers']['availability']);
        $this->assertSame(url('/storage/products/seo-product.jpg'), $productData['image'][0]);
        $this->assertSame($productData['url'], $productData['offers']['url']);
        $this->assertStringContainsString('name="robots" content="index, follow"', $html);
        $this->assertStringContainsString('property="og:image:alt"', $html);
        $this->assertStringNotContainsString('signature=private', $html);
        $this->assertStringNotContainsString('<Product>', $html);
    }

    public function test_unavailable_product_uses_out_of_stock_offer_and_placeholder_image(): void
    {
        $product = Product::factory()->create([
            'name' => 'Unavailable SEO Product',
            'stock' => 0,
            'reserved_stock' => 0,
            'image_path' => null,
        ]);

        $html = (string) $this->get(route('storefront.products.show', $product))->assertOk()->getContent();
        $productData = collect($this->jsonLdDocuments($html))->firstWhere('@type', 'Product');

        $this->assertSame('https://schema.org/OutOfStock', $productData['offers']['availability']);
        $this->assertSame(url('/assets/product-placeholder.svg'), $productData['image'][0]);
    }

    public function test_home_faq_and_catalog_breadcrumb_json_ld_match_visible_content(): void
    {
        $home = $this->get(route('storefront.home'))->assertOk();
        $homeHtml = (string) $home->getContent();
        $faqData = collect($this->jsonLdDocuments($homeHtml))->firstWhere('@type', 'FAQPage');

        $this->assertIsArray($faqData);
        $this->assertCount(count(config('storefront.faq')), $faqData['mainEntity']);
        $this->assertStringContainsString(config('storefront.faq.0.question'), $homeHtml);
        $this->assertStringContainsString(config('storefront.faq.0.answer'), $homeHtml);

        $shop = $this->get(route('storefront.shop.index'))->assertOk();
        $shopHtml = (string) $shop->getContent();
        $breadcrumbData = collect($this->jsonLdDocuments($shopHtml))->firstWhere('@type', 'BreadcrumbList');

        $this->assertIsArray($breadcrumbData);
        $this->assertSame(['Beranda', 'Katalog'], array_column($breadcrumbData['itemListElement'], 'name'));
        $this->assertStringContainsString('<nav aria-label="Breadcrumb"', $shopHtml);
        $this->assertStringContainsString('>Beranda<', $shopHtml);
        $this->assertStringContainsString('aria-current="page"', $shopHtml);
    }

    public function test_family_metadata_uses_only_the_canonical_choice_url(): void
    {
        $family = ProductFamily::factory()->create([
            'slug' => 'seo-family',
            'name' => 'SEO Family',
        ]);
        Product::factory()->create([
            'product_family_id' => $family->id,
            'stock' => 1,
            'reserved_stock' => 0,
        ]);
        $canonical = route('storefront.product-families.show', ['productFamily' => $family->slug]);

        $html = (string) $this->get($canonical)->assertOk()->getContent();
        $documents = $this->jsonLdDocuments($html);
        $collection = collect($documents)->firstWhere('@type', 'CollectionPage');
        $breadcrumbs = collect($documents)->firstWhere('@type', 'BreadcrumbList');

        $this->assertIsArray($collection);
        $this->assertSame($canonical, $collection['url']);
        $this->assertIsArray($breadcrumbs);
        $this->assertSame($canonical, $breadcrumbs['itemListElement'][2]['item']);
        $this->assertStringNotContainsString('/produk-keluarga/', $html);
    }

    public function test_sitemap_contains_only_bounded_public_canonical_urls(): void
    {
        config()->set('storefront.sitemap.max_urls', 10);
        $first = Product::factory()->create(['name' => 'Sitemap Public One']);
        $second = Product::factory()->create(['name' => 'Sitemap Public Two']);
        $inactive = Product::factory()->create(['name' => 'Sitemap Inactive', 'is_active' => false]);
        $deleted = Product::factory()->create(['name' => 'Sitemap Deleted']);
        $deleted->delete();
        $inactiveCategory = Category::factory()->create(['is_active' => false]);
        Product::factory()->create([
            'name' => 'Sitemap Inactive Category',
            'category_id' => $inactiveCategory->id,
        ]);

        $response = $this->get(route('storefront.sitemap'))->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $xml = (string) $response->getContent();
        preg_match_all('/<loc>(.*?)<\/loc>/', $xml, $matches);
        $locations = array_map(
            static fn (string $location): string => html_entity_decode($location, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
            $matches[1] ?? [],
        );

        $this->assertCount(10, $locations);
        $this->assertContains(route('storefront.products.show', $first), $locations);
        $this->assertContains(route('storefront.products.show', $second), $locations);
        $this->assertNotContains(route('storefront.products.show', $inactive), $locations);
        $this->assertNotContains(route('storefront.products.show', $deleted), $locations);
        foreach ($locations as $location) {
            $this->assertStringNotContainsString('?', $location);
        }
        $this->assertStringNotContainsString('/shop/orders/', $xml);
        $this->assertStringNotContainsString('/akses-pesanan', $xml);
        $this->assertStringNotContainsString('/akun', $xml);
        $this->assertStringNotContainsString('/admin', $xml);
        $this->assertStringNotContainsString('/api/', $xml);
    }

    public function test_private_and_search_pages_are_noindex_without_canonical_urls(): void
    {
        foreach ([
            route('storefront.order-access.create'),
            route('storefront.shop.cart'),
            route('customer.account'),
            route('admin.login'),
        ] as $url) {
            $html = (string) $this->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('name="robots" content="noindex, nofollow, noarchive"', $html);
            $this->assertStringNotContainsString('<link rel="canonical"', $html);
        }

        $searchHtml = (string) $this->get(route('storefront.shop.index', ['q' => 'private']))->assertOk()->getContent();
        $this->assertStringContainsString('name="robots" content="noindex, follow"', $searchHtml);
    }

    public function test_signed_invoice_is_noindex_without_signed_canonical_metadata(): void
    {
        $order = Order::factory()->create();
        $signedUrl = URL::temporarySignedRoute(
            'storefront.shop.orders.show',
            now()->addHour(),
            ['order' => $order],
            false,
        );

        $html = (string) $this->get($signedUrl)->assertOk()->getContent();
        $head = explode('</head>', $html, 2)[0];

        $this->assertStringContainsString('name="robots" content="noindex, nofollow, noarchive"', $head);
        $this->assertStringNotContainsString('<link rel="canonical"', $head);
        $this->assertStringNotContainsString('property="og:url"', $head);
        $this->assertStringNotContainsString($order->invoice_number, $head);
    }

    public function test_robots_references_https_sitemap_and_private_paths(): void
    {
        config()->set('app.force_https', true);

        $body = (string) $this->get(route('storefront.robots'))->assertOk()->getContent();

        $this->assertStringContainsString('Disallow: /shop/orders/', $body);
        $this->assertStringContainsString('Disallow: /akses-pesanan', $body);
        $this->assertStringContainsString('Disallow: /akun', $body);
        $this->assertStringContainsString('Disallow: /admin', $body);
        $this->assertStringContainsString('Disallow: /api/', $body);
        $sitemapUrl = preg_replace('/\Ahttp:/i', 'https:', route('storefront.sitemap')) ?: route('storefront.sitemap');
        $this->assertStringContainsString('Sitemap: '.$sitemapUrl, $body);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function jsonLdDocuments(string $html): array
    {
        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches);

        return array_map(
            static fn (string $document): array => json_decode($document, true, 512, JSON_THROW_ON_ERROR),
            $matches[1] ?? [],
        );
    }
}
