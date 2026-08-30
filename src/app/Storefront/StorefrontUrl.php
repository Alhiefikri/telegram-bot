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

    public function productUrl(Product $product, ?string $returnUrl = null, bool $checkout = false): string
    {
        $parameters = ['product' => $product];

        if ($checkout) {
            $parameters['checkout'] = 1;
        }

        if ($returnUrl !== null && $returnUrl !== '') {
            $parameters['from'] = $returnUrl;
        }

        return route('storefront.products.show', $parameters);
    }

    public function familyUrl(string $slug, ?string $returnUrl = null): string
    {
        $url = route('storefront.product-families.show', ['productFamily' => $slug]);

        return $returnUrl === null || $returnUrl === ''
            ? $url
            : $url.'?from='.rawurlencode($returnUrl);
    }

    public function familyReturnUrl(Request $request, string $slug): string
    {
        $from = $request->query('from');

        if (is_string($from) && $this->isLocalUrl($from)) {
            return $from;
        }

        return $this->familyUrl($slug);
    }

    public function checkoutRecoveryUrl(mixed $productId): string
    {
        if (is_scalar($productId)) {
            $product = Product::query()->whereKey((int) $productId)->first();

            if ($product?->isPublicVisible()) {
                return $this->productUrl($product, checkout: true);
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
        if (str_starts_with($url, '/')) {
            return ! str_starts_with($url, '//');
        }

        $appHost = parse_url(url('/'), PHP_URL_HOST);
        $urlHost = parse_url($url, PHP_URL_HOST);

        return is_string($appHost) && $appHost !== '' && $urlHost === $appHost;
    }
}
