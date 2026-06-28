<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Collection;

interface InventoryLedger
{
    public function reserveOrderItem(Order $order, Product $product, int $quantity): void;

    public function hasSaleProof(Collection $items, Collection $movements): bool;

    public function hasReleaseProof(Collection $items, Collection $movements): bool;

    public function recordSales(Order $order, Collection $items, Collection $products, Collection $movements): void;

    public function releaseReservations(Order $order, Collection $items, Collection $products, Collection $movements): void;
}
