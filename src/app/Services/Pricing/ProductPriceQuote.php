<?php

namespace App\Services\Pricing;

use InvalidArgumentException;

final readonly class ProductPriceQuote
{
    /**
     * @param  list<ProductPriceTierQuote>  $orderedTiers
     */
    public function __construct(
        public int $productId,
        public int $quantity,
        public int $baseIdrUnitAmount,
        public int $effectiveIdrUnitAmount,
        public int $idrSubtotal,
        public ?int $baseUsdtUnitAmount,
        public ?int $effectiveUsdtUnitAmount,
        public ?int $usdtSubtotal,
        public ?int $appliedTierMinimum,
        public ?ProductPriceTierQuote $nextTier,
        public array $orderedTiers,
    ) {
        if ($quantity < 1) {
            throw new InvalidArgumentException('A Product price quote requires a positive quantity.');
        }

        if ($baseUsdtUnitAmount === null
            && ($effectiveUsdtUnitAmount !== null || $usdtSubtotal !== null)) {
            throw new InvalidArgumentException('A Product price quote cannot contain USDT amounts without a base USDT price.');
        }

        if ($baseUsdtUnitAmount !== null
            && ($effectiveUsdtUnitAmount === null || $usdtSubtotal === null)) {
            throw new InvalidArgumentException('A Product price quote with a base USDT price requires a complete USDT quote.');
        }

        if ($appliedTierMinimum !== null
            && ! in_array($appliedTierMinimum, array_map(
                static fn (ProductPriceTierQuote $tier): int => $tier->minimumQuantity,
                $orderedTiers,
            ), true)) {
            throw new InvalidArgumentException('A Product price quote references an unknown applied tier.');
        }
    }

    public function hasAppliedTier(): bool
    {
        return $this->appliedTierMinimum !== null;
    }

    public function tiers(): array
    {
        return $this->orderedTiers;
    }

    public function nextTierMinimumQuantity(): ?int
    {
        return $this->nextTier?->minimumQuantity;
    }
}
