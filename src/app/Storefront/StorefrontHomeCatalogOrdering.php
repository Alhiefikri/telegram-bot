<?php

namespace App\Storefront;

use App\Catalog\CatalogProductReadModel;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\OrderItem;
use App\Models\Payment;
use Illuminate\Support\Collection;

final class StorefrontHomeCatalogOrdering
{
    /**
     * @param  Collection<int, CatalogProductReadModel>  $plans
     * @return Collection<int, CatalogProductReadModel>
     */
    public function apply(Collection $plans, StorefrontHomeCatalogFilters $filters): Collection
    {
        if ($filters->inStockOnly) {
            $plans = $plans->filter(fn (CatalogProductReadModel $plan): bool => $plan->isAvailable);
        }

        $soldToday = $filters->sort === StorefrontHomeCatalogFilters::DEFAULT_SORT && $plans->count() > 1
            ? $this->soldToday()
            : [];

        return $plans->sort(function (CatalogProductReadModel $left, CatalogProductReadModel $right) use ($filters, $soldToday): int {
            if ($filters->sort === StorefrontHomeCatalogFilters::DEFAULT_SORT) {
                // Keep the existing Family and Plan order when neither ranks higher.
                return ($right->isAvailable <=> $left->isAvailable)
                    ?: (($soldToday[$right->id] ?? 0) <=> ($soldToday[$left->id] ?? 0));
            }

            return match ($filters->sort) {
                'price_asc' => $left->priceAmount <=> $right->priceAmount,
                'price_desc' => $right->priceAmount <=> $left->priceAmount,
                default => 0,
            } ?: strcmp(mb_strtolower($left->label), mb_strtolower($right->label))
                ?: ($left->id <=> $right->id);
        })->values();
    }

    /** @return array<int, int> */
    private function soldToday(): array
    {
        $start = now()->startOfDay();

        return OrderItem::query()
            ->whereIn('order_id', Payment::query()
                ->select('order_id')
                ->where('status', PaymentStatus::Paid)
                ->where('paid_at', '>=', $start)
                ->where('paid_at', '<', $start->copy()->addDay())
                ->whereHas('order', fn ($order) => $order
                    ->whereIn('order_status', [OrderStatus::Processing, OrderStatus::Completed])
                    ->where('payment_status', PaymentStatus::Paid)))
            ->selectRaw('product_id, SUM(quantity) AS units_sold')
            ->groupBy('product_id')
            ->pluck('units_sold', 'product_id')
            ->map(fn ($quantity): int => (int) $quantity)
            ->all();
    }
}
