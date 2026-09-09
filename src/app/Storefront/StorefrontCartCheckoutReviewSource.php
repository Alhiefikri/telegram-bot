<?php

namespace App\Storefront;

use App\Models\Cart;

final readonly class StorefrontCartCheckoutReviewSource implements StorefrontCheckoutReviewSource
{
    public function __construct(
        public Cart $cart,
        public string $snapshot,
    ) {}
}
