<?php

namespace App\Services;

use App\Enums\InventoryMovementType;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidCartState;
use App\Exceptions\InvalidCheckout;
use App\Exceptions\InvalidOrderTransition;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Services\Telegram\TelegramProductNotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EloquentInventoryLedger implements InventoryLedger
{
    public function __construct(private readonly TelegramProductNotificationService $notifications) {}

    /**
     * Callers must hold the product/order locks and wrap the operation in the
     * same transaction as the related order transition.
     */
    public function reserveOrderItem(Order $order, Product $product, int $quantity): void
    {
        $availableBefore = $product->availableStock();
        $product->increment('reserved_stock', $quantity);
        $product->refresh();
        InventoryMovement::create(['product_id' => $product->id, 'order_id' => $order->id, 'type' => InventoryMovementType::Reservation, 'quantity' => $quantity, 'notes' => 'Order reservation', 'created_at' => now()]);

        if ($availableBefore > 0 && $product->availableStock() <= 0) {
            DB::afterCommit(fn () => $this->notifications->notifyOutOfStock($product->fresh()));
        }
    }

    public function assertCartProductAvailable(Product $product, int $quantity, int $cartId): void
    {
        if (! $product->isPublicVisible()) {
            throw new InvalidCartState('Product is not active.');
        }

        if ($product->availableStock() < $quantity) {
            throw new InsufficientStock('Insufficient available stock.');
        }
    }

    public function lockedCheckoutProducts(Collection $items): Collection
    {
        $products = Product::withTrashed()->whereIn('id', $items->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        foreach ($items as $item) {
            $product = $products[$item->product_id] ?? null;
            if (! $product || ! $product->isPublicVisible()) {
                throw new InvalidCheckout('Cart contains unavailable product.');
            }
            if ($product->availableStock() < $item->quantity) {
                throw new InsufficientStock('Insufficient available stock.');
            }
        }

        return $products;
    }

    public function orderInventoryState(Order $order): array
    {
        $items = $order->orderItems()->orderBy('product_id')->lockForUpdate()->get();
        $movements = InventoryMovement::where('order_id', $order->id)->lockForUpdate()->get();
        $products = Product::withTrashed()->whereIn('id', $items->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        return [$items, $movements, $products];
    }

    public function hasSaleProofForOrder(Order $order): bool
    {
        [$items, $movements] = $this->orderInventoryState($order);

        return $this->hasSaleProof($items, $movements);
    }

    public function hasReleaseProofForOrder(Order $order): bool
    {
        [$items, $movements] = $this->orderInventoryState($order);

        return $this->hasReleaseProof($items, $movements);
    }

    public function recordOrderSales(Order $order): void
    {
        [$items, $movements, $products] = $this->orderInventoryState($order);
        $this->recordSales($order, $items, $products, $movements);
    }

    public function releaseOrderReservations(Order $order): void
    {
        [$items, $movements, $products] = $this->orderInventoryState($order);
        $this->releaseReservations($order, $items, $products, $movements);
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
