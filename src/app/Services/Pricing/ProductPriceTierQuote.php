<?php

namespace App\Services\Pricing;

final readonly class ProductPriceTierQuote
{
    public function __construct(
        public int $minimumQuantity,
        public int $priceAmount,
        public ?int $usdtPriceAmount,
    ) {}
}
