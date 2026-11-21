<?php

namespace App\Services\TelegramStars;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTelegramStarsDetail;
use Illuminate\Support\Facades\DB;

final readonly class TelegramStarsPreCheckout
{
    public const GRACE_SECONDS = 120;

    public const RESPONSE_BUDGET_NS = 1_700_000_000;

    public function __construct(
        private StarsPricingPolicyManager $policy,
        private TelegramStarsReservation $reservations,
    ) {}

    /** The ingress owns the transaction; lock Order → Payment → inventory, as on release. */
    public function approve(array $query, int $deadline, ?string $namespace, bool $replayOnly): bool
    {
        if ($query['currency'] !== 'XTR' || $namespace === null) {
            return false;
        }

        $target = PaymentTelegramStarsDetail::where('invoice_payload', $query['invoice_payload'])->first();
        if ($target === null || $target->telegram_user_id !== $query['from']['id'] || $target->total_xtr !== $query['total_amount']) {
            return false;
        }

        $orderId = Payment::whereKey($target->payment_id)->value('order_id');
        $order = $orderId === null ? null : Order::whereKey($orderId)->lockForUpdate()->first();
        $payment = $order === null ? null : Payment::whereKey($target->payment_id)->lockForUpdate()->first();
        if ($payment === null || ! TelegramStarsInvoiceTarget::isPending($target, $order, $payment)
            || ! TelegramStarsInvoiceOutbound::hasAcknowledgedInvoice($target, $namespace)) {
            return false;
        }

        $hash = hash('sha256', $query['id']);
        $claim = DB::table('telegram_stars_approvals')->where('payment_id', $payment->id)->first();
        if ($claim !== null) {
            return $claim->bot_namespace === $namespace && hash_equals($claim->query_hash, $hash)
                && now()->getTimestamp() < (int) $claim->approved_at_epoch + 10;
        }

        if ($replayOnly || ! TelegramStarsAdmission::permitsNewPayments() || $target->deadline_epoch <= now()->getTimestamp()
            || ! $this->policy->lockForCheckout()->issuanceEnabled
            || ! $this->reservations->matches($order)
            || hrtime(true) >= $deadline) {
            return false;
        }

        $now = now()->getTimestamp();

        return $now < $target->deadline_epoch && DB::table('telegram_stars_approvals')->insertOrIgnore([
            'payment_id' => $payment->id,
            'query_hash' => $hash,
            'approved_at_epoch' => $now,
            'bot_namespace' => $namespace,
        ]) === 1;
    }

    /** Call while holding the same Order and Payment locks as approval. */
    public static function graceIsActive(Payment $payment): bool
    {
        return $payment->isTelegramStars() && $payment->status === PaymentStatus::Pending
            && DB::table('telegram_stars_approvals')->where('payment_id', $payment->id)
                ->where('approved_at_epoch', '>', now()->getTimestamp() - self::GRACE_SECONDS)->exists();
    }
}
