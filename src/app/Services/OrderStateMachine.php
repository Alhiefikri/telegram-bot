<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidOrderTransition;
use App\Jobs\DeleteAdminPendingOrderNotification;
use App\Jobs\DeleteBuyerBinanceCheckoutInstruction;
use App\Jobs\SendAdminOrderExpiredNotification;
use App\Jobs\SendAdminOrderPaidNotification;
use App\Models\ManualFulfillmentReview;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SalesProofPost;
use App\Models\StoreChannelSetting;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Support\Facades\DB;

class OrderStateMachine implements OrderLifecycle
{
    public function __construct(
        private readonly InventoryLedger $inventory,
        private readonly ?ProductCredentialAllocator $credentials = null,
        private readonly ?ProviderCancellationAttempts $providerCancellationAttempts = null,
        private readonly ?TelegramOutboundEffectService $outboundEffects = null,
        private readonly ?TelegramOutboundEffectDispatcher $outboundDispatcher = null,
    ) {}

    public function markPaymentPaid(Payment $payment): Order
    {
        return $this->applyPaymentPaid($payment)->order;
    }

    public function applyPaymentPaid(Payment $payment): OrderLifecycleResult
    {
        return DB::transaction(function () use ($payment) {
            $initial = Payment::findOrFail($payment->id);

            return $this->withLockedOrderPayments($initial->order_id, function (Order $order, $payments) use ($payment): OrderLifecycleResult {
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
        return $this->applyManualCompletion($order)->order;
    }

    public function applyManualCompletion(Order $order): OrderLifecycleResult
    {
        return DB::transaction(function () use ($order): OrderLifecycleResult {
            return $this->withLockedOrderPayments($order->id, function (Order $lockedOrder, $payments): OrderLifecycleResult {
                $manualPayment = $payments->where('provider', 'manual')->first();

                if ($lockedOrder->order_status === OrderStatus::Completed
                    && $lockedOrder->payment_status === PaymentStatus::Paid
                    && $manualPayment?->status === PaymentStatus::Paid) {
                    $completed = $lockedOrder->fresh(['payments']);

                    return new OrderLifecycleResult(
                        order: $completed,
                        payment: $completed->payments->firstWhere('id', $manualPayment->id),
                        newlyApplied: false,
                    );
                }

                $payment = $payments
                    ->where('provider', 'manual')
                    ->where('status', PaymentStatus::Pending)
                    ->first();

                if (! $this->canTransitionManualOrder($lockedOrder, $payment)) {
                    throw new InvalidOrderTransition('Only pending manual orders can be completed.');
                }

                $paidOrder = $this->applyPaidTransition($payment, $lockedOrder);
                if ($paidOrder->orderStatus() !== OrderStatus::Processing || $paidOrder->paymentStatus() !== PaymentStatus::Paid || ! $paidOrder->newlyApplied) {
                    throw new InvalidOrderTransition('Only newly paid manual orders can be completed.');
                }

                $lockedOrder->refresh();
                $lockedOrder->update(['order_status' => OrderStatus::Completed]);

                $completed = $lockedOrder->fresh(['payments']);

                return new OrderLifecycleResult(
                    order: $completed,
                    payment: $completed->payments->firstWhere('id', $payment->id),
                    newlyApplied: true,
                    receiptEligible: true,
                    salesProofEligible: $paidOrder->salesProofEligible,
                );
            });
        }, 5);
    }

    public function rejectManualOrder(Order $order): Order
    {
        return $this->applyManualRejection($order)->order;
    }

    public function applyManualRejection(Order $order): OrderLifecycleResult
    {
        return DB::transaction(function () use ($order): OrderLifecycleResult {
            return $this->withLockedOrderPayments($order->id, function (Order $lockedOrder, $payments): OrderLifecycleResult {
                $payment = $payments
                    ->where('provider', 'manual')
                    ->where('status', PaymentStatus::Pending)
                    ->first();

                if (! $this->canTransitionManualOrder($lockedOrder, $payment)) {
                    throw new InvalidOrderTransition('Only pending manual orders can be rejected.');
                }

                $rejected = $this->applyReleaseTransition($lockedOrder, $payments, OrderStatus::Cancelled, PaymentStatus::Failed, false);
                DeleteBuyerBinanceCheckoutInstruction::dispatch($rejected->order->getKey(), now()->toISOString())->onQueue('notifications')->afterCommit();

                return $rejected;
            });
        }, 5);
    }

    public function markPaymentRefunded(Payment $payment): Order
    {
        return $this->applyPaymentRefunded($payment)->order;
    }

    public function applyPaymentRefunded(Payment $payment): OrderLifecycleResult
    {
        return DB::transaction(function () use ($payment) {
            $initial = Payment::findOrFail($payment->id);

            return $this->withLockedOrderPayments($initial->order_id, function (Order $order, $payments) use ($payment): OrderLifecycleResult {
                $lockedPayment = $payments->firstWhere('id', $payment->id);
                if (! $lockedPayment) {
                    throw new InvalidOrderTransition('Payment is not attached to order.');
                }

                $wasAlreadyRefunded = $lockedPayment->status === PaymentStatus::Refunded
                    && $order->payment_status === PaymentStatus::Refunded;

                if (! $wasAlreadyRefunded) {
                    $this->releaseRefundedOrderResources($order);
                }

                if ($lockedPayment->status !== PaymentStatus::Refunded) {
                    $lockedPayment->update(['status' => PaymentStatus::Refunded]);
                }

                $updates = ['payment_status' => PaymentStatus::Refunded];
                if ($order->order_status !== OrderStatus::Completed) {
                    $updates['order_status'] = OrderStatus::Cancelled;
                }
                $order->update($updates);

                $refunded = $order->fresh(['payments']);

                return new OrderLifecycleResult(
                    order: $refunded,
                    payment: $refunded->payments->firstWhere('id', $payment->id),
                    newlyApplied: ! $wasAlreadyRefunded,
                );
            });
        }, 5);
    }

    public function markPaymentExpired(Payment $payment): ?Order
    {
        return $this->applyPaymentExpired($payment)->order;
    }

    public function applyPaymentExpired(Payment $payment): OrderLifecycleResult
    {
        $initial = Payment::findOrFail($payment->id);

        return $this->releasePendingByOrderId($initial->order_id, OrderStatus::Expired, PaymentStatus::Expired, false);
    }

    public function expirePendingOrder(Order $order): ?Order
    {
        return $this->applyPendingExpiry($order)->order;
    }

    public function applyPendingExpiry(Order $order): OrderLifecycleResult
    {
        $order->loadMissing('payments');
        if ($order->payments->contains(fn (Payment $payment): bool => $payment->provider === 'manual'
            && $payment->method === 'BINANCE_PAY_ID'
            && $payment->binance_auto_verification_enabled
            && $payment->status === PaymentStatus::Pending
            && $payment->binance_verification_grace_until?->isFuture())) {
            return new OrderLifecycleResult(
                order: null,
                payment: $order->payments->first(),
                newlyApplied: false,
            );
        }

        return $this->releasePendingByOrderId($order->id, OrderStatus::Expired, PaymentStatus::Expired, true);
    }

    public function cancelPendingOrder(Order $order, bool $requireNoPaymentProof = false): Order
    {
        return $this->applyPendingCancellation($order, $requireNoPaymentProof)->order;
    }

    public function applyPendingCancellation(Order $order, bool $requireNoPaymentProof = false): OrderLifecycleResult
    {
        return $this->releasePendingByOrderId($order->id, OrderStatus::Cancelled, PaymentStatus::Failed, false, $requireNoPaymentProof);
    }

    public function project(Order $order, ?Payment $payment = null): OrderLifecycleProjection
    {
        return OrderLifecycleProjection::from($order, $payment);
    }

    private function releasePendingByOrderId(int|string $orderId, OrderStatus $orderStatus, PaymentStatus $paymentStatus, bool $mustBeDue, bool $requireNoPaymentProof = false): OrderLifecycleResult
    {
        return DB::transaction(function () use ($orderId, $orderStatus, $paymentStatus, $mustBeDue, $requireNoPaymentProof) {
            return $this->withLockedOrderPayments($orderId, function (Order $order, $payments) use ($orderStatus, $paymentStatus, $mustBeDue, $requireNoPaymentProof): OrderLifecycleResult {
                if ($requireNoPaymentProof && $order->order_status === OrderStatus::PendingPayment) {
                    if ($order->payment_status !== PaymentStatus::Pending || ! $payments->contains(fn (Payment $payment): bool => $payment->status === PaymentStatus::Pending)) {
                        throw new InvalidOrderTransition('Only pending payments can be cancelled by the customer.');
                    }

                    if ($payments->contains(fn (Payment $payment): bool => $payment->payment_proof_submitted_at !== null)) {
                        throw new InvalidOrderTransition('Payment proof is being processed.');
                    }
                }

                return $this->applyReleaseTransition($order, $payments, $orderStatus, $paymentStatus, $mustBeDue);
            });
        }, 5);
    }

    private function withLockedOrderPayments(int|string $orderId, callable $callback): mixed
    {
        $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
        $payments = Payment::where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();

        return $callback($order, $payments);
    }

    private function applyPaidTransition(Payment $payment, Order $order): OrderLifecycleResult
    {
        if ($order->order_status === OrderStatus::Expired || $order->order_status === OrderStatus::Cancelled) {
            return $this->markLatePaymentPaid($payment, $order);
        }

        if ($payment->status === PaymentStatus::Paid && in_array($order->order_status, [OrderStatus::Processing, OrderStatus::Completed], true) && $this->inventory->hasSaleProofForOrder($order)) {
            $fresh = $order->fresh(['payments']);

            return new OrderLifecycleResult(
                order: $fresh,
                payment: $fresh->payments->firstWhere('id', $payment->id),
                newlyApplied: false,
                receiptEligible: true,
                salesProofEligible: true,
            );
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

        $salesProofEnabled = (bool) StoreChannelSetting::current()->sales_proof_enabled;

        if ($salesProofEnabled) {
            $salesProofPost = SalesProofPost::query()->firstOrCreate(
                ['order_id' => $order->id],
                ['status' => SalesProofPost::STATUS_PENDING],
            );

            $outboundEffects = $this->outboundEffects ?? app(TelegramOutboundEffectService::class);
            $effect = $outboundEffects->recordSalesProofPost($order, $salesProofPost);
            if ($effect !== null) {
                ($this->outboundDispatcher ?? app(TelegramOutboundEffectDispatcher::class))->dispatch($effect);
            }
        }

        $this->recordAndDispatchAdminOrderPaid($order);

        $paid = $order->fresh(['payments']);
        $this->recordAndDispatchReceipt($paid);

        return new OrderLifecycleResult(
            order: $paid,
            payment: $paid->payments->firstWhere('id', $payment->id),
            newlyApplied: true,
            receiptEligible: in_array($paid->order_status, [OrderStatus::Processing, OrderStatus::Completed], true),
            salesProofEligible: $salesProofEnabled,
        );
    }

    private function paidOrderStatus(Payment $payment): OrderStatus
    {
        return strtolower((string) $payment->provider) === 'pakasir'
            ? OrderStatus::Completed
            : OrderStatus::Processing;
    }

    private function markLatePaymentPaid(Payment $payment, Order $order): OrderLifecycleResult
    {
        if ($payment->status === PaymentStatus::Refunded) {
            if ($order->payment_status !== PaymentStatus::Refunded) {
                $order->update(['payment_status' => PaymentStatus::Refunded]);
            }

            $fresh = $order->fresh(['payments']);

            return new OrderLifecycleResult(
                order: $fresh,
                payment: $fresh->payments->firstWhere('id', $payment->id),
                newlyApplied: false,
            );
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

        ManualFulfillmentReview::query()->firstOrCreate(
            ['payment_id' => $payment->id],
            [
                'order_id' => $order->id,
                'amount' => $payment->provider_total_payment ?? $payment->amount,
                'currency' => $payment->currency,
                'reason' => ManualFulfillmentReview::REASON_LATE_PAYMENT,
                'state' => ManualFulfillmentReview::STATE_PENDING,
                'provider_reference' => $payment->provider_reference ?: $payment->merchant_ref,
            ],
        );

        if ($changed) {
            $this->dispatchAdminPendingNotificationDelete($order);
        }

        $latePaid = $order->fresh(['payments']);

        return new OrderLifecycleResult(
            order: $latePaid,
            payment: $latePaid->payments->firstWhere('id', $payment->id),
            newlyApplied: $changed,
        );
    }

    private function applyReleaseTransition(Order $order, $payments, OrderStatus $orderStatus, PaymentStatus $paymentStatus, bool $mustBeDue): OrderLifecycleResult
    {
        if ($order->order_status === $orderStatus) {
            $fresh = $order->fresh(['payments']);

            return new OrderLifecycleResult(
                order: $fresh,
                payment: $fresh->payments->first(),
                newlyApplied: false,
            );
        }

        if ($order->order_status !== OrderStatus::PendingPayment) {
            throw new InvalidOrderTransition('Only pending-payment orders can be released.');
        }

        if ($mustBeDue && $order->expires_at && $order->expires_at->isFuture()) {
            return new OrderLifecycleResult(
                order: null,
                payment: $payments->first(),
                newlyApplied: false,
            );
        }

        if ($mustBeDue && $payments->contains(fn (Payment $payment): bool => $payment->provider === 'manual'
            && $payment->method === 'BINANCE_PAY_ID'
            && $payment->binance_auto_verification_enabled
            && $payment->status === PaymentStatus::Pending
            && $payment->binance_verification_grace_until?->isFuture())) {
            return new OrderLifecycleResult(
                order: null,
                payment: $payments->first(),
                newlyApplied: false,
            );
        }

        $this->inventory->releaseOrderReservations($order);
        $this->credentials()->release($order);

        foreach ($payments->where('status', PaymentStatus::Pending) as $payment) {
            $payment->update(['status' => $paymentStatus]);
            $this->providerCancellationAttempts()->schedule($payment);
        }
        $order->update([
            'order_status' => $orderStatus,
            'payment_status' => $paymentStatus,
            ...($orderStatus === OrderStatus::Cancelled ? ['checkout_key' => null] : []),
        ]);
        $this->dispatchAdminPendingNotificationDelete($order);
        if ($orderStatus === OrderStatus::Expired && $paymentStatus === PaymentStatus::Expired) {
            $this->recordAndDispatchAdminOrderExpired($order);
        }

        $released = $order->fresh(['payments']);

        return new OrderLifecycleResult(
            order: $released,
            payment: $released->payments->first(),
            newlyApplied: true,
        );
    }

    private function canTransitionManualOrder(Order $order, ?Payment $payment): bool
    {
        return $order->order_status === OrderStatus::PendingPayment
            && $order->payment_status === PaymentStatus::Pending
            && $payment?->provider === 'manual'
            && $payment->status === PaymentStatus::Pending;
    }

    private function releaseRefundedOrderResources(Order $order): void
    {
        if (! $this->inventory->hasSaleProofForOrder($order)) {
            $this->inventory->releaseOrderReservations($order);
            $this->credentials()->release($order);

            return;
        }

        $this->credentials()->retire($order);
    }

    private function credentials(): ProductCredentialAllocator
    {
        return $this->credentials ?? app(ProductCredentialAllocator::class);
    }

    private function providerCancellationAttempts(): ProviderCancellationAttempts
    {
        return $this->providerCancellationAttempts ?? app(ProviderCancellationAttempts::class);
    }

    private function dispatchAdminPendingNotificationDelete(Order $order): void
    {
        DeleteAdminPendingOrderNotification::dispatch($order->id, now()->toISOString())->onQueue('notifications')->afterCommit();
    }

    private function dispatchAdminExpiredNotification(Order $order): void
    {
        SendAdminOrderExpiredNotification::dispatch($order->id, now()->toISOString())->onQueue('notifications')->afterCommit();
    }

    private function recordAndDispatchAdminOrderPaid(Order $order): void
    {
        $outboundEffects = $this->outboundEffects ?? app(TelegramOutboundEffectService::class);
        $effect = $outboundEffects->recordAdminOrderPaidNotification($order);

        if ($effect !== null) {
            ($this->outboundDispatcher ?? app(TelegramOutboundEffectDispatcher::class))->dispatch($effect);

            return;
        }

        if (! $outboundEffects->hasConfiguredAdminTarget()) {
            SendAdminOrderPaidNotification::dispatch($order->id, now()->toISOString())->onQueue('notifications')->afterCommit();
        }
    }

    private function recordAndDispatchAdminOrderExpired(Order $order): void
    {
        $outboundEffects = $this->outboundEffects ?? app(TelegramOutboundEffectService::class);
        $effect = $outboundEffects->recordAdminOrderExpiredNotification($order);

        if ($effect !== null) {
            ($this->outboundDispatcher ?? app(TelegramOutboundEffectDispatcher::class))->dispatch($effect);

            return;
        }

        if (! $outboundEffects->hasConfiguredAdminTarget()) {
            $this->dispatchAdminExpiredNotification($order);
        }
    }

    private function recordAndDispatchReceipt(Order $order): void
    {
        $outboundEffects = $this->outboundEffects ?? app(TelegramOutboundEffectService::class);
        $effect = $outboundEffects->recordReceipt($order);

        if ($effect !== null) {
            ($this->outboundDispatcher ?? app(TelegramOutboundEffectDispatcher::class))->dispatch($effect);
        }
    }
}
