<?php

namespace App\Storefront;

use Illuminate\Http\Request;

final class StorefrontLocaleReturn
{
    public const POST_ROUTES = [
        'storefront.shop.index', 'storefront.shop.cart', 'storefront.shop.checkout.start',
        'storefront.shop.checkout.product', 'storefront.shop.orders.show',
        'storefront.order-access.create', 'storefront.order-access.show', 'customer.orders.show',
        'storefront.products.show', 'storefront.product-families.show', 'storefront.guides.index',
        'storefront.login-guide', 'storefront.policies.privacy', 'storefront.policies.terms',
        'storefront.policies.refund', 'storefront.policies.recovery',
    ];

    public function __construct(private readonly StorefrontUrl $urls) {}

    public function path(Request $request, string $route): string
    {
        $uri = $request->getRequestUri();
        if ($route === 'storefront.shop.orders.show') {
            return $uri;
        }

        $query = match ($route) {
            'storefront.shop.index' => StorefrontCatalogQuery::fromRequest($request)->toUrlParameters(),
            'storefront.login-guide' => $request->only(['return', 'invoice']),
            'storefront.products.show', 'storefront.product-families.show',
            'storefront.shop.checkout.product', 'storefront.shop.checkout.start' => $this->purchaseQuery($request),
            default => [],
        };

        return explode('?', $uri, 2)[0].($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    private function purchaseQuery(Request $request): array
    {
        $from = $request->query('from');
        $quantity = $request->query('quantity');

        return array_filter([
            'from' => is_string($from) && $this->urls->isSafeReturnUrl($from) ? $from : null,
            'quantity' => is_string($quantity) && ctype_digit($quantity) ? $quantity : null,
        ], fn ($value) => $value !== null);
    }
}
