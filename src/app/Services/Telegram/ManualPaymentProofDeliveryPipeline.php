<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TelegramOutboundEffect;
use InvalidArgumentException;
use Throwable;

/** Adapter for forwarding a submitted manual payment proof to an admin. */
final class ManualPaymentProofDeliveryPipeline
{
    public function __construct(
        private readonly TelegramOutboundEffectLedgerProtocol $ledger,
        private readonly TelegramOutboundResponseValidator $responseValidator,
        private readonly TelegramManualPaymentIntents $paymentIntents,
    ) {}

    public function deliver(TelegramOutboundEffect $effect): void
    {
        if ($effect->effect_type !== TelegramOutboundEffectType::ManualPaymentProof->value) {
            throw new InvalidArgumentException('Unsupported Telegram manual payment proof effect type.');
        }

        $claimed = $this->ledger->claim((string) $effect->getKey());
        if ($claimed === null) {
            return;
        }

        try {
            $payment = Payment::query()
                ->with(['order.customer', 'order.orderItems'])
                ->findOrFail($claimed->source_id);
            $order = $payment->order;

            if (blank(config('services.telegram.token'))
                || preg_match('/^[1-9][0-9]*$/', trim((string) $claimed->telegram_chat_id)) !== 1) {
                $this->finalizeNotDeliverable($claimed, 'admin_target_missing', 'Manual payment proof has no usable delivery target.');

                return;
            }

            if ($order === null || ! $this->eligible($order, $payment)) {
                $this->finalizeNotDeliverable(
                    $claimed,
                    $payment->payment_proof_forwarded_at !== null ? 'proof_already_forwarded' : 'proof_not_eligible',
                    'Manual payment proof was no longer eligible for forwarding.',
                );

                return;
            }

            $photoFileId = trim((string) $payment->payment_proof_file_id);
            if ($photoFileId === '') {
                $this->finalizeNotDeliverable($claimed, 'proof_attachment_missing', 'Manual payment proof attachment is no longer available.');

                return;
            }

            $response = $this->paymentIntents->sendManualPaymentProofEffect(
                $order,
                $payment,
                (string) $claimed->telegram_chat_id,
            );
            $messageId = $this->responseValidator->messageIdFromResponse($response, 'Telegram manual payment proof');
            $finalized = $this->ledger->finalizeSent(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                $messageId,
            );

            if ($finalized !== null) {
                $this->synchronizeSentProjection($finalized);
            }
        } catch (Throwable $exception) {
            if ($this->ledger->isAmbiguous($exception)) {
                $this->ledger->finalizeUnknown(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    $exception,
                );

                return;
            }

            $this->ledger->finalizeFailed(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                $exception,
            );

            throw $exception;
        }
    }

    /**
     * Repair Payment projections for sent manual payment-proof effects.
     *
     * Recovery only scans effects already finalized as sent. It never claims
     * or dispatches an effect, and the conditional Payment update makes
     * concurrent recovery runs idempotent.
     */
    public function reconcileSentProjections(int $chunk = 100): int
    {
        $repaired = 0;

        TelegramOutboundEffect::query()
            ->where('source_type', Payment::class)
            ->where('effect_type', TelegramOutboundEffectType::ManualPaymentProof->value)
            ->where('state', TelegramOutboundEffectState::Sent->value)
            ->orderBy('id')
            ->chunkById(max(1, $chunk), function ($effects) use (&$repaired): void {
                foreach ($effects as $effect) {
                    if ($this->synchronizeSentProjection($effect)) {
                        $repaired++;
                    }
                }
            });

        return $repaired;
    }

    private function synchronizeSentProjection(TelegramOutboundEffect $effect): bool
    {
        return Payment::query()
            ->whereKey($effect->source_id)
            ->whereNull('payment_proof_forwarded_at')
            ->update([
                'payment_proof_admin_chat_id' => $effect->telegram_chat_id,
                'payment_proof_forwarded_at' => $effect->sent_at ?? now(),
                'updated_at' => now(),
            ]) === 1;
    }

    private function eligible(Order $order, Payment $payment): bool
    {
        $lateEvidence = $payment->method === 'BINANCE_PAY_ID'
            && in_array($payment->status, [PaymentStatus::Expired, PaymentStatus::Failed], true)
            && in_array($order->order_status, [OrderStatus::Expired, OrderStatus::Cancelled], true);

        return $payment->provider === 'manual'
            && ($payment->status === PaymentStatus::Pending || $lateEvidence)
            && $payment->payment_proof_file_id !== null
            && $payment->payment_proof_submitted_at !== null
            && $payment->payment_proof_forwarded_at === null;
    }

    private function finalizeNotDeliverable(TelegramOutboundEffect $effect, string $reason, string $message): void
    {
        $this->ledger->finalizeNotDeliverable(
            (string) $effect->getKey(),
            (string) $effect->claim_token,
            $reason,
            $message,
        );
    }
}
