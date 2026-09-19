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
        public int $baseIdrSubtotal,
        public int $idrSubtotal,
        public ?int $baseUsdtUnitAmount,
        public ?int $effectiveUsdtUnitAmount,
        public ?int $baseUsdtSubtotal,
        public ?int $usdtSubtotal,
        public ?int $appliedTierMinimum,
        public ?ProductPriceTierQuote $nextTier,
        public array $orderedTiers,
    ) {
        if ($quantity < 1) {
            throw new InvalidArgumentException('A Product price quote requires a positive quantity.');
        }

        if ($baseIdrSubtotal < 0 || $idrSubtotal < 0) {
            throw new InvalidArgumentException('A Product price quote cannot contain negative IDR subtotals.');
        }

        if ($baseUsdtUnitAmount === null
            && ($effectiveUsdtUnitAmount !== null || $baseUsdtSubtotal !== null || $usdtSubtotal !== null)) {
            throw new InvalidArgumentException('A Product price quote cannot contain USDT amounts without a base USDT price.');
        }

        if ($baseUsdtUnitAmount !== null
            && ($effectiveUsdtUnitAmount === null || $baseUsdtSubtotal === null || $usdtSubtotal === null)) {
            throw new InvalidArgumentException('A Product price quote with a base USDT price requires a complete USDT quote.');
        }

        if (($baseUsdtSubtotal ?? 0) < 0 || ($usdtSubtotal ?? 0) < 0) {
            throw new InvalidArgumentException('A Product price quote cannot contain negative USDT subtotals.');
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

    public function idrSavings(): int
    {
        return max(0, $this->baseIdrSubtotal - $this->idrSubtotal);
    }

    public function usdtSavings(): ?int
    {
        if ($this->baseUsdtSubtotal === null || $this->usdtSubtotal === null) {
            return null;
        }

        return max(0, $this->baseUsdtSubtotal - $this->usdtSubtotal);
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
