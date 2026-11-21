<?php

namespace App\Services\TelegramStars;

use App\Enums\InventoryMovementType;
use App\Models\Order;
use App\Models\ProductStockItem;
use App\Services\InventoryLedger;

final readonly class TelegramStarsReservation
{
    public function __construct(private InventoryLedger $inventory) {}

    /** Check the whole local-credential reservation under the Order → Payment locks. */
    public function matches(Order $order): bool
    {
        [$items, $movements, $products] = $this->inventory->orderInventoryState($order);
        $credentialCounts = ProductStockItem::query()->localCatalogStock()->whereNull('retired_at')
            ->whereIn('order_item_id', $items->modelKeys())
            ->selectRaw('order_item_id, product_id, count(*) as quantity')
            ->groupBy('order_item_id', 'product_id')->get()->groupBy('order_item_id');

        return $items->isNotEmpty() && $items->every(function ($item) use ($movements, $products, $credentialCounts): bool {
            $product = $products->get($item->product_id);
            $proof = $movements->where('product_id', $item->product_id);
            $credentials = $credentialCounts->get($item->id);

            return $product !== null && $item->quantity > 0 && $item->supplier_quantity === 0
                && $item->localStockQuantity() === $item->quantity
                && $product->stock >= $item->quantity && $product->reserved_stock >= $item->quantity
                && $proof->count() === 1 && $proof->first()->type === InventoryMovementType::Reservation
                && $proof->first()->quantity === $item->quantity
                && $credentials?->count() === 1 && (int) $credentials->first()->product_id === $item->product_id
                && (int) $credentials->first()->quantity === $item->quantity;
        });
    }
}
