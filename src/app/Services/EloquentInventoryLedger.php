<?php

namespace App\Services;

use App\Enums\InventoryMovementType;
use App\Exceptions\InvalidOrderTransition;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Collection;

class EloquentInventoryLedger implements InventoryLedger
{
    /**
     * Callers must hold the product/order locks and wrap the operation in the
     * same transaction as the related order transition.
     */
    public function reserveOrderItem(Order $order, Product $product, int $quantity): void
    {
        $product->increment('reserved_stock', $quantity);
        InventoryMovement::create(['product_id' => $product->id, 'order_id' => $order->id, 'type' => InventoryMovementType::Reservation, 'quantity' => $quantity, 'notes' => 'Order reservation', 'created_at' => now()]);
    }

    public function hasSaleProof(Collection $items, Collection $movements): bool
    {
        return $this->hasMatchingProof($items, $movements, InventoryMovementType::Sale, -1);
    }

    public function hasReleaseProof(Collection $items, Collection $movements): bool
    {
        return $this->hasMatchingProof($items, $movements, InventoryMovementType::Release, -1);
    }

    private function hasMatchingProof(Collection $items, Collection $movements, InventoryMovementType $type, int $sign): bool
    {
        foreach ($items as $item) {
            $movement = $movements
                ->where('product_id', $item->product_id)
                ->where('type', $type)
                ->first();

            if (! $movement || $movement->quantity !== ($sign * $item->quantity)) {
                return false;
            }
        }

        return $items->isNotEmpty();
    }

    /**
     * Callers must pass locked order items, product rows, and movement proof rows
     * from the active order-transition transaction.
     */
    public function recordSales(Order $order, Collection $items, Collection $products, Collection $movements): void
    {
        foreach ($items as $item) {
            $product = $products[$item->product_id] ?? throw new InvalidOrderTransition('Order product is missing.');
            $this->assertMatchingReservation($item, $movements);
            if ($product->reserved_stock < $item->quantity || $product->stock < $item->quantity) {
                throw new InvalidOrderTransition('Reserved stock invariant violated.');
            }
            $product->forceFill([
                'stock' => $product->stock - $item->quantity,
                'reserved_stock' => $product->reserved_stock - $item->quantity,
            ])->save();
            InventoryMovement::firstOrCreate(['order_id' => $order->id, 'product_id' => $product->id, 'type' => InventoryMovementType::Sale], ['quantity' => -$item->quantity, 'notes' => 'Order sale', 'created_at' => now()]);
        }
    }

    /**
     * Callers must pass locked order items, product rows, and movement proof rows
     * from the active order-transition transaction.
     */
    public function releaseReservations(Order $order, Collection $items, Collection $products, Collection $movements): void
    {
        foreach ($items as $item) {
            $product = $products[$item->product_id] ?? throw new InvalidOrderTransition('Order product is missing.');
            $this->assertMatchingReservation($item, $movements);
            if ($product->reserved_stock < $item->quantity) {
                throw new InvalidOrderTransition('Reserved stock invariant violated.');
            }
            $release = InventoryMovement::firstOrCreate(['order_id' => $order->id, 'product_id' => $product->id, 'type' => InventoryMovementType::Release], ['quantity' => -$item->quantity, 'notes' => 'Order reservation release', 'created_at' => now()]);
            if ($release->wasRecentlyCreated) {
                $product->decrement('reserved_stock', $item->quantity);
            }
        }
    }

    private function assertMatchingReservation($item, Collection $movements): void
    {
        $reservation = $movements
            ->where('product_id', $item->product_id)
            ->where('type', InventoryMovementType::Reservation)
            ->first();

        if (! $reservation || $reservation->quantity !== $item->quantity || $reservation->quantity <= 0) {
            throw new InvalidOrderTransition('Reservation movement proof is missing or mismatched.');
        }
    }
}
