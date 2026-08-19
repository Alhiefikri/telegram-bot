<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectType;
use App\Jobs\DeleteBuyerCheckoutMessages;
use App\Models\Order;
use App\Models\TelegramOutboundEffect;
use App\Support\DogfoodTiming;
use InvalidArgumentException;
use Throwable;

/**
 * Receipt is an effect-family adapter. Claiming, leases, ambiguity, and
 * token-checked state transitions remain owned by the shared ledger protocol.
 */
final class ReceiptDeliveryPipeline
{
    public function __construct(
        private readonly TelegramOutboundEffectLedgerProtocol $ledger,
        private readonly TelegramOutboundEffectDispatcher $dispatcher,
        private readonly TelegramReceiptNotifier $notifier,
        private readonly TelegramOutboundResponseValidator $responseValidator,
    ) {}

    public function deliver(
        TelegramOutboundEffect $effect,
        ?TelegramReceiptNotifier $notifier = null,
        bool $dispatchNext = true,
        bool $throwOnAmbiguous = false,
    ): void {
        if ($effect->effect_type !== TelegramOutboundEffectType::ReceiptCredentials->value) {
            throw new InvalidArgumentException('Unsupported Telegram receipt effect type.');
        }

        $claimed = $this->ledger->claim((string) $effect->getKey());
        if ($claimed === null) {
            return;
        }

        try {
            $order = Order::query()
                ->with(['customer', 'orderItems.stockItems', 'orderItems.product'])
                ->findOrFail($claimed->source_id);

            if (! $this->receiptIsEligible($order)
                || (string) $order->customer?->telegram_id !== (string) $claimed->telegram_chat_id) {
                $this->ledger->finalizeNotDeliverable(
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
            $messageId = $this->responseValidator->messageIdFromResponse($response, 'Telegram receipt');

            $finalized = $this->ledger->finalizeSent(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                (string) $messageId,
            );
            if ($finalized !== null) {
                $this->synchronizeSentProjection($finalized, $order->id);

                if ($dispatchNext) {
                    $next = $this->nextReceiptEffect($finalized);
                    if ($next !== null) {
                        $this->dispatcher->dispatch($next);
                    }
                }

                if ($this->isExplicitReceiptEffect($finalized) && $this->receiptGroupComplete($finalized)) {
                    $this->dispatchReceiptCleanup($order->id);
                }
            }
        } catch (Throwable $exception) {
            if ($this->ledger->isAmbiguous($exception)) {
                $this->ledger->finalizeUnknown(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    $exception,
                );

                if ($throwOnAmbiguous) {
                    throw $exception;
                }

                return;
            }

            $this->ledger->finalizeFailed(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                $exception,
            );
        }
    }

    private function receiptIsEligible(Order $order): bool
    {
        return $order->payment_status === PaymentStatus::Paid
            && in_array($order->order_status, [OrderStatus::PendingPayment, OrderStatus::Processing, OrderStatus::Completed], true)
            && $order->customer?->telegram_id !== null;
    }

    private function nextReceiptEffect(TelegramOutboundEffect $effect): ?TelegramOutboundEffect
    {
        if ($effect->effect_type !== TelegramOutboundEffectType::ReceiptCredentials->value
            || $effect->effect_group_key === null) {
            return null;
        }

        return TelegramOutboundEffect::query()
            ->where('effect_group_key', $effect->effect_group_key)
            ->where('sequence', '>', $effect->sequence)
            ->whereIn('state', [
                'pending',
                'failed',
            ])
            ->orderBy('sequence')
            ->get()
            ->first(fn (TelegramOutboundEffect $candidate): bool => $this->ledger->isDispatchable($candidate));
    }

    private function synchronizeSentProjection(TelegramOutboundEffect $effect, int|string $orderId): void
    {
        if ($effect->effect_group_key !== TelegramOutboundEffectService::receiptEffectGroupKey((string) $orderId)) {
            return;
        }

        $hasUnsentUnit = TelegramOutboundEffect::query()
            ->where('effect_group_key', $effect->effect_group_key)
            ->where('state', '<>', 'sent')
            ->exists();
        if ($hasUnsentUnit) {
            return;
        }

        $updated = Order::query()
            ->whereKey($orderId)
            ->whereNull('telegram_receipt_sent_at')
            ->update([
                'telegram_receipt_sent_at' => $effect->sent_at ?? now(),
                'updated_at' => now(),
            ]);

        if ($updated === 1) {
            $this->dispatchReceiptCleanup($orderId);
        }
    }

    private function isExplicitReceiptEffect(TelegramOutboundEffect $effect): bool
    {
        return ($effect->outcome_metadata['delivery_context'] ?? null) === 'explicit_resend';
    }

    private function receiptGroupComplete(TelegramOutboundEffect $effect): bool
    {
        return $effect->effect_group_key !== null
            && ! TelegramOutboundEffect::query()
                ->where('effect_group_key', $effect->effect_group_key)
                ->where('state', '<>', 'sent')
                ->exists();
    }

    private function dispatchReceiptCleanup(int|string $orderId): void
    {
        DeleteBuyerCheckoutMessages::dispatch((string) $orderId, now()->toISOString())
            ->onQueue('notifications')
            ->afterCommit();
    }
}
