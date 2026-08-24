<?php

namespace App\Services\Bsc;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\BscPaymentTransferEvidence;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentBscDetail;
use Illuminate\Support\Carbon;

/**
 * Owns the narrow exception to pending-order expiry for BSC confirmations.
 *
 * A future grace timestamp is not sufficient on its own. It is valid only
 * when an authoritative, successful exact transfer was included on-chain
 * before the original Payment/Order deadline.
 */
final class BscConfirmationGrace
{
    public function isActive(Payment $payment, ?Order $order = null, ?Carbon $at = null): bool
    {
        $at ??= Carbon::now();
        $order ??= $payment->relationLoaded('order') ? $payment->order : $payment->order()->first();

        if ($payment->provider !== 'manual'
            || $payment->method !== 'USDT_BEP20'
            || $payment->status !== PaymentStatus::Pending
            || $order?->order_status !== OrderStatus::PendingPayment) {
            return false;
        }

        $detail = $payment->relationLoaded('bscDetail') ? $payment->bscDetail : $payment->bscDetail()->first();
        $deadline = $payment->expired_at ?? $order?->expires_at;
        $graceUntil = $detail?->verification_grace_until;
        $createdAt = $payment->created_at;

        if ($detail === null || $deadline === null || $createdAt === null || $graceUntil === null) {
            return false;
        }

        // The grace deadline is part of the Payment's verification snapshot.
        // Do not shorten an existing Payment's policy when an Owner changes
        // the setting for future checkouts; only accept the configured upper
        // safety bound for a value that was persisted by the verifier.
        if (! $graceUntil->greaterThan($deadline)
            || $graceUntil->greaterThan($deadline->copy()->addMinutes(60))
            || ! $graceUntil->isFuture($at)) {
            return false;
        }

        return BscPaymentTransferEvidence::query()
            ->where('payment_id', $payment->id)
            ->where('token_contract', strtolower((string) $detail->token_contract))
            ->where('receiver_address', strtolower((string) $detail->receiver_address))
            ->where('amount_units', (string) $detail->expected_token_units)
            ->where('receipt_status', 'success')
            ->where('verification_status', PaymentBscDetail::STATUS_CONFIRMING)
            ->whereNotNull('block_timestamp')
            ->where('block_timestamp', '>=', $createdAt)
            ->where('block_timestamp', '<=', $deadline)
            ->where('block_timestamp', '<=', $at)
            ->exists();
    }
}
