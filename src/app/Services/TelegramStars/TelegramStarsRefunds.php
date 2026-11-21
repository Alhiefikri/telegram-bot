<?php

namespace App\Services\TelegramStars;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OrderLifecycle;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramStarsUpdate;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use RuntimeException;
use Throwable;

final readonly class TelegramStarsRefunds
{
    public function __construct(
        private TelegramBotClient $bot,
        private OrderLifecycle $lifecycle,
        private AuditLogger $audit,
    ) {}

    public function preview(User $actor, Payment $payment): ?array
    {
        $this->authorize($actor);
        $order = Order::findOrFail($payment->order_id);
        $charge = TelegramStarsOriginalCharge::forPayment($order, $payment);
        if ($charge === null || $charge->namespace !== TelegramStarsChargeIdentity::configuredNamespace()) {
            return null;
        }
        $claim = $this->claim($payment)->first();

        return [
            'invoice' => $order->invoice_number,
            'receipt' => $charge->receiptId,
            'charge_label' => 'SHA-256 '.substr(TelegramStarsChargeIdentity::hash($charge->chargeId), 0, 12),
            'buyer' => $charge->buyer,
            'total' => $charge->total,
            'claim' => $claim,
            'reversal_attribution_unknown' => $claim !== null && $claim->state !== 'confirmed'
                && DB::table('telegram_stars_refund_reviews')->where('payment_id', $payment->id)->where('reason', 'reversal_attribution_unknown')->exists(),
            'eligible' => $claim === null && $this->eligible($order, $payment, $charge),
        ];
    }

    public function request(User $actor, Payment $payment, int $previewedReceipt, string $reason, ?string $ip = null): string
    {
        $this->authorize($actor);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new DomainException('Alasan refund wajib diisi (maksimum 500 karakter).');
        }
        $this->requireOutsideTransaction();

        try {
            [$charge, $state] = $this->locked($payment, function (Order $order, Payment $payment) use ($actor, $previewedReceipt, $reason, $ip): array {
                $this->authorize(User::findOrFail($actor->id));
                $charge = TelegramStarsOriginalCharge::forPayment($order, $payment);
                if ($charge === null || $charge->namespace !== TelegramStarsChargeIdentity::configuredNamespace()
                    || $charge->receiptId !== $previewedReceipt) {
                    throw new DomainException('Charge asli tidak cocok dengan preview atau bot aktif.');
                }
                $existing = $this->claim($payment)->value('state');
                if ($existing !== null) {
                    return [null, $existing];
                }
                if (! $this->eligible($order, $payment, $charge)) {
                    throw new DomainException('Charge tidak dapat direfund; periksa status pembayaran dan bukti reversal.');
                }

                DB::table('telegram_stars_refunds')->insert([
                    'payment_id' => $payment->id,
                    'external_money_receipt_id' => $charge->receiptId,
                    'actor_user_id' => $actor->id,
                    'reason' => $reason,
                    'state' => 'pending',
                    'requested_at' => now(),
                ]);
                $this->audit->logWithResolvedContext('telegram_stars.refund.requested', $payment, null, [
                    'receipt_id' => $charge->receiptId, 'total_xtr' => $charge->total, 'reason' => $reason,
                ], $actor, $ip);

                return [$charge, 'pending'];
            });
        } catch (DomainException|AuthorizationException $error) {
            throw $error;
        } catch (Throwable $error) {
            $this->unavailable($error);
        }
        if ($charge === null) {
            return $state;
        }

        // This durable pending claim grants exactly one send attempt. A crash
        // before/after sending is indistinguishable, so never replay the write.
        try {
            $this->bot->refundStarPayment($charge->buyer, $charge->chargeId);
            $this->locked($payment, fn (Order $order, Payment $locked) => $this->confirmFromAck($locked, $charge));
        } catch (Throwable $error) {
            Log::warning('telegram.stars_refund_uncertain', ['payment_id' => $payment->id, 'error_class' => $error::class]);
            try {
                $this->claim($payment)->where('state', 'pending')->update(['state' => 'review']);
            } catch (Throwable $dbError) {
                $this->unavailable($dbError);
            }
        }

        return $this->claim($payment)->value('state');
    }

    public function process(int $inboxId): void
    {
        try {
            DB::transaction(function () use ($inboxId): void {
                $inbox = TelegramUpdate::whereKey($inboxId)->where('stars_event', TelegramStarsUpdate::REFUND)
                    ->whereNull('processed_at')->lockForUpdate()->first();
                if ($inbox === null) {
                    return;
                }
                $message = TelegramStarsUpdate::minimize($inbox->payload, TelegramStarsUpdate::REFUND)['message'] ?? [];
                $event = $message[TelegramStarsUpdate::REFUND] ?? [];
                $hash = TelegramStarsChargeIdentity::hash($event['telegram_payment_charge_id'] ?? '');
                if ($inbox->stars_namespace === null || $event === [] || $inbox->stars_charge_hash !== $hash) {
                    $this->review(null, 'unscoped_refund', ['webhook', $inbox->id], $inbox->id);
                } else {
                    $this->observe(
                        $inbox->stars_namespace, $hash, $event['invoice_payload'],
                        $message['chat']['type'] === 'private' ? $message['chat']['id'] : null,
                        $event['currency'] === 'XTR' ? $event['total_amount'] : null,
                        $message['date'] ?? null, $inbox->id,
                    );
                }
                $inbox->update(['processed_at' => now()]);
            }, 3);
        } catch (Throwable $error) {
            $this->unavailable($error);
        }
    }

    /** Called with an authoritative row from the configured Bot API. */
    public function reconcileOutgoing(string $namespace, array $row): ?string
    {
        try {
            $partner = $row['receiver'] ?? null;
            $charge = $row['id'] ?? null;
            if (array_key_exists('source', $row) || ! is_array($partner) || ($partner['type'] ?? null) !== 'user'
                || ! is_string($charge) || trim($charge) === '' || strlen($charge) > 256) {
                return 'invalid_outgoing_refund_evidence';
            }
            $wholeInvoice = ($partner['transaction_type'] ?? null) === 'invoice_payment'
                && ! array_key_exists('subscription_period', $partner)
                && (! array_key_exists('nanostar_amount', $row) || $row['nanostar_amount'] === 0);

            return $this->observe(
                $namespace, TelegramStarsChargeIdentity::hash($charge), $partner['invoice_payload'] ?? null, $partner['user']['id'] ?? null,
                $wholeInvoice ? ($row['amount'] ?? null) : null, $row['date'] ?? null,
            );
        } catch (Throwable $error) {
            $this->unavailable($error);
        }
    }

    private function observe(string $namespace, string $hash, mixed $payload, mixed $buyer, mixed $total, mixed $date, ?int $inboxId = null): ?string
    {
        $identity = TelegramStarsChargeIdentity::incoming($namespace, $hash);
        $paymentId = DB::table('external_money_receipts as receipt')
            ->join('telegram_stars_settlements as settlement', 'receipt.id', '=', 'settlement.external_money_receipt_id')
            ->where('receipt.canonical_key', $identity->canonicalKey())->value('settlement.payment_id');
        $source = $inboxId === null ? 'history' : 'webhook';
        $evidence = [$source, $identity->canonicalKey(), is_string($payload) ? hash('sha256', $payload) : null, $buyer, $total, $date];
        if ($paymentId === null) {
            return $this->review(null, 'unknown_refund_charge', $evidence, $inboxId);
        }

        return $this->locked(Payment::findOrFail($paymentId), function (Order $order, Payment $payment) use (
            $namespace, $hash, $payload, $buyer, $total, $date, $inboxId, $evidence
        ): ?string {
            $charge = TelegramStarsOriginalCharge::forPayment($order, $payment);
            if ($charge === null || $charge->namespace !== $namespace || TelegramStarsChargeIdentity::hash($charge->chargeId) !== $hash
                || $charge->buyer !== $buyer || $charge->total !== $total || $charge->payload !== $payload) {
                return $this->review($payment, 'refund_evidence_mismatch', $evidence, $inboxId);
            }
            $claim = $this->claim($payment)->first();
            if ($claim === null || (is_int($date) && $date < Carbon::parse($claim->requested_at)->timestamp)) {
                // May be a chargeback or a refund outside this workflow. The
                // API does not reliably distinguish the two; do not book it.
                return $this->review($payment, 'external_reversal_review', $evidence, $inboxId);
            }
            if (! is_int($date) || $date < 1 || $date > now()->addMinutes(2)->timestamp) {
                return $this->review($payment, 'refund_evidence_date_missing', $evidence, $inboxId);
            }

            // Matching evidence proves a reversal, not that refundStarPayment
            // caused it. Even a pending claim may not have been sent yet.
            if ($claim->state === 'confirmed') {
                return null;
            }
            $this->claim($payment)->where('state', 'pending')->update(['state' => 'review']);

            return $this->review($payment, 'reversal_attribution_unknown', $evidence, $inboxId);
        });
    }

    private function confirmFromAck(Payment $payment, TelegramStarsOriginalCharge $charge): void
    {
        $updated = $this->claim($payment)->where('external_money_receipt_id', $charge->receiptId)
            ->whereIn('state', ['pending', 'review'])->update([
                'state' => 'confirmed', 'confirmed_at' => now(), 'confirmation_source' => 'ack',
            ]);
        if ($updated === 1) {
            $this->lifecycle->applyConfirmedStarsRefund($payment);
            $this->audit->logWithResolvedContext('telegram_stars.refund.confirmed', $payment, null, [
                'receipt_id' => $charge->receiptId, 'total_xtr' => $charge->total, 'source' => 'ack',
            ]);
        }
    }

    private function eligible(Order $order, Payment $payment, TelegramStarsOriginalCharge $charge): bool
    {
        return $payment->status === PaymentStatus::Paid && $order->payment_status === PaymentStatus::Paid
            && ! Payment::where('order_id', $order->id)->whereKeyNot($payment->id)->exists()
            && ! $this->hasReversalEvidence($payment, $charge);
    }

    /** Must be checked under the same Order → Payment locks as refund claims. */
    public function blocksManualDelivery(Order $order, Payment $payment): bool
    {
        if (! $payment->isTelegramStars()) {
            return false;
        }

        $charge = TelegramStarsOriginalCharge::forPayment($order, $payment);

        return $charge === null || $this->claim($payment)->exists() || $this->hasReversalEvidence($payment, $charge);
    }

    private function hasReversalEvidence(Payment $payment, TelegramStarsOriginalCharge $charge): bool
    {
        return TelegramUpdate::where('stars_event', TelegramStarsUpdate::REFUND)
            ->where('stars_namespace', $charge->namespace)->where('stars_charge_hash', TelegramStarsChargeIdentity::hash($charge->chargeId))->exists()
            || DB::table('telegram_stars_refund_reviews')->where('payment_id', $payment->id)->where('reason', 'external_reversal_review')->exists();
    }

    private function review(?Payment $payment, string $reason, array $evidence, ?int $inboxId): string
    {
        if (DB::table('telegram_stars_refund_reviews')->insertOrIgnore([
            'evidence_key' => hash('sha256', json_encode([$reason, $evidence], JSON_THROW_ON_ERROR)),
            'payment_id' => $payment?->id,
            'telegram_update_id' => $inboxId,
            'reason' => $reason,
            'created_at' => now(),
        ]) === 1) {
            DB::afterCommit(static fn () => Log::warning('telegram.stars_refund_review', ['reason' => $reason, 'payment_id' => $payment?->id]));
        }

        return $reason;
    }

    private function claim(Payment $payment): Builder
    {
        return DB::table('telegram_stars_refunds')->where('payment_id', $payment->id);
    }

    private function locked(Payment $payment, callable $action): mixed
    {
        return DB::transaction(function () use ($payment, $action): mixed {
            if (DB::transactionLevel() === 1 && DB::getDriverName() === 'pgsql') {
                DB::statement("SET LOCAL lock_timeout = '2s'");
            }
            $order = Order::whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
            $locked = Payment::where('order_id', $order->id)->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            return $action($order, $locked);
        }, 3);
    }

    private function authorize(User $actor): void
    {
        if ($actor->role?->canRefundStars() !== true) {
            throw new AuthorizationException;
        }
    }

    private function requireOutsideTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Stars refund cannot be requested in a transaction.');
        }
    }

    private function unavailable(Throwable $error): never
    {
        Log::warning('telegram.stars_refund_unavailable', ['error_class' => $error::class]);
        throw new RuntimeException('Telegram Stars refund state is unavailable; reconcile before taking action.');
    }
}
