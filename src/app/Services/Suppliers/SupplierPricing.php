<?php

namespace App\Services\Suppliers;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierPricePolicy;
use App\Services\Pricing\ProductQuantityPricing;
use InvalidArgumentException;

final class SupplierPricing
{
    public function __construct(private readonly ProductQuantityPricing $retail) {}

    public function retailPrice(SupplierPricePolicy $policy, int $costIdr): int
    {
        $this->validatePolicy($policy);
        if ($costIdr < 0) {
            throw new InvalidArgumentException('Invalid supplier cost.');
        }
        $base = $this->add($costIdr, (int) $policy->buffer_idr);
        $percent = $this->ceilDiv($this->multiply($costIdr, (int) $policy->markup_basis_points), 10000);
        $profit = match ($policy->mode) {
            'percent' => $percent,
            'fixed' => (int) $policy->profit_idr,
            'hybrid' => max($percent, (int) $policy->profit_idr),
            default => throw new InvalidArgumentException('Manual pricing retains the Product retail price.'),
        };
        $amount = $this->add($base, $profit);

        return $this->multiply($this->ceilDiv($amount, (int) $policy->rounding_idr), (int) $policy->rounding_idr);
    }

    /** Total cost, with quantity-specific unit tiers and then the best applicable bulk discount. */
    public function costMicros(SupplierCatalogItem $item, int $quantity): ?int
    {
        if ($quantity < 1) {
            return null;
        }
        $unit = $item->unit_price_micros;
        foreach ($item->price_tiers ?? [] as $tier) {
            if ($quantity >= (int) $tier['min'] && ($tier['max'] === null || $quantity <= (int) $tier['max'])) {
                $unit = $tier['unit_price_micros'];
            }
        }
        if (! is_int($unit) || $unit < 0) {
            return null;
        }
        $discount = 0;
        foreach ($item->bulk_discounts ?? [] as $row) {
            if ($quantity >= (int) $row['min']) {
                $discount = max($discount, (int) $row['percent_basis_points']);
            }
        }
        if ($discount < 0 || $discount > 10000) {
            return null;
        }
        $total = $this->multiply($unit, $quantity);
        // Charge ceil(total * (1 - discount)); split to avoid an unnecessary overflow.
        $remaining = 10000 - $discount;

        return $this->add($this->multiply(intdiv($total, 10000), $remaining), $this->ceilDiv($this->multiply($total % 10000, $remaining), 10000));
    }

    public function maximumCostIdr(SupplierPricePolicy $policy, int $retailSubtotal, int $quantity): int
    {
        $this->validatePolicy($policy);
        if ($quantity < 1 || $retailSubtotal < 0) {
            throw new InvalidArgumentException('Invalid supplier retail ceiling.');
        }

        return max(0, $retailSubtotal - $this->multiply($this->add((int) $policy->minimum_profit_idr, (int) $policy->buffer_idr), $quantity));
    }

