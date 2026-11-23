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
        'customer.account', 'customer.profile.edit', 'customer.orders.index',
        'customer.balance.show', 'customer.security', 'customer.claim.create',
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
            'customer.orders.index' => array_filter($request->query->all(), fn ($value, $key) => is_string($value) && in_array($key, ['status', 'page'], true), ARRAY_FILTER_USE_BOTH),
            'customer.balance.show' => array_filter($request->query->all(), fn ($value, $key) => is_string($value) && in_array($key, ['currency', 'direction', 'cursor'], true), ARRAY_FILTER_USE_BOTH),
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
