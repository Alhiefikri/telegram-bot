<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Exceptions\TelegramOutboundPreSendException;
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
        private readonly TelegramOutboundEffectService $outboundEffects,
        private readonly TelegramCredentialPinService $credentialPins,
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
                $this->credentialPins->pin($order, $finalized);
                $this->synchronizeSentProjection($finalized, $order->id);
                $this->materializeCompletedActionPanel($finalized);
                if ($dispatchNext) {
                    $next = $this->ledger->nextReceiptEffect(
                        $finalized->effect_group_key,
                        (int) $finalized->sequence,
                    );
                    if ($next !== null) {
                        $this->dispatcher->dispatch($next);
                    }
                }

                if ($this->isExplicitReceiptEffect($finalized)
                    && $this->ledger->isReceiptGroupComplete($finalized->effect_group_key)) {
                    $this->dispatchReceiptCleanup($order->id);
                }
            }
        } catch (Throwable $exception) {
            if ($this->ledger->isAmbiguous($exception)) {
                $finalized = $this->ledger->finalizeUnknown(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    $exception,
                );
                if ($finalized !== null && $dispatchNext && $this->isInvoiceEffect($finalized)) {
                    $this->dispatchNextReceiptEffect($finalized);
                }

                if ($throwOnAmbiguous) {
                    throw $exception;
                }

                return;
            }

            $finalized = $this->ledger->finalizeFailed(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                $exception,
            );
            if ($finalized !== null
                && ($this->isDeterministicImageFailure($claimed, $exception)
                    || $this->isRejectedInvoicePhoto($claimed, $finalized))) {
                $fallback = $this->outboundEffects->recordReceiptTextFallback($finalized);
                if ($fallback !== null) {
                    if ($dispatchNext) {
                        $this->dispatcher->dispatch($fallback);
                    } else {
                        $current = $fallback;
                        do {
                            $this->deliver(
                                $current,
                                $notifier,
                                dispatchNext: false,
                                throwOnAmbiguous: $throwOnAmbiguous,
                            );
                            $current = $current->fresh();
                            $current = $this->ledger->nextReceiptEffect(
                                $current->effect_group_key,
                                (int) $current->sequence,
                            );
                        } while ($current !== null);
                    }
                }

                return;
            }

            if ($finalized !== null && $dispatchNext && $this->isInvoiceEffect($finalized)) {
                $this->dispatchNextReceiptEffect($finalized);
            }
        }
    }

    /**
     * Materialize the panel only after every unit in a new receipt group is
     * sent. The effect row is the durable handoff if dispatch is interrupted.
     */
    public function materializeCompletedActionPanel(
        TelegramOutboundEffect $receiptEffect,
        bool $dispatch = true,
    ): ?TelegramOutboundEffect {
        if (! $this->ledger->isReceiptGroupComplete($receiptEffect->effect_group_key)) {
            return null;
        }

        $panel = $this->outboundEffects->recordReceiptActionPanel($receiptEffect);
        if ($panel !== null && $dispatch) {
            $this->dispatcher->dispatch($panel);
        }

        return $panel;
    }

    /**
     * Recover the committed receipt-complete to action-panel handoff. Only
     * receipt groups explicitly opted into the panel are considered, so legacy
     * and pre-panel groups remain immutable.
     */
    public function reconcileMissingActionPanels(int $chunk = 100, bool $dispatch = false): int
    {
        $materialized = 0;

        TelegramOutboundEffect::query()
            ->where('source_type', Order::class)
            ->where('effect_type', TelegramOutboundEffectType::ReceiptCredentials->value)
            ->where('template_version', TelegramOutboundEffectService::TEMPLATE_RECEIPT_PHOTO_CAPTION)
            ->where('sequence', 1)
            ->where('state', TelegramOutboundEffectState::Sent->value)
            ->whereNotNull('effect_group_key')
            ->orderBy('id')
            ->chunkById(max(1, $chunk), function ($effects) use (&$materialized, $dispatch): void {
                foreach ($effects as $first) {
                    $last = TelegramOutboundEffect::query()
                        ->where('effect_group_key', $first->effect_group_key)
                        ->orderByDesc('sequence')
                        ->first();
                    if ($last === null) {
                        continue;
                    }

                    if ($this->materializeCompletedActionPanel($last, $dispatch) !== null) {
                        $materialized++;
                    }
                }
            });

        return $materialized;
    }

    /**
     * Repair Order projections for receipt groups that already finished.
     *
     * Recovery only examines sent ledger rows; it never dispatches a receipt
     * effect. The conditional projection update fences concurrent recovery
     * scans and keeps cleanup dispatch idempotent. Recovery passes
     * `dispatchCleanup: false` so the command's existing cleanup scan retains
     * its eligibility and stale-claim behavior.
     */
    public function reconcileCompletedProjections(int $chunk = 100, bool $dispatchCleanup = true): array
    {
        $repairedOrderIds = [];
        // Polymorphic source_id is text while Order IDs are UUIDs on PostgreSQL;
        // collect matching source IDs before querying the typed Order key.
        $receiptOrderIds = TelegramOutboundEffect::query()
            ->where('source_type', Order::class)
            ->where('effect_type', TelegramOutboundEffectType::ReceiptCredentials->value)
            ->where('state', TelegramOutboundEffectState::Sent->value)
            ->whereNotNull('effect_group_key')
            ->distinct()
            ->pluck('source_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($receiptOrderIds === []) {
            return $repairedOrderIds;
        }

        Order::query()
            ->whereNull('telegram_receipt_sent_at')
            ->whereIn('id', $receiptOrderIds)
            ->select('id')
            ->orderBy('id')
            ->chunkById(max(1, $chunk), function ($orders) use (&$repairedOrderIds, $dispatchCleanup): void {
                $orderIds = $orders->modelKeys();
                if ($orderIds === []) {
                    return;
                }

                $sentEffects = TelegramOutboundEffect::query()
                    ->where('source_type', Order::class)
                    ->where('effect_type', TelegramOutboundEffectType::ReceiptCredentials->value)
                    ->where('state', TelegramOutboundEffectState::Sent->value)
                    ->whereNotNull('effect_group_key')
                    ->whereIn('source_id', array_map(
                        static fn ($id): string => (string) $id,
                        $orderIds,
                    ))
                    ->orderBy('source_id')
                    ->orderBy('effect_group_key')
                    ->orderBy('sequence')
                    ->get()
                    ->groupBy(fn (TelegramOutboundEffect $effect): string => (string) $effect->source_id);

                foreach ($orders as $order) {
                    $effectsForOrder = $sentEffects->get((string) $order->getKey());
                    if ($effectsForOrder === null) {
                        continue;
                    }

                    foreach ($effectsForOrder->groupBy('effect_group_key') as $group) {
                        $last = $group->last();
                        if ($last !== null && $this->synchronizeSentProjection($last, $order->getKey(), dispatchCleanup: $dispatchCleanup)) {
                            $repairedOrderIds[] = (string) $order->getKey();
                            break;
                        }
                    }
                }
            });

        return $repairedOrderIds;
    }

    public function reconcileMissingCredentialPins(int $chunk = 100): int
    {
        return $this->credentialPins->reconcileMissingPins($chunk);
    }

    private function receiptIsEligible(Order $order): bool
    {
        return $order->payment_status === PaymentStatus::Paid
            && in_array($order->order_status, [OrderStatus::PendingPayment, OrderStatus::Processing, OrderStatus::Completed], true)
            && $order->customer?->telegram_id !== null;
    }

    private function dispatchNextReceiptEffect(TelegramOutboundEffect $effect): void
    {
        $next = $this->ledger->nextReceiptEffect(
            $effect->effect_group_key,
            (int) $effect->sequence,
        );
        if ($next !== null) {
            $this->dispatcher->dispatch($next);
        }
    }

    private function isDeterministicImageFailure(
        TelegramOutboundEffect $effect,
        Throwable $exception,
    ): bool {
        return $effect->template_version === TelegramOutboundEffectService::TEMPLATE_RECEIPT_PHOTO_CAPTION
            && (int) $effect->sequence === 1
            && $exception instanceof TelegramOutboundPreSendException
            && str_contains($exception->getMessage(), 'paid invoice rendering failed before send');
    }

    private function isRejectedInvoicePhoto(
        TelegramOutboundEffect $claimed,
        TelegramOutboundEffect $failed,
    ): bool {
        return $claimed->template_version === TelegramOutboundEffectService::TEMPLATE_RECEIPT_PHOTO_CAPTION
            && (int) $claimed->sequence === 1
            && $claimed->telegram_method === 'sendPhoto'
            && in_array($failed->last_error_code, ['telegram_http_400', 'telegram_http_403'], true);
    }

    private function isInvoiceEffect(TelegramOutboundEffect $effect): bool
    {
        $metadata = is_array($effect->outcome_metadata) ? $effect->outcome_metadata : [];

        return ($metadata['unit_role'] ?? null) === 'invoice'
            || ($effect->telegram_method === 'sendPhoto'
                && $effect->template_version === TelegramOutboundEffectService::TEMPLATE_RECEIPT_INVOICE);
    }

    private function synchronizeSentProjection(
        TelegramOutboundEffect $effect,
        int|string $orderId,
        bool $dispatchCleanup = true,
    ): bool {
        if (! in_array($effect->effect_group_key, [
            TelegramOutboundEffectService::receiptEffectGroupKey((string) $orderId),
            TelegramOutboundEffectService::receiptTextFallbackEffectGroupKey((string) $orderId),
            TelegramOutboundEffectService::legacyReceiptEffectGroupKey((string) $orderId),
        ], true)) {
            return false;
        }

        if (! $this->ledger->isReceiptGroupComplete($effect->effect_group_key)) {
            return false;
        }

        $updated = Order::query()
            ->whereKey($orderId)
            ->whereNull('telegram_receipt_sent_at')
            ->update([
                'telegram_receipt_sent_at' => $effect->sent_at ?? now(),
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            return false;
        }

        if ($dispatchCleanup) {
            $this->dispatchReceiptCleanup($orderId);
        }

        return true;
    }

    private function isExplicitReceiptEffect(TelegramOutboundEffect $effect): bool
    {
        return ($effect->outcome_metadata['delivery_context'] ?? null) === 'explicit_resend';
    }

    private function dispatchReceiptCleanup(int|string $orderId): void
    {
        DeleteBuyerCheckoutMessages::dispatch((string) $orderId, now()->toISOString())
            ->onQueue('notifications')
            ->afterCommit();
    }
}
