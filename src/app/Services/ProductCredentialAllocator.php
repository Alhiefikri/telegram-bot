<?php

namespace App\Services;

use App\Exceptions\InsufficientStock;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStockItem;

class ProductCredentialAllocator
{
    public function allocate(OrderItem $orderItem, Product $product, int $quantity): void
    {
        if ($quantity === 0) {
            return;
        }
        $credentialCount = ProductStockItem::where('product_id', $product->id)->whereNull('supplier_purchase_id')->count();
        if ($credentialCount === 0) {
            return;
        }

        $credentials = ProductStockItem::where('product_id', $product->id)
            ->whereNull('supplier_purchase_id')
            ->whereNull('order_item_id')
            ->whereNull('retired_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->limit($quantity)
            ->get();

        if ($credentials->count() < $quantity) {
            throw new InsufficientStock('Not enough account credentials are available.');
        }

        ProductStockItem::whereKey($credentials->pluck('id'))
            ->update(['order_item_id' => $orderItem->id, 'updated_at' => now()]);
    }

    public function release(Order $order): void
    {
        $orderItemIds = $order->orderItems()->pluck('id');
        if ($orderItemIds->isEmpty()) {
            return;
        }

        ProductStockItem::whereIn('order_item_id', $orderItemIds)
            ->whereNull('supplier_purchase_id')
            ->whereNull('retired_at')
            ->update(['order_item_id' => null, 'updated_at' => now()]);
    }

    /** Retired credentials retain their allocation and can never re-enter stock. */
    public function retire(Order $order, string $reason = 'refunded_after_delivery'): void
    {
        $orderItemIds = $order->orderItems()->pluck('id');
        if ($orderItemIds->isEmpty()) {
            return;
        }

        ProductStockItem::whereIn('order_item_id', $orderItemIds)
            ->whereNull('retired_at')
            ->update(['retired_at' => now(), 'retired_reason' => $reason, 'updated_at' => now()]);
    }
}
