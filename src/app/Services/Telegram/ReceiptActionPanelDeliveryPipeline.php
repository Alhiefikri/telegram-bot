<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TelegramOutboundEffect;
use App\Support\DogfoodTiming;
use InvalidArgumentException;
use Throwable;

/**
 * Delivers the non-secret action panel through the shared effect ledger.
 *
 * This family is intentionally independent from receipt completion: a panel
 * failure can be reviewed without changing the paid Order or its receipt
 * projection.
 */
final class ReceiptActionPanelDeliveryPipeline
{
    public function __construct(
        private readonly TelegramOutboundEffectLedgerProtocol $ledger,
        private readonly TelegramBotClient $bot,
        private readonly TelegramOutboundResponseValidator $responseValidator,
        private readonly TelegramReceiptActionPanel $panel,
        private readonly TelegramReceiptObservability $observability,
    ) {}

    public function deliver(TelegramOutboundEffect $effect, ?string $jobEnqueuedAt = null): void
    {
        if ($effect->effect_type !== TelegramOutboundEffectType::ReceiptOrderActions->value
            || $effect->source_type !== Order::class
            || $effect->telegram_method !== 'sendMessage'
            || $effect->template_version !== TelegramOutboundEffectService::TEMPLATE_RECEIPT_ORDER_ACTIONS) {
            throw new InvalidArgumentException('Unsupported Telegram receipt action-panel effect type.');
        }

        $claimed = $this->ledger->claim((string) $effect->getKey());
        if ($claimed === null) {
            return;
        }

        $deliveryStartedAt = DogfoodTiming::now();

        try {
            $order = Order::query()
                ->with(['customer', 'payments'])
                ->find($claimed->source_id);
            $customer = $order?->customer;

            if (! $order instanceof Order
                || ! $customer instanceof Customer
                || ! $this->eligible($order, $customer, $claimed)
                || ! $this->receiptGroupCompleted($claimed)) {
                $finalized = $this->finalizeNotDeliverable(
                    $claimed,
                    'receipt_actions_not_eligible',
                    'Receipt actions were no longer eligible for delivery.',
                );
                $this->recordOutcome($finalized, 'failed', $deliveryStartedAt, $jobEnqueuedAt);

                return;
            }

            $metadata = is_array($claimed->outcome_metadata) ? $claimed->outcome_metadata : [];
            if (! is_bool($metadata['txt_available'] ?? null)) {
                $finalized = $this->finalizeNotDeliverable(
                    $claimed,
                    'receipt_actions_render_failed',
                    'Receipt actions could not be rendered.',
                );
                $this->recordOutcome($finalized, 'failed', $deliveryStartedAt, $jobEnqueuedAt);

                return;
            }

            try {
                $unit = $this->panel->render(
                    $order,
                    (string) ($claimed->locale ?: $customer->locale),
                    $metadata['txt_available'],
                );
            } catch (Throwable) {
                $finalized = $this->finalizeNotDeliverable(
                    $claimed,
                    'receipt_actions_render_failed',
                    'Receipt actions could not be rendered.',
                );
                $this->recordOutcome($finalized, 'failed', $deliveryStartedAt, $jobEnqueuedAt);

                return;
            }

            $response = $this->bot->sendMessage(
                (string) $claimed->telegram_chat_id,
                $unit->text(),
                $unit->replyMarkup(),
            );
            $messageId = $this->responseValidator->messageIdFromResponse($response, 'Telegram receipt action panel');

            $finalized = $this->ledger->finalizeSent(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                $messageId,
                ['delivery_method' => 'sendMessage'],
            );
            $this->recordOutcome($finalized, 'sent', $deliveryStartedAt, $jobEnqueuedAt);
        } catch (Throwable $exception) {
            if ($this->ledger->isAmbiguous($exception)) {
                $finalized = $this->ledger->finalizeUnknown(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    $exception,
                );
                $this->recordOutcome($finalized, 'unknown', $deliveryStartedAt, $jobEnqueuedAt);

                return;
            }

            $finalized = $this->ledger->finalizeFailed(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                $exception,
                $this->ledger->retryAfterSeconds($exception),
            );
            $this->recordOutcome($finalized, 'failed', $deliveryStartedAt, $jobEnqueuedAt);
        }
    }

    private function eligible(
        Order $order,
        Customer $customer,
        TelegramOutboundEffect $effect,
    ): bool {
        if ((string) $customer->getKey() !== (string) $order->customer_id
            || preg_match('/\A[1-9][0-9]*\z/D', trim((string) $customer->telegram_id)) !== 1
            || (string) $effect->telegram_chat_id !== (string) $customer->telegram_id
            || $effect->target_type !== 'customer'
            || $order->telegram_receipt_sent_at === null
            || $order->payment_status !== PaymentStatus::Paid
            || ! in_array($order->order_status, [OrderStatus::PendingPayment, OrderStatus::Processing, OrderStatus::Completed], true)
            || $order->hasOpenLatePaymentReview()) {
            return false;
        }

        $payment = $order->payments
            ->sortByDesc(fn (Payment $candidate): array => [
                $candidate->created_at?->getTimestamp() ?? 0,
                (string) $candidate->getKey(),
            ])
            ->first();

        return $payment instanceof Payment
            && (string) $payment->order_id === (string) $order->getKey()
            && $payment->status === PaymentStatus::Paid
            && $payment->paid_at !== null;
    }

    private function receiptGroupCompleted(TelegramOutboundEffect $effect): bool
    {
        $metadata = is_array($effect->outcome_metadata) ? $effect->outcome_metadata : [];
        $groupKey = $metadata['receipt_group_key'] ?? null;
        if (! is_string($groupKey) || $groupKey === '') {
            return false;
        }

        $receipts = TelegramOutboundEffect::query()
            ->where('source_type', Order::class)
            ->where('source_id', (string) $effect->source_id)
            ->where('effect_type', TelegramOutboundEffectType::ReceiptCredentials->value)
            ->where('effect_group_key', $groupKey)
            ->orderBy('sequence')
            ->get();
        $first = $receipts->first();
        $firstMetadata = is_array($first?->outcome_metadata) ? $first->outcome_metadata : [];

        return $first !== null
            && $first->telegram_method === 'sendPhoto'
            && $first->template_version === TelegramOutboundEffectService::TEMPLATE_RECEIPT_PHOTO_CAPTION
            && ($firstMetadata['receipt_actions_version'] ?? null) === TelegramOutboundEffectService::RECEIPT_ACTIONS_OPT_IN_VERSION
            && ! $receipts->contains(fn (TelegramOutboundEffect $receipt): bool => $receipt->state !== TelegramOutboundEffectState::Sent);
    }

    private function finalizeNotDeliverable(
        TelegramOutboundEffect $effect,
        string $reason,
        string $message,
    ): ?TelegramOutboundEffect {
        return $this->ledger->finalizeNotDeliverable(
            (string) $effect->getKey(),
            (string) $effect->claim_token,
            $reason,
            $message,
        );
    }

    private function recordOutcome(
        ?TelegramOutboundEffect $effect,
        string $outcome,
        int $deliveryStartedAt,
        ?string $jobEnqueuedAt,
    ): void {
        if ($effect === null) {
            return;
        }

        $this->observability->actionPanelOutcome(
            $effect,
            $outcome,
            DogfoodTiming::elapsedMs($deliveryStartedAt),
            $effect->last_error_code,
            DogfoodTiming::queueWaitMs($jobEnqueuedAt),
        );
    }
}
