<?php

namespace App\Services\Pricing;

use InvalidArgumentException;

final readonly class ProductPriceQuote
{
    /**
     * @param  list<ProductPriceTierQuote>  $orderedTiers
     * @param  ?int  $appliedUsdtTierMinimum  Tier minimum that determined the effective USDT unit price.
     * @param  ?int  $resellerIdrUnitAmount  Reseller IDR override that replaced the IDR schedule, when one applied.
     * @param  ?int  $resellerUsdtUnitAmount  Reseller USDT override that replaced the USDT schedule, when one applied.
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
        public ?int $appliedUsdtTierMinimum,
        public ?int $resellerIdrUnitAmount,
        public ?int $resellerUsdtUnitAmount,
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

        $tierMinimums = array_map(
            static fn (ProductPriceTierQuote $tier): int => $tier->minimumQuantity,
            $orderedTiers,
        );

        if ($appliedTierMinimum !== null && ! in_array($appliedTierMinimum, $tierMinimums, true)) {
            throw new InvalidArgumentException('A Product price quote references an unknown applied tier.');
        }

        if ($appliedUsdtTierMinimum !== null
            && ($baseUsdtUnitAmount === null || ! in_array($appliedUsdtTierMinimum, $tierMinimums, true))) {
            throw new InvalidArgumentException('A Product price quote references an unknown applied USDT tier.');
        }

        foreach (['IDR' => $resellerIdrUnitAmount, 'USDT' => $resellerUsdtUnitAmount] as $currency => $override) {
            if ($override !== null && $override <= 0) {
                throw new InvalidArgumentException("A reseller {$currency} unit price must be positive.");
            }
        }

        if ($resellerUsdtUnitAmount !== null && $baseUsdtUnitAmount === null) {
            throw new InvalidArgumentException('A reseller USDT unit price requires a base USDT price.');
        }

        if (($resellerIdrUnitAmount !== null && $appliedTierMinimum !== null)
            || ($resellerUsdtUnitAmount !== null && $appliedUsdtTierMinimum !== null)) {
            throw new InvalidArgumentException('A reseller unit price cannot stack with a quantity tier.');
        }

        if (($resellerIdrUnitAmount !== null && $effectiveIdrUnitAmount !== $resellerIdrUnitAmount)
            || ($resellerUsdtUnitAmount !== null && $effectiveUsdtUnitAmount !== $resellerUsdtUnitAmount)) {
            throw new InvalidArgumentException('A reseller unit price must be the final effective unit price.');
        }
    }

    public function hasAppliedTier(): bool
    {
        return $this->appliedTierMinimum !== null;
    }

    public function hasIdrResellerOverride(): bool
    {
        return $this->resellerIdrUnitAmount !== null;
    }

    public function hasUsdtResellerOverride(): bool
    {
        return $this->resellerUsdtUnitAmount !== null;
    }

    public function hasResellerOverride(): bool
    {
        return $this->hasIdrResellerOverride() || $this->hasUsdtResellerOverride();
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
