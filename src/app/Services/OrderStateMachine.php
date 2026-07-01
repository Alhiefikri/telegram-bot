<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidOrderTransition;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class OrderStateMachine
{
    public function __construct(
        private readonly InventoryLedger $inventory,
        private readonly ?ProductCredentialAllocator $credentials = null,
    ) {}

    public function markPaymentPaid(Payment $payment): Order
    {
        return DB::transaction(function () use ($payment) {
            $initial = Payment::findOrFail($payment->id);
            $order = Order::whereKey($initial->order_id)->lockForUpdate()->firstOrFail();
            $payments = Payment::where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
            $lockedPayment = $payments->firstWhere('id', $payment->id);

            if ($order->order_status === OrderStatus::Expired || $order->order_status === OrderStatus::Cancelled) {
                return $this->markLatePaymentPaid($lockedPayment, $order);
            }

            if ($lockedPayment->status === PaymentStatus::Paid && $order->order_status === OrderStatus::Processing && $this->inventory->hasSaleProofForOrder($order)) {
                return $order->fresh();
            }

            if ($lockedPayment->status !== PaymentStatus::Pending || $order->order_status !== OrderStatus::PendingPayment) {
                throw new InvalidOrderTransition('Payment cannot be marked paid from current state.');
            }

            $this->inventory->recordOrderSales($order);

            $lockedPayment->update(['status' => PaymentStatus::Paid, 'paid_at' => now()]);
            $order->update(['payment_status' => PaymentStatus::Paid, 'order_status' => OrderStatus::Processing]);

            return $order->fresh(['payments']);
        }, 5);
    }

    public function markPaymentRefunded(Payment $payment): Order
    {
        return DB::transaction(function () use ($payment) {
            $initial = Payment::findOrFail($payment->id);
            $order = Order::whereKey($initial->order_id)->lockForUpdate()->firstOrFail();
            $lockedPayment = Payment::where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get()->firstWhere('id', $payment->id);

            if (! $lockedPayment) {
                throw new InvalidOrderTransition('Payment is not attached to order.');
            }

            if ($lockedPayment->status !== PaymentStatus::Refunded) {
                $lockedPayment->update(['status' => PaymentStatus::Refunded]);
            }

            $updates = ['payment_status' => PaymentStatus::Refunded];
            if ($order->order_status !== OrderStatus::Completed) {
                $updates['order_status'] = OrderStatus::Cancelled;
            }
            $order->update($updates);

            return $order->fresh(['payments']);
        }, 5);
    }

    public function markPaymentExpired(Payment $payment): ?Order
    {
        $initial = Payment::findOrFail($payment->id);
        $order = Order::whereKey($initial->order_id)->firstOrFail();

        return $this->releasePending($order, OrderStatus::Expired, PaymentStatus::Expired, false);
    }

    private function markLatePaymentPaid(Payment $payment, Order $order): Order
    {
        if ($payment->status === PaymentStatus::Refunded) {
            if ($order->payment_status !== PaymentStatus::Refunded) {
                $order->update(['payment_status' => PaymentStatus::Refunded]);
            }

            return $order->fresh(['payments']);
        }

        if (! in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::Expired, PaymentStatus::Failed], true)) {
            throw new InvalidOrderTransition('Late payment cannot be marked paid from current payment state.');
        }

        if ($payment->status !== PaymentStatus::Paid) {
            $payment->update(['status' => PaymentStatus::Paid, 'paid_at' => $payment->paid_at ?? now()]);
        }

        if ($order->payment_status !== PaymentStatus::Paid) {
            $order->update(['payment_status' => PaymentStatus::Paid]);
        }

        return $order->fresh(['payments']);
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

            if ($lockedOrder->order_status === $orderStatus) {
                if (! $this->inventory->hasReleaseProofForOrder($lockedOrder)) {
                    throw new InvalidOrderTransition('Release movement proof is missing.');
                }

                $this->credentials()->release($lockedOrder);

                return $lockedOrder->fresh();
            }
            if ($lockedOrder->order_status !== OrderStatus::PendingPayment) {
                throw new InvalidOrderTransition('Only pending-payment orders can be released.');
            }
            if ($mustBeDue && $lockedOrder->expires_at && $lockedOrder->expires_at->isFuture()) {
                return null;
            }

            $this->inventory->releaseOrderReservations($lockedOrder);
            $this->credentials()->release($lockedOrder);

            foreach ($payments->where('status', PaymentStatus::Pending) as $payment) {
                $payment->update(['status' => $paymentStatus]);
            }
            $lockedOrder->update(['order_status' => $orderStatus, 'payment_status' => $paymentStatus]);

            return $lockedOrder->fresh(['payments']);
        }, 5);
    }

    private function credentials(): ProductCredentialAllocator
    {
        return $this->credentials ?? app(ProductCredentialAllocator::class);
    }
}
