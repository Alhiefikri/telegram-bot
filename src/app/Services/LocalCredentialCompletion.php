<?php

namespace App\Services;

use App\Models\Order;
use App\Models\SupplierFulfillment;

final class LocalCredentialCompletion
{
    public function isComplete(Order $order): bool
    {
        if (SupplierFulfillment::query()->where('order_id', $order->id)->exists()) {
            return false;
        }

        $items = $order->orderItems()->withCount(['stockItems' => fn ($query) => $query
            ->whereNull('supplier_purchase_id')->whereNull('retired_at')])->get();

        return $items->isNotEmpty() && $items->every(
            fn ($item): bool => $item->quantity > 0 && $item->stock_items_count === $item->quantity,
        );
    }
}
