<?php

namespace App\Services\Pricing;

use App\Models\Product;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class ProductQuantityPricing
{
    public const MAX_TIERS = 5;

    public const MINIMUM_TIER_QUANTITY = 2;

    public const MAXIMUM_TIER_QUANTITY = 20;

    /**
     * Quote one Product for a quantity and channel.
     *
     * A trusted reseller context lets a Product-owned reseller override replace
     * that currency's schedule: the override is the final per-unit amount, never
     * stacked with a quantity tier, applies independently per currency, and a
     * null override keeps the normal quantity quote.
     */
    public function quote(Product $product, int $quantity, ?ResellerPricingContext $context = null): ProductPriceQuote
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Product quantity must be positive.');
        }

        $tiers = $product->relationLoaded('quantityPriceTiers')
            ? $product->quantityPriceTiers
            : ($product->exists
                ? $product->quantityPriceTiers()
                    ->select(['id', 'product_id', 'minimum_quantity', 'price_amount', 'usdt_price_amount'])
                    ->limit(self::MAX_TIERS + 1)->get()
                : collect());

        $baseIdrUnitAmount = (int) $product->price_amount;
        $baseUsdtUnitAmount = $product->usdt_price_amount === null
            ? null
            : (int) $product->usdt_price_amount;
        $normalizedTiers = $this->validatedTierValues(
            $baseIdrUnitAmount,
            $baseUsdtUnitAmount,
            collect($tiers)->map(static fn ($tier): array => [
                'minimum_quantity' => $tier->minimum_quantity,
                'price_amount' => $tier->price_amount,
                'usdt_price_amount' => $tier->usdt_price_amount,
            ])->all(),
        );
        $orderedTiers = array_map(
            static fn (array $tier): ProductPriceTierQuote => new ProductPriceTierQuote(
                minimumQuantity: $tier['minimum_quantity'],
                priceAmount: $tier['price_amount'],
                usdtPriceAmount: $tier['usdt_price_amount'],
            ),
            $normalizedTiers,
        );

        $appliedTier = null;
        $nextTier = null;
        foreach ($orderedTiers as $tier) {
            if ($quantity >= $tier->minimumQuantity) {
                $appliedTier = $tier;

                continue;
            }

            $nextTier ??= $tier;
        }

        $resellerIdrUnitAmount = $this->resellerUnitAmount($product->reseller_price_amount ?? null, $context !== null, 'IDR');
        $resellerUsdtUnitAmount = $baseUsdtUnitAmount === null
            ? null
            : $this->resellerUnitAmount($product->reseller_usdt_price_amount ?? null, $context !== null, 'USDT');

        $effectiveIdrUnitAmount = $resellerIdrUnitAmount ?? $appliedTier?->priceAmount ?? $baseIdrUnitAmount;
        $effectiveUsdtUnitAmount = $baseUsdtUnitAmount === null
            ? null
            : ($resellerUsdtUnitAmount ?? $appliedTier?->usdtPriceAmount ?? $baseUsdtUnitAmount);
        $appliedTierMinimum = $resellerIdrUnitAmount === null ? $appliedTier?->minimumQuantity : null;
        $appliedUsdtTierMinimum = $baseUsdtUnitAmount === null || $resellerUsdtUnitAmount !== null
            ? null
            : $appliedTier?->minimumQuantity;

        $baseIdrSubtotal = $this->multiply($baseIdrUnitAmount, $quantity, 'IDR');
        $baseUsdtSubtotal = $baseUsdtUnitAmount === null
            ? null
            : $this->multiply($baseUsdtUnitAmount, $quantity, 'USDT');

        return new ProductPriceQuote(
            productId: (int) $product->getKey(),
            quantity: $quantity,
            baseIdrUnitAmount: $baseIdrUnitAmount,
            effectiveIdrUnitAmount: $effectiveIdrUnitAmount,
            baseIdrSubtotal: $baseIdrSubtotal,
            idrSubtotal: $this->multiply($effectiveIdrUnitAmount, $quantity, 'IDR'),
            baseUsdtUnitAmount: $baseUsdtUnitAmount,
            effectiveUsdtUnitAmount: $effectiveUsdtUnitAmount,
            baseUsdtSubtotal: $baseUsdtSubtotal,
            usdtSubtotal: $effectiveUsdtUnitAmount === null
                ? null
                : $this->multiply($effectiveUsdtUnitAmount, $quantity, 'USDT'),
            appliedTierMinimum: $appliedTierMinimum,
            appliedUsdtTierMinimum: $appliedUsdtTierMinimum,
            resellerIdrUnitAmount: $resellerIdrUnitAmount,
            resellerUsdtUnitAmount: $resellerUsdtUnitAmount,
            nextTier: $nextTier,
            orderedTiers: $orderedTiers,
        );
    }

    /**
     * Quote one bounded page of Products for the same quantity and channel.
     *
     * The page must be loaded with the `quantityPriceTiers` relation
     * (`with('quantityPriceTiers')`): one bulk relation query for the whole page
     * is the caller's responsibility, and an unprepared page is rejected instead
     * of falling back to one tier query per Product.
     *
     * @param  Collection<int, Product>  $products
     * @return array<int, ProductPriceQuote> keyed by Product ID
     */
    public function quoteMany(Collection $products, int $quantity, ?ResellerPricingContext $context = null): array
    {
        foreach ($products as $product) {
            if (! $product instanceof Product) {
                throw new InvalidArgumentException('A Product price quote page requires Product models.');
            }

            if (! $product->relationLoaded('quantityPriceTiers')) {
                throw new InvalidArgumentException('A Product price quote page requires the quantityPriceTiers relation to be eager loaded.');
            }
        }

        $quotes = [];
        foreach ($products as $product) {
            $quotes[(int) $product->getKey()] = $this->quote($product, $quantity, $context);
        }

        return $quotes;
    }

    /**
     * Validate a complete Product-owned schedule before it is persisted.
     *
     * @param  list<array{minimum_quantity:mixed, price_amount:mixed, usdt_price_amount:mixed}>  $tiers
     */
    public function validateSchedule(int $baseIdrUnitAmount, ?int $baseUsdtUnitAmount, array $tiers): void
    {
        $this->validatedTierValues($baseIdrUnitAmount, $baseUsdtUnitAmount, $tiers);
    }

    /**
     * Validate the optional Product-owned reseller overrides before persistence.
     *
     * Both currencies are independent and optional; a reseller USDT override is
     * only valid while the Product keeps a base USDT price, so an override can
     * never manufacture dollar eligibility. The reseller IDR override is
     * normalized when the caller passes it.
     */
    public function validateResellerOverrides(?int $resellerIdrUnitAmount, ?int $baseUsdtUnitAmount, ?int $resellerUsdtUnitAmount): void
    {
        if ($resellerIdrUnitAmount !== null && $resellerIdrUnitAmount <= 0) {
            throw $this->invalidResellerPrice('the reseller IDR price must be positive');
        }

        if ($resellerUsdtUnitAmount === null) {
            return;
        }

        if ($resellerUsdtUnitAmount <= 0) {
            throw $this->invalidResellerPrice('the reseller USDT price must be positive');
        }

        if ($baseUsdtUnitAmount === null) {
            throw $this->invalidResellerPrice('the reseller USDT price requires a base USDT price');
        }
    }

    /**
     * @param  list<array{minimum_quantity:mixed, price_amount:mixed, usdt_price_amount:mixed}>  $tiers
     * @return list<array{minimum_quantity:int, price_amount:int, usdt_price_amount:?int}>
     */
    private function validatedTierValues(int $baseIdrUnitAmount, ?int $baseUsdtUnitAmount, array $tiers): array
    {
        if (count($tiers) > self::MAX_TIERS) {
            throw $this->invalidSchedule('more than five tiers are configured');
        }

        $normalized = [];
        $previousMinimum = null;
        $previousIdrUnitAmount = $baseIdrUnitAmount;
        $previousUsdtUnitAmount = $baseUsdtUnitAmount;

        foreach ($tiers as $tier) {
            $minimumQuantity = (int) ($tier['minimum_quantity'] ?? 0);
            if ($minimumQuantity < self::MINIMUM_TIER_QUANTITY
                || $minimumQuantity > self::MAXIMUM_TIER_QUANTITY
                || ($previousMinimum !== null && $minimumQuantity <= $previousMinimum)) {
                throw $this->invalidSchedule('tier minimum quantities are not strictly ordered within the supported range');
            }

            $priceAmount = (int) ($tier['price_amount'] ?? 0);
            if ($priceAmount <= 0 || $priceAmount >= $previousIdrUnitAmount) {
                throw $this->invalidSchedule('IDR prices must be positive and strictly lower than the preceding price');
            }

            $usdtPriceAmount = ($tier['usdt_price_amount'] ?? null) === null
                ? null
                : (int) $tier['usdt_price_amount'];
            if ($baseUsdtUnitAmount === null) {
                if ($usdtPriceAmount !== null) {
                    throw $this->invalidSchedule('USDT prices must be absent when the Product has no base USDT price');
                }
            } elseif ($usdtPriceAmount === null
                || $usdtPriceAmount <= 0
                || $usdtPriceAmount >= $previousUsdtUnitAmount) {
                throw $this->invalidSchedule('USDT prices must be complete, positive, and strictly lower than the preceding price');
            }

            $normalized[] = [
                'minimum_quantity' => $minimumQuantity,
                'price_amount' => $priceAmount,
                'usdt_price_amount' => $usdtPriceAmount,
            ];
            $previousMinimum = $minimumQuantity;
            $previousIdrUnitAmount = $priceAmount;
            $previousUsdtUnitAmount = $usdtPriceAmount;
        }

        return $normalized;
    }

    /**
     * A reseller override only applies inside a trusted reseller context.
     *
     * Persisted overrides are positive by contract; a non-positive stored amount
     * fails closed instead of silently becoming free or retail pricing.
     */
    private function resellerUnitAmount(mixed $override, bool $hasResellerContext, string $currency): ?int
    {
        if (! $hasResellerContext || $override === null) {
            return null;
        }

        $amount = (int) $override;
        if ($amount <= 0) {
            throw $this->invalidResellerPrice("the reseller {$currency} price must be positive");
        }

        return $amount;
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

    private function invalidResellerPrice(string $reason): InvalidArgumentException
    {
        return new InvalidArgumentException("Product reseller price schedule is invalid: {$reason}.");
    }
}
