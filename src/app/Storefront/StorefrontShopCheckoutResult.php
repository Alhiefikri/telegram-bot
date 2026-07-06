<?php

namespace App\Storefront;

final readonly class StorefrontShopCheckoutResult
{
    public function __construct(
        public string $redirectUrl,
    ) {}
}