    public function isEligible(Product $product, Supplier $supplier, SupplierCatalogItem $item, SupplierPricePolicy $policy, int $quantity, int $retailSubtotal): bool
    {
        if ($quantity < 1 || $quantity > 10000 || ! $supplier->is_active || ! $supplier->currency_confirmed
            || $supplier->exchange_rate_idr <= 0 || $supplier->synced_at === null
            || $supplier->sync_expires_at === null || ! $supplier->sync_expires_at->isFuture()
            || $item->supplier_id !== $supplier->id || $item->synced_at === null
            || ($item->currency !== null && $item->currency !== $supplier->currency)
            || ! $item->orderable || $item->requires_input || $item->stock === null
            || $quantity > max(0, $item->stock - $item->reserved_quantity)
            || $supplier->balance_micros === null || $policy->product_id !== $product->id) {
            return false;
        }
        try {
            $cost = $this->costMicros($item, $quantity);

            return $cost !== null
                && $retailSubtotal >= $this->multiply($this->add((int) $policy->minimum_profit_idr, (int) $policy->buffer_idr), $quantity)
                && $cost <= max(0, $supplier->balance_micros - $supplier->reserved_balance_micros)
                && SupplierMoney::costIdr($cost, $supplier->exchange_rate_idr) <= $this->maximumCostIdr($policy, $retailSubtotal, $quantity);
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /** A contiguous purchasable range; never advertise a quantity across an unprofitable tier gap. */
    public function capacity(Product $product, Supplier $supplier, SupplierCatalogItem $item, SupplierPricePolicy $policy): int
    {
        $limit = min(10000, max(0, (int) $item->stock - (int) $item->reserved_quantity));
        if ($limit === 0) {
            return 0;
        }
        $boundaries = [1, $limit + 1];
        $local = $product->localAvailableStock();
        foreach ($item->price_tiers ?? [] as $tier) {
            $boundaries[] = (int) $tier['min'];
            if ($tier['max'] !== null) {
                $boundaries[] = (int) $tier['max'] + 1;
            }
        }
        foreach ($item->bulk_discounts ?? [] as $discount) {
            $boundaries[] = (int) $discount['min'];
        }
        foreach ($product->quantityPriceTiers as $tier) {
            $boundaries[] = (int) $tier->minimum_quantity - $local;
        }
        $boundaries = array_values(array_unique(array_filter($boundaries, fn (int $q): bool => $q >= 1 && $q <= $limit + 1)));
        sort($boundaries, SORT_NUMERIC);
        $capacity = 0;
        try {
            for ($i = 0; $i < count($boundaries) - 1; $i++) {
                $start = $boundaries[$i];
                $end = $boundaries[$i + 1] - 1;
                $quote = $this->retail->quote($product, $this->add($start, $local));
                // A unit cost ceiling avoids ceil(total IDR) oscillations at micro-unit boundaries.
                $unitCost = $this->costMicros($item, $start);
                $unitCeiling = $this->maximumCostIdr($policy, $quote->effectiveIdrUnitAmount, 1);
                if ($unitCost === null || SupplierMoney::costIdr($this->ceilDiv($unitCost, $start), $supplier->exchange_rate_idr) > $unitCeiling
                    || ! $this->isEligible($product, $supplier, $item, $policy, $start, $this->multiply($quote->effectiveIdrUnitAmount, $start))) {
                    return $capacity;
                }
                $endQuote = $this->retail->quote($product, $this->add($end, $local));
                if ($this->isEligible($product, $supplier, $item, $policy, $end, $this->multiply($endQuote->effectiveIdrUnitAmount, $end))) {
                    $capacity = $end;

                    continue;
                }
                $low = $start;
                $high = $end;
                while ($low < $high) {
                    $mid = $low + intdiv($high - $low + 1, 2);
                    $midQuote = $this->retail->quote($product, $this->add($mid, $local));
                    if ($this->isEligible($product, $supplier, $item, $policy, $mid, $this->multiply($midQuote->effectiveIdrUnitAmount, $mid))) {
                        $low = $mid;
                    } else {
                        $high = $mid - 1;
                    }
                }

                return $low;
            }
        } catch (InvalidArgumentException) {
            return 0;
        }

        return $capacity;
    }

    private function validatePolicy(SupplierPricePolicy $policy): void
    {
        if (! in_array($policy->mode, ['manual', 'percent', 'fixed', 'hybrid'], true)
            || ! in_array((int) $policy->rounding_idr, [1, 100, 500, 1000], true)
            || $policy->markup_basis_points < 0 || $policy->profit_idr < 0
            || $policy->minimum_profit_idr < 0 || $policy->buffer_idr < 0) {
            throw new InvalidArgumentException('Invalid supplier price policy.');
        }
    }

    private function ceilDiv(int $value, int $divisor): int
    {
        return intdiv($value, $divisor) + ($value % $divisor === 0 ? 0 : 1);
    }

    private function multiply(int $a, int $b): int
    {
        if ($a < 0 || $b < 0 || ($b !== 0 && $a > intdiv(PHP_INT_MAX, $b))) {
            throw new InvalidArgumentException('Supplier amount exceeds supported range.');
        }

        return $a * $b;
    }

    private function add(int $a, int $b): int
    {
        if ($a < 0 || $b < 0 || $a > PHP_INT_MAX - $b) {
            throw new InvalidArgumentException('Supplier amount exceeds supported range.');
        }

        return $a + $b;
    }
}
