<?php

namespace App\Storefront;

use App\Models\Product;

final readonly class StorefrontDirectCheckoutReviewSource implements StorefrontCheckoutReviewSource
{
    public function __construct(
        public Product $product,
        public int $quantity,
        public ?string $returnUrl = null,
    ) {}
}
