<?php

namespace App\Services;

use App\Enums\InventoryMovementType;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidCartState;
use App\Exceptions\InvalidCheckout;
use App\Exceptions\InvalidOrderTransition;
use App\Jobs\SendProductStockNotifications;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Services\Telegram\StockNotificationDeliveryPipeline;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EloquentInventoryLedger implements InventoryLedger
{
    public function __construct(private readonly StockNotificationDeliveryPipeline $stockNotifications) {}

    /**
     * Callers must hold the product/order locks and wrap the operation in the
     * same transaction as the related order transition.
     */
    public function reserveOrderItem(Order $order, Product $product, int $quantity): void
    {
        $product->increment('reserved_stock', $quantity);
        $product->refresh();
        InventoryMovement::create(['product_id' => $product->id, 'order_id' => $order->id, 'type' => InventoryMovementType::Reservation, 'quantity' => $quantity, 'notes' => 'Order reservation', 'created_at' => now()]);
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
        $products = Product::withTrashed()
            ->with([
                'category' => fn ($query) => $query->where('is_active', true),
                'family:id,name',
            ])
            ->withAvailability()
            ->whereIn('id', $items->pluck('product_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

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
            $stockBefore = $product->stock;
            $product->forceFill([
                'stock' => $product->stock - $item->quantity,
                'reserved_stock' => $product->reserved_stock - $item->quantity,
            ])->save();
            $becameUnavailable = $product->stock <= 0
                || ($product->isCredentialManaged()
                    && $product->reserved_stock <= 0
                    && $product->availableStockItemCount() <= 0);
            $sale = InventoryMovement::firstOrCreate(
                ['order_id' => $order->id, 'product_id' => $product->id, 'type' => InventoryMovementType::Sale],
                [
                    'quantity' => -$item->quantity,
                    'stock_before' => $stockBefore,
                    'became_unavailable' => $becameUnavailable,
                    'notes' => 'Order sale',
                    'created_at' => now(),
                ],
            );

            if ($sale->wasRecentlyCreated && $becameUnavailable) {
                $this->stockNotifications->materialize($sale->id);
                DB::afterCommit(fn () => SendProductStockNotifications::dispatch($sale->id)->onQueue(StockNotificationDeliveryPipeline::QUEUE));
            }
        }
    }

    /**
     * A late payment was already released, so it has matching release proof
     * rather than a live reservation. The sale is still ledger-owned to keep
     * stock movements and stock-notification semantics consistent.
     */
    public function recordLatePaymentSales(Order $order, Collection $items, Collection $products, Collection $movements): void
    {
        foreach ($items as $item) {
            $product = $products[$item->product_id] ?? throw new InvalidOrderTransition('Order product is missing.');
            $this->assertMatchingReservation($item, $movements);

            if (! $this->hasMatchingProof(collect([$item]), $movements, InventoryMovementType::Release, -1)) {
                throw new InvalidOrderTransition('Released reservation proof is missing or mismatched.');
            }

            if (! $product->isPublicVisible() || $product->availableStock() < $item->quantity) {
                throw new InsufficientStock('Ordered product no longer has valid available inventory.');
            }

            $stockBefore = $product->stock;
            $product->decrement('stock', $item->quantity);
            $product->refresh();
            $becameUnavailable = $product->stock <= 0
                || ($product->isCredentialManaged() && $product->availableStockItemCount() <= 0);
            $sale = InventoryMovement::firstOrCreate(
                ['order_id' => $order->id, 'product_id' => $product->id, 'type' => InventoryMovementType::Sale],
                [
                    'quantity' => -$item->quantity,
                    'stock_before' => $stockBefore,
                    'became_unavailable' => $becameUnavailable,
                    'notes' => 'Late payment manual fulfillment',
                    'created_at' => now(),
                ],
            );

            if ($sale->wasRecentlyCreated && $becameUnavailable) {
                $this->stockNotifications->materialize($sale->id);
                DB::afterCommit(fn () => SendProductStockNotifications::dispatch($sale->id)->onQueue(StockNotificationDeliveryPipeline::QUEUE));
            }
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
            $release = $movements
                ->where('product_id', $item->product_id)
                ->where('type', InventoryMovementType::Release)
                ->first();

            if ($release) {
                if ($release->quantity !== -$item->quantity) {
                    throw new InvalidOrderTransition('Release movement proof is missing or mismatched.');
                }

                continue;
            }

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
