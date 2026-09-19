<?php

namespace App\Services\Pricing;

use App\Models\Product;
use InvalidArgumentException;

final class ProductQuantityPricing
{
    public const MAX_TIERS = 5;

    public const MINIMUM_TIER_QUANTITY = 2;

    public const MAXIMUM_TIER_QUANTITY = 20;

    public function quote(Product $product, int $quantity): ProductPriceQuote
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Product quantity must be positive.');
        }

        $tierQuery = $product->quantityPriceTiers()
            ->select(['id', 'product_id', 'minimum_quantity', 'price_amount', 'usdt_price_amount']);
        $tiers = (clone $tierQuery)->limit(self::MAX_TIERS)->get();

        if ($tiers->count() === self::MAX_TIERS
            && (clone $tierQuery)->offset(self::MAX_TIERS)->limit(1)->exists()) {
            throw $this->invalidSchedule('more than five tiers are configured');
        }

        $baseIdrUnitAmount = (int) $product->price_amount;
        $baseUsdtUnitAmount = $product->usdt_price_amount === null
            ? null
            : (int) $product->usdt_price_amount;
        $orderedTiers = [];
        $previousMinimum = null;
        $previousIdrUnitAmount = $baseIdrUnitAmount;
        $previousUsdtUnitAmount = $baseUsdtUnitAmount;

        foreach ($tiers as $tier) {
            $minimumQuantity = (int) $tier->minimum_quantity;
            if ($minimumQuantity < self::MINIMUM_TIER_QUANTITY
                || $minimumQuantity > self::MAXIMUM_TIER_QUANTITY
                || ($previousMinimum !== null && $minimumQuantity <= $previousMinimum)) {
                throw $this->invalidSchedule('tier minimum quantities are not strictly ordered within the supported range');
            }

            $priceAmount = (int) $tier->price_amount;
            if ($priceAmount <= 0 || $priceAmount >= $previousIdrUnitAmount) {
                throw $this->invalidSchedule('IDR prices must be positive and strictly lower than the preceding price');
            }

            $usdtPriceAmount = $tier->usdt_price_amount === null
                ? null
                : (int) $tier->usdt_price_amount;
            if ($baseUsdtUnitAmount === null) {
                if ($usdtPriceAmount !== null) {
                    throw $this->invalidSchedule('USDT prices must be absent when the Product has no base USDT price');
                }
            } elseif ($usdtPriceAmount === null
                || $usdtPriceAmount <= 0
                || $usdtPriceAmount >= $previousUsdtUnitAmount) {
                throw $this->invalidSchedule('USDT prices must be complete, positive, and strictly lower than the preceding price');
            }

            $orderedTiers[] = new ProductPriceTierQuote(
                minimumQuantity: $minimumQuantity,
                priceAmount: $priceAmount,
                usdtPriceAmount: $usdtPriceAmount,
            );
            $previousMinimum = $minimumQuantity;
            $previousIdrUnitAmount = $priceAmount;
            $previousUsdtUnitAmount = $usdtPriceAmount;
        }

        $appliedTier = null;
        $nextTier = null;
        foreach ($orderedTiers as $tier) {
            if ($quantity >= $tier->minimumQuantity) {
                $appliedTier = $tier;
                continue;
            }

            $nextTier ??= $tier;
        }

        $effectiveIdrUnitAmount = $appliedTier?->priceAmount ?? $baseIdrUnitAmount;
        $effectiveUsdtUnitAmount = $baseUsdtUnitAmount === null
            ? null
            : ($appliedTier?->usdtPriceAmount ?? $baseUsdtUnitAmount);

        return new ProductPriceQuote(
            productId: (int) $product->getKey(),
            quantity: $quantity,
            baseIdrUnitAmount: $baseIdrUnitAmount,
            effectiveIdrUnitAmount: $effectiveIdrUnitAmount,
            idrSubtotal: $this->multiply($effectiveIdrUnitAmount, $quantity, 'IDR'),
            baseUsdtUnitAmount: $baseUsdtUnitAmount,
            effectiveUsdtUnitAmount: $effectiveUsdtUnitAmount,
            usdtSubtotal: $effectiveUsdtUnitAmount === null
                ? null
                : $this->multiply($effectiveUsdtUnitAmount, $quantity, 'USDT'),
            appliedTierMinimum: $appliedTier?->minimumQuantity,
            nextTier: $nextTier,
            orderedTiers: $orderedTiers,
        );
    }

    private function multiply(int $unitAmount, int $quantity, string $currency): int
    {
        if ($unitAmount > 0 && $quantity > intdiv(PHP_INT_MAX, $unitAmount)) {
            throw new InvalidArgumentException("{$currency} Product subtotal exceeds the supported integer range.");
        }

        if ($unitAmount < 0 && $unitAmount < intdiv(PHP_INT_MIN, $quantity)) {
            throw new InvalidArgumentException("{$currency} Product subtotal exceeds the supported integer range.");
        }

        return $unitAmount * $quantity;
    }

    private function invalidSchedule(string $reason): InvalidArgumentException
    {
        return new InvalidArgumentException("Product quantity price schedule is invalid: {$reason}.");
    }
}
