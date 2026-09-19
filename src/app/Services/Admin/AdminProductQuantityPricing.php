<?php

namespace App\Services\Admin;

use App\Models\Product;
use App\Services\Pricing\ProductQuantityPricing;

final class AdminProductQuantityPricing
{
    public function __construct(
        private readonly ProductQuantityPricing $pricing,
    ) {}

    /**
     * @return list<array{minimum_quantity:?int, price_amount:?int, usdt_price_amount:?int}>
     */
    public function normalizeInput(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(function (mixed $tier): array {
            $tier = is_array($tier) ? $tier : [];

            return [
                'minimum_quantity' => $this->integerInput($tier['minimum_quantity'] ?? null),
                'price_amount' => $this->integerInput($tier['price_amount'] ?? null),
                'usdt_price_amount' => $this->usdtInput($tier['usdt_price_amount'] ?? null),
            ];
        }, $value));
    }

    /**
     * @param  list<array{minimum_quantity:?int, price_amount:?int, usdt_price_amount:?int}>  $tiers
     */
    public function validateInput(mixed $value, int $baseIdrUnitAmount, ?int $baseUsdtUnitAmount): void
    {
        $this->pricing->validateSchedule(
            $baseIdrUnitAmount,
            $baseUsdtUnitAmount,
            $this->normalizeInput($value),
        );
    }

    /**
     * Replace the complete schedule. The caller must hold the Product lock and
     * the surrounding database transaction.
     *
     * @param  list<array{minimum_quantity:int, price_amount:int, usdt_price_amount:?int}>  $tiers
     * @return array{before:list<array{minimum_quantity:int, price_amount:int, usdt_price_amount:?int}>, after:list<array{minimum_quantity:int, price_amount:int, usdt_price_amount:?int}>}
     */
    public function replace(Product $product, array $tiers): array
    {
        $this->pricing->validateSchedule(
            (int) $product->price_amount,
            $product->usdt_price_amount === null ? null : (int) $product->usdt_price_amount,
            $tiers,
        );

        $before = $this->snapshot($product);
        $product->quantityPriceTiers()->delete();
        if ($tiers !== []) {
            $product->quantityPriceTiers()->createMany($tiers);
        }

        return [
            'before' => $before,
            'after' => $this->snapshot($product),
        ];
    }

    /**
     * @return list<array{minimum_quantity:int, price_amount:int, usdt_price_amount:?int}>
     */
    public function snapshot(Product $product): array
    {
        return $product->quantityPriceTiers()
            ->select(['minimum_quantity', 'price_amount', 'usdt_price_amount'])
            ->limit(ProductQuantityPricing::MAX_TIERS)
            ->get()
            ->map(static fn ($tier): array => [
                'minimum_quantity' => (int) $tier->minimum_quantity,
                'price_amount' => (int) $tier->price_amount,
                'usdt_price_amount' => $tier->usdt_price_amount === null ? null : (int) $tier->usdt_price_amount,
            ])
            ->all();
    }

    private function integerInput(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || ! preg_match('/^\d+$/', $value)) {
            return null;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT);

        return $parsed === false ? null : (int) $parsed;
    }

    private function usdtInput(mixed $value): ?int
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if (! preg_match('/^(\d+)(?:\.(\d{1,3}))?$/', $value, $matches)) {
            return null;
        }

        $whole = filter_var($matches[1], FILTER_VALIDATE_INT);
        if ($whole === false) {
            return null;
        }

        $fraction = (int) str_pad($matches[2] ?? '', 6, '0');
        if ($whole > intdiv(PHP_INT_MAX - $fraction, 1_000_000)) {
            return null;
        }

        return ((int) $whole * 1_000_000) + $fraction;
    }
}
