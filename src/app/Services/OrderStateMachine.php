<?php

namespace App\Services;

use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidOrderTransition;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class OrderStateMachine
{
    public function markPaymentPaid(Payment $payment): Order
    {
        return DB::transaction(function () use ($payment) {
            $initial = Payment::findOrFail($payment->id);
            $order = Order::whereKey($initial->order_id)->lockForUpdate()->firstOrFail();
            $payments = Payment::where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
            $lockedPayment = $payments->firstWhere('id', $payment->id);

            if ($order->order_status === OrderStatus::Expired || $order->order_status === OrderStatus::Cancelled) {
                throw new InvalidOrderTransition('Cannot pay an expired or cancelled order.');
            }

            $items = $order->orderItems()->orderBy('product_id')->lockForUpdate()->get();
            $movements = InventoryMovement::where('order_id', $order->id)->lockForUpdate()->get();
            $products = Product::withTrashed()->whereIn('id', $items->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            if ($lockedPayment->status === PaymentStatus::Paid && $order->order_status === OrderStatus::Processing && $this->hasMatchingMovements($items, $movements, InventoryMovementType::Sale, -1)) {
                return $order->fresh();
            }

            if ($lockedPayment->status !== PaymentStatus::Pending || $order->order_status !== OrderStatus::PendingPayment) {
                throw new InvalidOrderTransition('Payment cannot be marked paid from current state.');
            }

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

            $lockedPayment->update(['status' => PaymentStatus::Paid, 'paid_at' => now()]);
            $order->update(['payment_status' => PaymentStatus::Paid, 'order_status' => OrderStatus::Processing]);

            return $order->fresh(['payments']);
        }, 5);
    }

    public function expirePendingOrder(Order $order): ?Order
    {
        return $this->releasePending($order, OrderStatus::Expired, PaymentStatus::Expired, true);
    }

    public function cancelPendingOrder(Order $order): Order
    {
        return $this->releasePending($order, OrderStatus::Cancelled, PaymentStatus::Failed, false);
    }

    private function releasePending(Order $order, OrderStatus $orderStatus, PaymentStatus $paymentStatus, bool $mustBeDue): ?Order
    {
        return DB::transaction(function () use ($order, $orderStatus, $paymentStatus, $mustBeDue) {
            $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $payments = Payment::where('order_id', $lockedOrder->id)->orderBy('id')->lockForUpdate()->get();

            $items = $lockedOrder->orderItems()->orderBy('product_id')->lockForUpdate()->get();
            $movements = InventoryMovement::where('order_id', $lockedOrder->id)->lockForUpdate()->get();

            if ($lockedOrder->order_status === $orderStatus) {
                if (! $this->hasMatchingMovements($items, $movements, InventoryMovementType::Release, -1)) {
                    throw new InvalidOrderTransition('Release movement proof is missing.');
                }

                return $lockedOrder->fresh();
            }
            if ($lockedOrder->order_status !== OrderStatus::PendingPayment) {
                throw new InvalidOrderTransition('Only pending-payment orders can be released.');
            }
            if ($mustBeDue && $lockedOrder->expires_at && $lockedOrder->expires_at->isFuture()) {
                return null;
            }

            $products = Product::withTrashed()->whereIn('id', $items->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($items as $item) {
                $product = $products[$item->product_id] ?? throw new InvalidOrderTransition('Order product is missing.');
                $this->assertMatchingReservation($item, $movements);
                if ($product->reserved_stock < $item->quantity) {
                    throw new InvalidOrderTransition('Reserved stock invariant violated.');
                }
                $release = InventoryMovement::firstOrCreate(['order_id' => $lockedOrder->id, 'product_id' => $product->id, 'type' => InventoryMovementType::Release], ['quantity' => -$item->quantity, 'notes' => 'Order reservation release', 'created_at' => now()]);
                if ($release->wasRecentlyCreated) {
                    $product->decrement('reserved_stock', $item->quantity);
                }
            }

            foreach ($payments->where('status', PaymentStatus::Pending) as $payment) {
                $payment->update(['status' => $paymentStatus]);
            }
            $lockedOrder->update(['order_status' => $orderStatus, 'payment_status' => $paymentStatus]);

            return $lockedOrder->fresh(['payments']);
        }, 5);
    }

    private function assertMatchingReservation($item, $movements): void
    {
        $reservation = $movements
            ->where('product_id', $item->product_id)
            ->where('type', InventoryMovementType::Reservation)
            ->first();

        if (! $reservation || $reservation->quantity !== $item->quantity || $reservation->quantity <= 0) {
            throw new InvalidOrderTransition('Reservation movement proof is missing or mismatched.');
        }
    }

    private function hasMatchingMovements($items, $movements, InventoryMovementType $type, int $sign): bool
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
}
