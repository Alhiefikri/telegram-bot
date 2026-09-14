<?php

namespace Tests\Feature;

use App\Models\ManualPaymentSetting;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Storefront\StorefrontUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class StorefrontSameOriginReturnUrlTest extends TestCase
{
    use RefreshDatabase;

    private const CANONICAL_URL = 'https://shop.example';

    /**
     * Absolute URLs that share the host but not the canonical origin, plus
     * protocol-relative and scheme-less forms that must never be trusted.
     *
     * @var array<string, string>
     */
    private const REJECTED_RETURN_URLS = [
        'different scheme' => 'http://shop.example/produk/1',
        'explicit non-default port' => 'https://shop.example:8443/produk/1',
        'wrong port for scheme' => 'https://shop.example:80/produk/1',
        'external host' => 'https://evil.example/produk/1',
        'host suffix trick' => 'https://shop.example.evil.example/produk/1',
        'subdomain host' => 'https://sub.shop.example/produk/1',
        'protocol-relative own host' => '//shop.example/produk/1',
        'protocol-relative external host' => '//evil.example/produk/1',
        'protocol-relative triple slash' => '///evil.example/produk/1',
        'scheme-less host path' => 'shop.example/produk/1',
        'missing host after scheme' => 'https:/shop.example/produk/1',
        'backslash protocol-relative form' => '/\evil.example/produk/1',
        'leading double-backslash form' => '\\\\evil.example/produk/1',
        'host with trailing dot' => 'https://shop.example./produk/1',
        'host with trailing space' => 'https://shop.example /produk/1',
    ];

    /**
     * Same-origin absolute URLs (including an explicit default port) and
     * relative paths that every return-context entry point must accept.
     *
     * @var array<string, string>
     */
    private const ACCEPTED_RETURN_URLS = [
        'absolute same-origin path' => 'https://shop.example/shop?page=2#product-9',
        'absolute explicit default port' => 'https://shop.example:443/shop#products',
        'relative path' => '/shop?page=2#product-9',
        'relative root' => '/',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('app.url', self::CANONICAL_URL);
        app('url')->useOrigin(self::CANONICAL_URL);
        app('url')->forceScheme('https');
        config()->set('services.storefront.telegram_reservation_floor', 0);
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'is_active' => true,
        ]);
    }

    private function urls(): StorefrontUrl
    {
        return app(StorefrontUrl::class);
    }

    private function requestFrom(string $from): Request
    {
        return Request::create('/shop/checkout', 'GET', ['from' => $from]);
    }

    public function test_checkout_return_url_rejects_urls_that_are_not_true_same_origin(): void
    {
        $product = Product::factory()->create(['name' => 'Origin Checkout Product', 'stock' => 2, 'reserved_stock' => 0]);
        $fallback = $this->urls()->productUrl($product);

        foreach (self::REJECTED_RETURN_URLS as $case => $from) {
            $this->assertSame(
                $fallback,
                $this->urls()->checkoutReturnUrl($this->requestFrom($from), $product),
                "Expected fallback for case: {$case}",
            );
        }
    }

    public function test_checkout_return_url_accepts_same_origin_absolute_and_relative_urls(): void
    {
        $product = Product::factory()->create(['name' => 'Origin Checkout Product', 'stock' => 2, 'reserved_stock' => 0]);

        foreach (self::ACCEPTED_RETURN_URLS as $case => $from) {
            $this->assertSame(
                $from,
                $this->urls()->checkoutReturnUrl($this->requestFrom($from), $product),
                "Expected echo for case: {$case}",
            );
        }
    }

    public function test_product_detail_return_url_rejects_urls_that_are_not_true_same_origin(): void
    {
        $product = Product::factory()->create(['name' => 'Origin Product Detail', 'stock' => 2, 'reserved_stock' => 0]);
        $fallback = self::CANONICAL_URL.'/#product-'.$product->getKey();

        foreach (self::REJECTED_RETURN_URLS as $case => $from) {
            $this->assertSame(
                $fallback,
                $this->urls()->productReturnUrl($this->requestFrom($from), $product),
                "Expected fallback for case: {$case}",
            );
        }

        foreach (self::ACCEPTED_RETURN_URLS as $case => $from) {
            $this->assertSame(
                $from,
                $this->urls()->productReturnUrl($this->requestFrom($from), $product),
                "Expected echo for case: {$case}",
            );
        }
    }

    public function test_family_return_url_rejects_urls_that_are_not_true_same_origin(): void
    {
        $fallback = route('storefront.product-families.show', ['productFamily' => 'origin-family']);

        foreach (self::REJECTED_RETURN_URLS as $case => $from) {
            $this->assertSame(
                $fallback,
                $this->urls()->familyReturnUrl($this->requestFrom($from), 'origin-family'),
                "Expected fallback for case: {$case}",
            );
        }

        foreach (self::ACCEPTED_RETURN_URLS as $case => $from) {
            $this->assertSame(
                $from,
                $this->urls()->familyReturnUrl($this->requestFrom($from), 'origin-family'),
                "Expected echo for case: {$case}",
            );
        }
    }

    public function test_family_urls_do_not_preserve_legacy_family_paths_as_return_context(): void
    {
        $canonical = route('storefront.product-families.show', ['productFamily' => 'origin-family']);
        $legacy = '/produk-keluarga/old-family';

        $this->assertSame(
            $canonical,
            $this->urls()->familyReturnUrl($this->requestFrom($legacy), 'origin-family'),
        );
        $this->assertSame(
            $canonical,
            $this->urls()->familyUrl('origin-family', $legacy),
        );
    }

    public function test_checkout_source_and_label_recognize_the_canonical_family_path(): void
    {
        $familyUrl = route('storefront.product-families.show', ['productFamily' => 'origin-family']);

        $this->assertSame('family_direct_checkout_page', $this->urls()->checkoutPageSource($familyUrl));
        $this->assertSame('Kembali ke pilihan', $this->urls()->checkoutReturnLabel($familyUrl));
    }

    public function test_generated_from_parameters_never_carry_non_same_origin_return_urls(): void
    {
        $product = Product::factory()->create(['name' => 'Origin Parameter Product', 'stock' => 2, 'reserved_stock' => 0]);
        $productId = (int) $product->getKey();
        $familySlug = 'origin-family';

        foreach (self::REJECTED_RETURN_URLS as $case => $from) {
            $this->assertSame(
                route('storefront.products.show', ['product' => $productId]),
                $this->urls()->productUrl($product, $from),
                "productUrl case: {$case}",
            );
            $this->assertSame(
                route('storefront.shop.checkout.product', ['product' => $productId]),
                $this->urls()->checkoutProductUrl($product, $from),
                "checkoutProductUrl case: {$case}",
            );
            $this->assertSame(
                route('storefront.shop.checkout'),
                $this->urls()->checkoutSubmitUrl($from),
                "checkoutSubmitUrl case: {$case}",
            );
            $this->assertSame(
                route('storefront.product-families.show', ['productFamily' => $familySlug]),
                $this->urls()->familyUrl($familySlug, $from),
                "familyUrl case: {$case}",
            );
        }

        foreach (self::ACCEPTED_RETURN_URLS as $case => $from) {
            $encoded = '?from='.rawurlencode($from);
            $this->assertSame(
                route('storefront.products.show', ['product' => $productId]).$encoded,
                $this->urls()->productUrl($product, $from),
                "productUrl case: {$case}",
            );
            $this->assertSame(
                route('storefront.shop.checkout.product', ['product' => $productId]).$encoded,
                $this->urls()->checkoutProductUrl($product, $from),
                "checkoutProductUrl case: {$case}",
            );
            $this->assertSame(
                route('storefront.shop.checkout').$encoded,
                $this->urls()->checkoutSubmitUrl($from),
                "checkoutSubmitUrl case: {$case}",
            );
            $this->assertSame(
                route('storefront.product-families.show', ['productFamily' => $familySlug]).$encoded,
                $this->urls()->familyUrl($familySlug, $from),
                "familyUrl case: {$case}",
            );
        }
    }

    public function test_checkout_return_url_matches_effective_port_when_canonical_url_has_explicit_port(): void
    {
        $product = Product::factory()->create(['name' => 'Origin Port Product', 'stock' => 2, 'reserved_stock' => 0]);
        $canonicalRequest = Request::create('http://127.0.0.1:8000/shop/checkout');
        $this->app->instance('request', $canonicalRequest);
        app('url')->setRequest($canonicalRequest);
        config()->set('app.url', 'http://127.0.0.1:8000');
        app('url')->forceScheme('http');
        app('url')->useOrigin('http://127.0.0.1:8000');

        $accepted = [
            'same explicit port' => 'http://127.0.0.1:8000/produk/1',
            'same explicit port other path' => 'http://127.0.0.1:8000/shop?page=2#products',
        ];
        $rejected = [
            'default port differs from canonical port' => 'http://127.0.0.1/produk/1',
            'other explicit port' => 'http://127.0.0.1:80/produk/1',
            'https default port differs from canonical port' => 'https://127.0.0.1:443/produk/1',
            'scheme mismatch on same port' => 'https://127.0.0.1:8000/produk/1',
        ];
        $fallback = $this->urls()->productUrl($product);

        foreach ($accepted as $case => $from) {
            $this->assertSame(
                $from,
                $this->urls()->checkoutReturnUrl($this->requestFrom($from), $product),
                "Expected echo for case: {$case}",
            );
        }

        foreach ($rejected as $case => $from) {
            $this->assertSame(
                $fallback,
                $this->urls()->checkoutReturnUrl($this->requestFrom($from), $product),
                "Expected fallback for case: {$case}",
            );
        }
    }

    public function test_failed_direct_checkout_recovery_never_redirects_to_external_return_url(): void
    {
        $product = Product::factory()->create(['name' => 'Origin Recovery Product', 'stock' => 2, 'reserved_stock' => 0]);
        // The acceptance criteria each map to one request; the storefront-checkout
        // limiter allows 5 attempts per minute per session, so stay under it.
        $rejectedSamples = [
            'scheme mismatch' => 'http://shop.example/produk/1',
            'explicit port mismatch' => 'https://shop.example:8443/produk/1',
            'protocol-relative' => '//evil.example/produk/1',
            'external host' => 'https://evil.example/produk/1',
        ];

        foreach ($rejectedSamples as $case => $from) {
            $response = $this->post(
                route('storefront.shop.checkout').'?from='.rawurlencode($from),
                ['product_id' => $product->getKey()],
            );

            $response->assertRedirect(route('storefront.shop.checkout.product', ['product' => $product->getKey()]));
            $response->assertSessionHasErrors();

            $this->assertStringNotContainsString(
                rawurlencode($from),
                $response->headers->get('Location') ?? '',
                "Redirect location must not embed case: {$case}",
            );
        }
    }

    public function test_checkout_review_page_back_link_never_points_at_external_return_url(): void
    {
        $product = Product::factory()->create(['name' => 'Origin Review Product', 'stock' => 2, 'reserved_stock' => 0]);
        $attackerUrls = [
            'external host' => 'https://evil.example/steal-session?x=1',
            'explicit port mismatch' => 'https://shop.example:8443/steal-session?x=1',
            'protocol-relative' => '//evil.example/steal-session?x=1',
        ];

        foreach ($attackerUrls as $case => $attackerUrl) {
            $html = $this->get(route('storefront.shop.checkout.product', [
                'product' => $product,
                'from' => $attackerUrl,
            ]))->assertOk()->getContent();

            $this->assertStringContainsString(
                'href="'.route('storefront.products.show', $product).'"',
                $html,
                "Fallback back link expected for case: {$case}",
            );
            $this->assertStringNotContainsString('steal-session', $html, "Case: {$case}");
            $this->assertStringNotContainsString('evil.example', $html, "Case: {$case}");
            $this->assertStringNotContainsString(':8443', $html, "Case: {$case}");
        }
    }

    public function test_checkout_review_page_back_link_accepts_same_origin_absolute_and_relative_urls(): void
    {
        $product = Product::factory()->create(['name' => 'Origin Review Product', 'stock' => 2, 'reserved_stock' => 0]);
        $sameOriginUrl = self::CANONICAL_URL.'/shop?page=2#product-'.$product->getKey();

        $html = $this->get(route('storefront.shop.checkout.product', [
            'product' => $product,
            'from' => $sameOriginUrl,
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('href="'.$sameOriginUrl.'"', $html);

        $html = $this->get(route('storefront.shop.checkout.product', [
            'product' => $product,
            'from' => '/shop#products',
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('href="/shop#products"', $html);
    }

    public function test_product_and_family_pages_never_render_external_return_links(): void
    {
        $product = Product::factory()->create(['name' => 'Origin Catalog Product', 'stock' => 2, 'reserved_stock' => 0]);
        $family = ProductFamily::factory()->create(['slug' => 'origin-family', 'name' => 'Origin Family']);
        Product::factory()->create([
            'name' => 'Origin Family Member',
            'product_family_id' => $family->id,
            'family_option_label' => 'Paket A',
            'stock' => 2,
            'reserved_stock' => 0,
        ]);
        $attackerUrls = [
            'external host' => 'https://evil.example/steal-context?x=1',
            'explicit port mismatch' => 'https://shop.example:8443/steal-context?x=1',
            'protocol-relative' => '//shop.example/steal-context?x=1',
        ];

        foreach ($attackerUrls as $case => $attackerUrl) {
            $productHtml = $this->get(route('storefront.products.show', [
                'product' => $product,
                'from' => $attackerUrl,
            ]))->assertOk()->getContent();
            $this->assertStringContainsString(
                'href="'.self::CANONICAL_URL.'/#product-'.$product->getKey().'"',
                $productHtml,
                "Product fallback link expected for case: {$case}",
            );
            $this->assertStringNotContainsString('steal-context', $productHtml, "Case: {$case}");
            $this->assertStringNotContainsString('evil.example', $productHtml, "Case: {$case}");

            $familyHtml = $this->get(route('storefront.product-families.show', [
                'productFamily' => $family->slug,
                'from' => $attackerUrl,
            ]))->assertOk()->getContent();
            $this->assertStringNotContainsString('steal-context', $familyHtml, "Case: {$case}");
            $this->assertStringNotContainsString('evil.example', $familyHtml, "Case: {$case}");
        }
    }
}
