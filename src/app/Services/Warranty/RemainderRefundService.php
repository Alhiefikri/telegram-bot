<?php

namespace App\Services\Warranty;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidOrderTransition;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuditLogger;

/**
 * Records the unclaimed remainder of an externally funded Payment as one
 * confirmed OrderRefund when an operator completes a full Order refund.
 *
 * The caller must already hold the Order and Payment row locks inside its
 * transaction. Registration only records exact transfer evidence; it never
 * moves money, never credits Store Balance and never rewrites a confirmed
 * partial refund.
 */
final class RemainderRefundService
{
    public function registerConfirmedExternal(User $actor, Order $order, Payment $payment, string $reference): ?OrderRefund
    {
        $reference = trim($reference);
        if ($actor->role?->canReconcilePayments() !== true
            || $reference === '' || strlen($reference) > 200 || preg_match('/[\x00-\x1f\x7f]/', $reference)) {
            throw new InvalidOrderTransition('Invalid remainder refund evidence.');
        }
        if (in_array(strtolower(trim((string) $payment->provider)), ['store_balance', 'usdt_balance'], true)) {
            throw new InvalidOrderTransition('Internal balance refunds never register an external remainder.');
        }

        $refunds = OrderRefund::query()->where('payment_id', $payment->id)->orderBy('id')->lockForUpdate()->get();
        if ($refunds->isEmpty()) {
            return null;
        }
        if ($refunds->contains(fn (OrderRefund $refund): bool => in_array($refund->state, ['requested', 'uncertain'], true))) {
            throw new InvalidOrderTransition('Confirm or recover every registered external refund before refunding the full Order.');
        }
        if ((int) $order->getKey() !== (int) $payment->order_id
            || $payment->status !== PaymentStatus::Paid || $order->payment_status !== PaymentStatus::Paid
            || ! in_array($order->order_status, [OrderStatus::Processing, OrderStatus::Completed], true)) {
            throw new InvalidOrderTransition('Only a paid active Order can register a remainder refund.');
        }
        $remaining = RefundAllocation::remaining($payment->amount, (int) $refunds->where('state', 'confirmed')->sum('amount'));
        if ($remaining === 0) {
            return null;
        }

        $identity = hash('sha256', $payment->provider.'|'.$payment->currency.'|'.$reference);
        if (OrderRefund::query()->where('external_reference_hash', $identity)->exists()) {
            throw new InvalidOrderTransition('This transfer reference is already recorded for another refund.');
        }
        $refund = OrderRefund::create([
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'customer_id' => $order->customer_id,
            'amount' => $remaining,
            'currency' => $payment->currency,
            'state' => 'confirmed',
            'external_reference' => $reference,
            'external_reference_hash' => $identity,
            'actor_user_id' => $actor->id,
            'confirmed_at' => now(),
        ]);
        app(AuditLogger::class)->log('order.remainder_refund_confirmed', $refund, new: [
            'order_id' => $order->id,
            'amount' => $remaining,
            'currency' => $payment->currency,
        ], user: $actor);

        return $refund->fresh();
    }
}
