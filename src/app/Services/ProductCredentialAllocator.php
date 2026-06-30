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
        $credentialCount = ProductStockItem::where('product_id', $product->id)->count();
        if ($credentialCount === 0) {
            return;
        }

        $credentials = ProductStockItem::where('product_id', $product->id)
            ->whereNull('order_item_id')
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
            ->update(['order_item_id' => null, 'updated_at' => now()]);
    }
}
