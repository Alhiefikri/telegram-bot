<?php

namespace App\Storefront;

use App\Models\Product;
use Illuminate\Http\Request;

class StorefrontUrl
{
    public function catalogUrl(?int $productId = null, ?string $search = null): string
    {
        $query = trim((string) $search);
        $url = $query === ''
            ? url('/#products')
            : url('/').'?q='.rawurlencode($query).'#products';

        return $productId === null ? $url : preg_replace('/#products$/', '#product-'.$productId, $url);
    }

    public function shopCatalogUrl(StorefrontCatalogQuery $query, bool $withFragment = false): string
    {
        $url = route('storefront.shop.index');
        $parameters = $query->toUrlParameters();

        if ($parameters !== []) {
            $url .= '?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        }

        return $withFragment ? $url.'#products' : $url;
    }

    public function productUrl(Product $product, ?string $returnUrl = null): string
    {
        return $this->productUrlForId((int) $product->getKey(), $returnUrl);
    }

    public function productUrlForId(int $productId, ?string $returnUrl = null): string
    {
        return route('storefront.products.show', ['product' => $productId] + $this->localReturnParameters($returnUrl));
    }

    public function checkoutProductUrl(Product $product, ?string $returnUrl = null): string
    {
        return $this->checkoutProductUrlForId((int) $product->getKey(), $returnUrl);
    }

    public function checkoutProductUrlForId(int $productId, ?string $returnUrl = null): string
    {
        return route('storefront.shop.checkout.product', ['product' => $productId] + $this->localReturnParameters($returnUrl));
    }

    public function checkoutSubmitUrl(?string $returnUrl = null): string
    {
        return route('storefront.shop.checkout', $this->localReturnParameters($returnUrl));
    }

    public function checkoutReturnUrl(Request $request, Product $product): string
    {
        $from = $request->query('from');

        return is_string($from) && $this->isLocalUrl($from)
            ? $from
            : $this->productUrl($product);
    }

    public function checkoutPageSource(?string $returnUrl): string
    {
        $path = $this->localPath($returnUrl);

        return is_string($path) && str_starts_with($path, '/produk-keluarga/')
            ? 'family_direct_checkout_page'
            : 'direct_checkout_page';
    }

    /**
     * Source-aware back-link label for the checkout review page. The label is
     * derived from the same bounded same-origin URL that the link points at,
     * so the two can never disagree for entry points owned by StorefrontUrl.
     */
    public function checkoutReturnLabel(?string $returnUrl): string
    {
        $path = $this->localPath($returnUrl);

        return match (true) {
            is_string($path) && str_starts_with($path, '/produk-keluarga/') => 'Kembali ke pilihan',
            is_string($path) && str_starts_with($path, '/shop/cart') => 'Kembali ke keranjang',
            is_string($path) && str_starts_with($path, '/produk/') => 'Kembali ke produk',
            default => 'Kembali ke katalog',
        };
    }

    private function localPath(?string $url): ?string
    {
        return is_string($url) && $this->isLocalUrl($url)
            ? parse_url($url, PHP_URL_PATH)
            : null;
    }

    public function waitlistUrlForId(int $productId): string
    {
        return route('storefront.waitlist.store', ['product' => $productId]);
    }

    /**
     * @return array<string, string>
     */
    private function localReturnParameters(?string $returnUrl): array
    {
        return is_string($returnUrl) && $this->isLocalUrl($returnUrl)
            ? ['from' => $returnUrl]
            : [];
    }

    public function familyUrl(string $slug, ?string $returnUrl = null): string
    {
        $url = route('storefront.product-families.show', ['productFamily' => $slug]);

        if ($returnUrl === null || $returnUrl === '' || ! $this->isLocalUrl($returnUrl)) {
            return $url;
        }

        return $url.'?from='.rawurlencode($returnUrl);
    }

    public function familyReturnUrl(Request $request, string $slug): string
    {
        $from = $request->query('from');

        if (is_string($from) && $this->isLocalUrl($from)) {
            return $from;
        }

        return $this->familyUrl($slug);
    }

    public function checkoutRecoveryUrl(mixed $productId, ?string $returnUrl = null): string
    {
        $normalizedProductId = is_int($productId)
            ? $productId
            : (is_string($productId) && ctype_digit($productId) ? (int) $productId : null);

        if ($normalizedProductId !== null && $normalizedProductId > 0) {
            $product = Product::query()->whereKey($normalizedProductId)->first();

            if ($product?->isPublicVisible()) {
                return $this->checkoutProductUrl($product, $returnUrl);
            }
        }

        return $this->catalogUrl();
    }

    public function productReturnUrl(Request $request, Product $product): string
    {
        $from = $request->query('from');

        if (is_string($from) && $this->isLocalUrl($from)) {
            return $from;
        }

        return $this->catalogUrl((int) $product->id);
    }

    public function telegramProductStartUrl(Product $product): string
    {
        return $this->telegramProductStartUrlForId((int) $product->id);
    }

    public function telegramProductStartUrlForId(int $productId): string
    {
        $base = (string) (config('storefront.links.telegram_bot_url') ?: 'https://t.me/lhiestore_bot?start=landing');

        if (preg_match('/([?&])start=[^&]*/', $base) === 1) {
            return preg_replace('/([?&])start=[^&]*/', '$1start=product_'.$productId, $base) ?: $base;
        }

        return $base.(str_contains($base, '?') ? '&' : '?').'start=product_'.$productId;
    }

    private function isLocalUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > 2048) {
            return false;
        }

        if (str_starts_with($url, '/')) {
            // Browsers normalize a backslash right after the slash into another
            // slash, so "/\host/path" would resolve as a protocol-relative URL.
            return ! str_starts_with($url, '//') && ! str_starts_with($url, '/\\');
        }

        $parts = parse_url($url);
        $app = parse_url(url('/'));

        if (! is_array($parts) || ! is_array($app)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        return $scheme !== ''
            && $host !== ''
            && $scheme === strtolower((string) ($app['scheme'] ?? ''))
            && $host === strtolower((string) ($app['host'] ?? ''))
            && $this->effectivePort($parts) === $this->effectivePort($app);
    }

    /**
     * Explicit port when present, otherwise the scheme's default port.
     *
     * @param  array<string, int|string>  $parts
     */
    private function effectivePort(array $parts): ?int
    {
        return isset($parts['port'])
            ? (int) $parts['port']
            : match (strtolower((string) ($parts['scheme'] ?? ''))) {
                'http' => 80,
                'https' => 443,
                default => null,
            };
    }
}
