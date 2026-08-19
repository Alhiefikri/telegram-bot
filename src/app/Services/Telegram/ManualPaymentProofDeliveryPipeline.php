<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
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
                Payment::query()
                    ->whereKey($finalized->source_id)
                    ->whereNull('payment_proof_forwarded_at')
                    ->update([
                        'payment_proof_admin_chat_id' => $finalized->telegram_chat_id,
                        'payment_proof_forwarded_at' => $finalized->sent_at ?? now(),
                        'updated_at' => now(),
                    ]);
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
