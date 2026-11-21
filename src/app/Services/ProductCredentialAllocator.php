<?php

namespace App\Services;

use App\Exceptions\InsufficientStock;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Services\Credentials\UniqueCredentialEmailPolicy;
use Illuminate\Support\Facades\DB;

class ProductCredentialAllocator
{
    public function allocate(OrderItem $orderItem, Product $product, int $quantity, bool $allowDeferred = false): void
    {
        if ($quantity === 0) {
            return;
        }
        if ($orderItem->unique_credential_email || $product->unique_credential_email) {
            $policy = app(UniqueCredentialEmailPolicy::class);
            // Every allocation path shares the Product lock, including manual delivery.
            Product::withTrashed()->whereKey($product->id)
                ->lock(DB::getDriverName() === 'pgsql' ? 'for no key update' : true)->firstOrFail();
            $missing = max(0, $quantity - $orderItem->stockItems()->whereNull('retired_at')->count());
            if ($missing === 0) {
                return;
            }
            $selected = $policy->selectAvailable($orderItem, $missing);
            if (count($selected) < $missing && ! $allowDeferred) {
                throw new InsufficientStock('Not enough account credentials are available.');
            }
            ProductStockItem::whereKey(array_map(fn (ProductStockItem $stock) => $stock->id, $selected))
                ->update(['order_item_id' => $orderItem->id, 'updated_at' => now()]);
            if ($allowDeferred) {
                $orderItem->update(['deferred_local_quantity' => $missing - count($selected)]);
            }

            return;
        }
        $credentialCount = ProductStockItem::where('product_id', $product->id)->localCatalogStock()->count();
        if ($credentialCount === 0) {
            return;
        }

        $credentials = ProductStockItem::where('product_id', $product->id)
            ->localCatalogStock()
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
            ->localCatalogStock()
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
