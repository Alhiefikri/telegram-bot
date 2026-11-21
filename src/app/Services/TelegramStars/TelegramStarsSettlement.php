<?php

namespace App\Services\TelegramStars;

use App\Enums\ExternalMoneyReceiptClaimOutcome;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\ExternalMoneyReceipt;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTelegramStarsDetail;
use App\Models\TelegramUpdate;
use App\Services\MoneyReceipts\ExternalMoneyReceiptClaims;
use App\Services\MoneyReceipts\MoneyReceiptTarget;
use App\Services\MoneyReceipts\VerifiedMoneyReceipt;
use App\Services\OrderLifecycle;
use App\Services\Telegram\TelegramStarsUpdate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use RuntimeException;
use Throwable;

final readonly class TelegramStarsSettlement
{
    public function __construct(
        private ExternalMoneyReceiptClaims $receipts,
        private OrderLifecycle $lifecycle,
        private TelegramStarsReservation $reservations,
    ) {}

    public function process(int $inboxId): void
    {
        try {
            DB::transaction(function () use ($inboxId): void {
                if (DB::transactionLevel() === 1 && DB::getDriverName() === 'pgsql') {
                    DB::statement("SET LOCAL lock_timeout = '2s'");
                }

                $inbox = TelegramUpdate::whereKey($inboxId)->where('stars_event', TelegramStarsUpdate::SUCCESS)
                    ->whereNull('processed_at')->lockForUpdate()->first();
                if ($inbox === null) {
                    return;
                }

                $this->consume($inbox);
                $inbox->update(['processed_at' => now()]);
            }, 3);
        } catch (Throwable $error) {
            // Query exceptions may include bindings from financial evidence.
            Log::warning('telegram.stars_settlement_failed', ['error_class' => $error::class]);
            throw new RuntimeException('Telegram Stars settlement failed; inbox remains recoverable.');
        }
    }

    private function consume(TelegramUpdate $inbox): void
    {
        $message = TelegramStarsUpdate::minimize($inbox->payload, TelegramStarsUpdate::SUCCESS)['message'] ?? null;
        $event = $message[TelegramStarsUpdate::SUCCESS] ?? null;
        if ($event === null) {
            $this->review($inbox, 'malformed_evidence');

            return;
        }
        // Do not intercept unrelated legacy fiat charges in a shared inbox.
        // A non-XTR event that names a Stars invoice is still a mismatch.
        $target = PaymentTelegramStarsDetail::where('invoice_payload', $event['invoice_payload'])->first();
        if ($target === null) {
            if ($event['currency'] === 'XTR') {
                $this->review($inbox, 'unknown_invoice');
            }

            return;
        }

        // Use the lifecycle's Order → Payment lock order (also used by expiry).
        $orderId = Payment::whereKey($target->payment_id)->value('order_id');
        $order = $orderId === null ? null : Order::whereKey($orderId)->lockForUpdate()->first();
        $payment = $order === null ? null : Payment::whereKey($target->payment_id)->lockForUpdate()->first();
        $approval = DB::table('telegram_stars_approvals')->where('payment_id', $target->payment_id)->first();
        $namespace = $inbox->stars_namespace;
        $chargeHash = TelegramStarsChargeIdentity::hash($event['telegram_payment_charge_id']);

        $mismatch = match (true) {
            $payment === null || ! TelegramStarsInvoiceTarget::matches($target, $order, $payment) => 'target_mismatch',
            $namespace === null || $inbox->stars_charge_hash !== $chargeHash => 'unscoped_evidence',
            $event['currency'] !== 'XTR' => 'currency_mismatch',
            $event['total_amount'] !== $target->total_xtr => 'amount_mismatch',
            $message['chat']['type'] !== 'private', $message['chat']['id'] !== $target->chat_id,
            $message['from']['id'] !== $target->telegram_user_id => 'actor_mismatch',
            isset($event['is_recurring']) => 'unsupported_subscription',
            $approval === null || $approval->bot_namespace !== $namespace
                || $approval->approved_at_epoch >= $target->deadline_epoch => 'missing_approval',
            ! TelegramStarsInvoiceOutbound::hasAcknowledgedInvoice($target, $namespace) => 'invoice_not_acknowledged',
            default => null,
        };
        if ($mismatch !== null) {
            $this->review($inbox, $mismatch, $payment);

            return;
        }

        $identity = TelegramStarsChargeIdentity::incoming($namespace, $chargeHash);
        $settledKey = DB::table('telegram_stars_settlements as settlement')
            ->join('external_money_receipts as receipt', 'receipt.id', '=', 'settlement.external_money_receipt_id')
            ->where('settlement.payment_id', $payment->id)->value('receipt.canonical_key');
        if ($settledKey === $identity->canonicalKey()) {
            return;
        }

        $late = match ($order->order_status) {
            OrderStatus::Expired => $payment->status === PaymentStatus::Expired && $order->payment_status === PaymentStatus::Expired,
            OrderStatus::Cancelled => $payment->status === PaymentStatus::Failed && $order->payment_status === PaymentStatus::Failed,
            default => false,
        };
        $hold = match (true) {
            $settledKey !== null => 'additional_charge',
            TelegramUpdate::where('stars_event', TelegramStarsUpdate::REFUND)
                ->where('stars_namespace', $namespace)->where('stars_charge_hash', $chargeHash)->exists() => 'refund_evidence_present',
            Payment::where('order_id', $order->id)->whereKeyNot($payment->id)->exists()
                || (! $late && ! TelegramStarsInvoiceTarget::isPending($target, $order, $payment)) => 'ambiguous_order_state',
            ! $late && ! $this->reservations->matches($order) => 'reservation_mismatch',
            default => null,
        };
        $receipt = new VerifiedMoneyReceipt(
            identity: $identity,
            beneficiary: $namespace,
            currency: 'XTR',
            amountUnits: (string) $target->total_xtr,
            decimals: 0,
            target: MoneyReceiptTarget::orderPayment((string) $order->id),
            evidence: ['source' => 'telegram_stars_webhook', 'inbox_id' => $inbox->id],
        );
        $outcome = $this->receipts->claim($receipt);
        if ($outcome->isHeld()) {
            $this->review($inbox, 'receipt_conflict', $payment);

            return;
        }

        $receiptId = ExternalMoneyReceipt::where('canonical_key', $receipt->canonicalKey())->firstOrFail()->id;
        // Even a fully attributed charge held for review belongs to this
        // target, so it cannot be spent on a different approved invoice.
        if ($hold !== null || $outcome === ExternalMoneyReceiptClaimOutcome::Replayed) {
            if ($outcome === ExternalMoneyReceiptClaimOutcome::Claimed
                || ! DB::table('telegram_stars_payment_reviews')->where('external_money_receipt_id', $receiptId)->exists()) {
                $this->review($inbox, $hold ?? 'receipt_already_held', $payment, $receiptId);
            }

            return;
        }

        DB::table('telegram_stars_settlements')->insert([
            'payment_id' => $payment->id,
            'external_money_receipt_id' => $receiptId,
            'telegram_update_id' => $inbox->id,
            'late' => $late,
            'created_at' => now(),
        ]);

        if (! $this->lifecycle->applyClaimedStarsPayment($payment, $inbox->id)->newlyApplied) {
            throw new LogicException('A fresh Stars settlement did not apply its lifecycle transition.');
        }
    }

    private function review(TelegramUpdate $inbox, string $reason, ?Payment $payment = null, ?int $receiptId = null): void
    {
        $event = $inbox->payload['message'] ?? [];
        if (DB::table('telegram_stars_payment_reviews')->insertOrIgnore([
            'evidence_key' => hash('sha256', json_encode([
                $inbox->stars_namespace, $event['chat'] ?? null, $event['from'] ?? null,
                $event[TelegramStarsUpdate::SUCCESS] ?? null, $reason,
            ], JSON_THROW_ON_ERROR)),
            'telegram_update_id' => $inbox->id,
            'payment_id' => $payment?->id,
            'external_money_receipt_id' => $receiptId,
            'reason' => $reason,
            'created_at' => now(),
        ]) === 1) {
            DB::afterCommit(static fn () => Log::warning('telegram.stars_payment_review', ['reason' => $reason]));
        }
    }
}
