<?php

namespace App\Services\Telegram;

use App\Exceptions\TelegramTransportException;
use App\Models\Order;
use App\Models\TelegramOutboundEffect;
use App\Support\DogfoodTiming;
use InvalidArgumentException;
use Throwable;

/**
 * Receipt is an effect-family adapter. Claiming, leases, ambiguity, and
 * token-checked state transitions remain owned by the shared ledger facade.
 */
final class ReceiptDeliveryPipeline
{
    public function __construct(
        private readonly TelegramOutboundEffectService $outboundEffects,
        private readonly TelegramReceiptNotifier $notifier,
    ) {}

    public function deliver(
        TelegramOutboundEffect $effect,
        ?TelegramReceiptNotifier $notifier = null,
        bool $dispatchNext = true,
        bool $throwOnAmbiguous = false,
    ): void {
        if ($effect->effect_type !== TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS) {
            throw new InvalidArgumentException('Unsupported Telegram receipt effect type.');
        }

        $claimed = $this->outboundEffects->claim((string) $effect->getKey());
        if ($claimed === null) {
            return;
        }

        try {
            $order = Order::query()
                ->with(['customer', 'orderItems.stockItems', 'orderItems.product'])
                ->findOrFail($claimed->source_id);

            if (! $this->outboundEffects->receiptIsEligible($order)
                || (string) $order->customer->telegram_id !== (string) $claimed->telegram_chat_id) {
                $this->outboundEffects->finalizeNotDeliverable(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    'receipt_not_eligible',
                    'Telegram receipt was no longer eligible for delivery.',
                );

                return;
            }

            $response = DogfoodTiming::time('dogfood.telegram.receipt_step', [
                'step' => 'receipt_chunk',
                'sequence' => (int) $claimed->sequence,
                'chunk_group_correlation_id' => DogfoodTiming::correlation($claimed->effect_group_key),
                'customer_correlation_id' => DogfoodTiming::correlation($order->customer->telegram_id),
                'order_correlation_id' => DogfoodTiming::correlation($order->id),
            ], fn () => ($notifier ?? $this->notifier)->sendEffect($order, $claimed));
            $messageId = data_get($response, 'result.message_id');

            if (! is_numeric($messageId)) {
                throw new TelegramTransportException('sendMessage');
            }

            if ($this->outboundEffects->finalizeSent(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                (string) $messageId,
            )) {
                if ($dispatchNext) {
                    $next = $this->outboundEffects->nextReceiptEffect($claimed->fresh());
                    if ($next !== null) {
                        $this->outboundEffects->dispatch($next);
                    }
                }

                if ($this->outboundEffects->isExplicitReceiptEffect($claimed)
                    && $this->outboundEffects->receiptGroupComplete($claimed)) {
                    $this->outboundEffects->dispatchReceiptCleanup($order->id);
                }
            }
        } catch (Throwable $exception) {
            if ($this->outboundEffects->isAmbiguous($exception)) {
                $this->outboundEffects->finalizeUnknown(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    $exception,
                );

                if ($throwOnAmbiguous) {
                    throw $exception;
                }

                return;
            }

            $this->outboundEffects->finalizeFailed(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                $exception,
            );
        }
    }
}
