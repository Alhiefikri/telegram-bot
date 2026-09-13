<?php

namespace App\Services\Telegram;

use App\Models\Order;
use App\Models\Product;

/**
 * Owns the scarcity signal for the public Sales Proof caption.
 *
 * A posted caption is frozen while stock keeps moving, and the Store Channel
 * already announces restock and sold-out through StockNotificationDeliveryPipeline.
 * The caption therefore only speaks up in one narrow case: the Product behind a
 * single-item post still has a few units left. It stays quiet at zero so the
 * dedicated sold-out post owns that moment, and it stays quiet for multi-item
 * posts where "+N other items" would leave some Products without a number.
 */
final class SalesProofStockSignal
{
    public const LOW_STOCK_THRESHOLD = 5;

    public function forOrder(Order $order): ?int
    {
        $items = $order->orderItems;
        if ($items->count() !== 1) {
            return null;
        }

        $productId = $items->first()?->product_id;
        if ($productId === null) {
            return null;
        }

        $product = Product::query()
            ->withAvailability()
            ->publicVisible()
            ->whereKey((int) $productId)
            ->first();

        return $product instanceof Product ? $this->forProduct($product) : null;
    }

    public function forProduct(Product $product): ?int
    {
        $remaining = $product->availableStock();

        return $remaining > 0 && $remaining <= self::LOW_STOCK_THRESHOLD
            ? $remaining
            : null;
    }
}
