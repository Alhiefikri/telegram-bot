<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidOrderTransition;
use App\Jobs\DeleteAdminPendingOrderNotification;
use App\Jobs\PublishSalesProofPost;
use App\Jobs\SendAdminOrderExpiredNotification;
use App\Jobs\SendAdminOrderPaidNotification;
use App\Jobs\SendTelegramReceipt;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SalesProofPost;
use App\Models\StoreChannelSetting;
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

            return $this->withLockedOrderPayments($initial->order_id, function (Order $order, $payments) use ($payment): Order {
                $lockedPayment = $payments->firstWhere('id', $payment->id);
                if (! $lockedPayment) {
                    throw new InvalidOrderTransition('Payment is not attached to order.');
                }

                return $this->applyPaidTransition($lockedPayment, $order);
            });
        }, 5);
    }

    public function completeManualOrder(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            return $this->withLockedOrderPayments($order->id, function (Order $lockedOrder, $payments): Order {
                $payment = $payments
                    ->where('provider', 'manual')
                    ->where('status', PaymentStatus::Pending)
                    ->first();

                if (! $this->canTransitionManualOrder($lockedOrder, $payment)) {
                    throw new InvalidOrderTransition('Only pending manual orders can be completed.');
                }

                $paidOrder = $this->applyPaidTransition($payment, $lockedOrder);
                if ($paidOrder->order_status !== OrderStatus::Processing || $paidOrder->payment_status !== PaymentStatus::Paid) {
                    throw new InvalidOrderTransition('Only newly paid manual orders can be completed.');
                }

                $lockedOrder->refresh();
                $lockedOrder->update(['order_status' => OrderStatus::Completed]);

                SendAdminOrderPaidNotification::dispatch($lockedOrder->getKey(), now()->toISOString())->onQueue('notifications')->afterCommit();
                SendTelegramReceipt::dispatch($lockedOrder->getKey(), false, now()->toISOString())->onQueue('notifications')->afterCommit();

                return $lockedOrder->fresh(['payments']);
            });
        }, 5);
    }

    public function rejectManualOrder(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            return $this->withLockedOrderPayments($order->id, function (Order $lockedOrder, $payments): Order {
                $payment = $payments
                    ->where('provider', 'manual')
                    ->where('status', PaymentStatus::Pending)
                    ->first();

                if (! $this->canTransitionManualOrder($lockedOrder, $payment)) {
                    throw new InvalidOrderTransition('Only pending manual orders can be rejected.');
                }

                return $this->applyReleaseTransition($lockedOrder, $payments, OrderStatus::Cancelled, PaymentStatus::Failed, false);
            });
        }, 5);
    }

    public function markPaymentRefunded(Payment $payment): Order
    {
        return DB::transaction(function () use ($payment) {
            $initial = Payment::findOrFail($payment->id);

            return $this->withLockedOrderPayments($initial->order_id, function (Order $order, $payments) use ($payment): Order {
                $lockedPayment = $payments->firstWhere('id', $payment->id);
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
            });
        }, 5);
    }

    public function markPaymentExpired(Payment $payment): ?Order
    {
        $initial = Payment::findOrFail($payment->id);

        return $this->releasePendingByOrderId($initial->order_id, OrderStatus::Expired, PaymentStatus::Expired, false);
    }

    public function expirePendingOrder(Order $order): ?Order
    {
        return $this->releasePendingByOrderId($order->id, OrderStatus::Expired, PaymentStatus::Expired, true);
    }

    public function cancelPendingOrder(Order $order): Order
    {
        return $this->releasePendingByOrderId($order->id, OrderStatus::Cancelled, PaymentStatus::Failed, false);
    }

    private function releasePendingByOrderId(int|string $orderId, OrderStatus $orderStatus, PaymentStatus $paymentStatus, bool $mustBeDue): ?Order
    {
        return DB::transaction(function () use ($orderId, $orderStatus, $paymentStatus, $mustBeDue) {
            return $this->withLockedOrderPayments($orderId, fn (Order $order, $payments): ?Order => $this->applyReleaseTransition($order, $payments, $orderStatus, $paymentStatus, $mustBeDue));
        }, 5);
    }

    private function withLockedOrderPayments(int|string $orderId, callable $callback): mixed
    {
        $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
        $payments = Payment::where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();

        return $callback($order, $payments);
    }

    private function applyPaidTransition(Payment $payment, Order $order): Order
    {
        if ($order->order_status === OrderStatus::Expired || $order->order_status === OrderStatus::Cancelled) {
            return $this->markLatePaymentPaid($payment, $order);
        }

        if ($payment->status === PaymentStatus::Paid && in_array($order->order_status, [OrderStatus::Processing, OrderStatus::Completed], true) && $this->inventory->hasSaleProofForOrder($order)) {
            return $order->fresh(['payments']);
        }

        if ($payment->status !== PaymentStatus::Pending || $order->order_status !== OrderStatus::PendingPayment) {
            throw new InvalidOrderTransition('Payment cannot be marked paid from current state.');
        }

        $this->inventory->recordOrderSales($order);

        $payment->update(['status' => PaymentStatus::Paid, 'paid_at' => now()]);
        $order->update([
            'payment_status' => PaymentStatus::Paid,
            'order_status' => $this->paidOrderStatus($payment),
        ]);
        $this->dispatchAdminPendingNotificationDelete($order);

        if (StoreChannelSetting::current()->sales_proof_enabled) {
            SalesProofPost::query()->firstOrCreate(['order_id' => $order->id], ['status' => SalesProofPost::STATUS_PENDING]);
            PublishSalesProofPost::dispatch($order->id, false, now()->toISOString())->onQueue('notifications')->afterCommit();
        }

        SendAdminOrderPaidNotification::dispatch($order->id, now()->toISOString())->onQueue('notifications')->afterCommit();

        return $order->fresh(['payments']);
    }

    private function paidOrderStatus(Payment $payment): OrderStatus
    {
        return strtolower((string) $payment->provider) === 'pakasir'
            ? OrderStatus::Completed
            : OrderStatus::Processing;
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

        $changed = false;
        if ($payment->status !== PaymentStatus::Paid) {
            $payment->update(['status' => PaymentStatus::Paid, 'paid_at' => $payment->paid_at ?? now()]);
            $changed = true;
        }

        if ($order->payment_status !== PaymentStatus::Paid) {
            $order->update(['payment_status' => PaymentStatus::Paid]);
            $changed = true;
        }

        if ($changed) {
            $this->dispatchAdminPendingNotificationDelete($order);
        }

        return $order->fresh(['payments']);
    }

    private function applyReleaseTransition(Order $order, $payments, OrderStatus $orderStatus, PaymentStatus $paymentStatus, bool $mustBeDue): ?Order
    {
        if ($order->order_status === $orderStatus) {
            if (! $this->inventory->hasReleaseProofForOrder($order)) {
                throw new InvalidOrderTransition('Release movement proof is missing.');
            }

            $this->credentials()->release($order);

            return $order->fresh(['payments']);
        }

        if ($order->order_status !== OrderStatus::PendingPayment) {
            throw new InvalidOrderTransition('Only pending-payment orders can be released.');
        }

        if ($mustBeDue && $order->expires_at && $order->expires_at->isFuture()) {
            return null;
        }

        $this->inventory->releaseOrderReservations($order);
        $this->credentials()->release($order);

        foreach ($payments->where('status', PaymentStatus::Pending) as $payment) {
            $payment->update(['status' => $paymentStatus]);
        }
        $order->update([
            'order_status' => $orderStatus,
            'payment_status' => $paymentStatus,
        ]);
        $this->dispatchAdminPendingNotificationDelete($order);
        if ($orderStatus === OrderStatus::Expired && $paymentStatus === PaymentStatus::Expired) {
            $this->dispatchAdminExpiredNotification($order);
        }

        return $order->fresh(['payments']);
    }

    private function canTransitionManualOrder(Order $order, ?Payment $payment): bool
    {
        return $order->order_status === OrderStatus::PendingPayment
            && $order->payment_status === PaymentStatus::Pending
            && $payment?->provider === 'manual'
            && $payment->status === PaymentStatus::Pending;
    }

    private function credentials(): ProductCredentialAllocator
    {
        return $this->credentials ?? app(ProductCredentialAllocator::class);
    }

    private function dispatchAdminPendingNotificationDelete(Order $order): void
    {
        DeleteAdminPendingOrderNotification::dispatch($order->id, now()->toISOString())->onQueue('notifications')->afterCommit();
    }

    private function dispatchAdminExpiredNotification(Order $order): void
    {
        SendAdminOrderExpiredNotification::dispatch($order->id, now()->toISOString())->onQueue('notifications')->afterCommit();
    }
}
