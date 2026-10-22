<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Jobs\DeleteBuyerCheckoutMessages;
use App\Models\Order;
use App\Models\TelegramOutboundEffect;
use App\Services\Suppliers\SupplierFulfillmentService;
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
                $this->observeSentEffect($finalized, $order);
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

                // New photo-caption receipts can contain credentials; an
                // uncertain delivery must block both fallback and successors.
                if ($finalized !== null
                    && ! $this->isInitialInvoicePhoto($claimed)
                    && $dispatchNext
                    && $this->isInvoiceEffect($finalized)) {
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
            if ($finalized !== null && $this->isInitialInvoicePhoto($claimed)) {
                $fallback = $this->outboundEffects->recordReceiptTextFallback($finalized);
                if ($fallback !== null) {
                    $this->deliverReceiptTextFallback(
                        $fallback,
                        $notifier,
                        $dispatchNext,
                        $throwOnAmbiguous,
                    );
                }

                return;
            }

            if ($finalized !== null && $dispatchNext && $this->isInvoiceEffect($finalized)) {
                $this->dispatchNextReceiptEffect($finalized);
            }
        } finally {
            app(AdminOrderNotificationDeliveryPipeline::class)->wakePaidOrder((string) $claimed->source_id);
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
        $effects = app(TelegramOutboundRecoveryPage::class)->load(
            'missing_receipt_action_panels',
            TelegramOutboundEffect::query()
                ->where('source_type', Order::class)
                ->where('effect_type', TelegramOutboundEffectType::ReceiptCredentials->value)
                ->whereIn('template_version', [TelegramOutboundEffectService::TEMPLATE_RECEIPT_PHOTO_CAPTION, TelegramOutboundEffectService::TEMPLATE_RECEIPT_TEXT_FALLBACK])
                ->where('sequence', 1)
                ->where('state', TelegramOutboundEffectState::Sent->value)
                ->whereNotNull('effect_group_key'),
            max(1, $chunk),
        );

        foreach ($effects as $first) {
            $last = TelegramOutboundEffect::query()
                ->where('effect_group_key', $first->effect_group_key)
                ->orderByDesc('sequence')
                ->first();
            if ($last !== null && $this->materializeCompletedActionPanel($last, $dispatch) !== null) {
                $materialized++;
            }
        }

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
        $orders = app(TelegramOutboundRecoveryPage::class)->load(
            'completed_receipt_projections',
            Order::query()
                ->whereNull('telegram_receipt_sent_at')
                ->whereExists(function ($effects): void {
                    $effects
                        ->selectRaw('1')
                        ->from('telegram_outbound_effects as sent_receipt_effects')
                        ->where('sent_receipt_effects.source_type', Order::class)
                        ->where('sent_receipt_effects.effect_type', TelegramOutboundEffectType::ReceiptCredentials->value)
                        ->where('sent_receipt_effects.state', TelegramOutboundEffectState::Sent->value)
                        ->whereNotNull('sent_receipt_effects.effect_group_key')
                        ->whereRaw('sent_receipt_effects.source_id = CAST(orders.id AS TEXT)');
                })
                ->select('id'),
            max(1, $chunk),
        );
        $orderIds = $orders->modelKeys();
        if ($orderIds === []) {
            return [];
        }

        $sentEffects = TelegramOutboundEffect::query()
            ->where('source_type', Order::class)
            ->where('effect_type', TelegramOutboundEffectType::ReceiptCredentials->value)
            ->where('state', TelegramOutboundEffectState::Sent->value)
            ->whereNotNull('effect_group_key')
            ->whereIn('source_id', array_map(static fn ($id): string => (string) $id, $orderIds))
            ->orderBy('source_id')
            ->orderBy('effect_group_key')
            ->orderBy('sequence')
            ->get()
            ->groupBy(static fn (TelegramOutboundEffect $effect): string => (string) $effect->source_id);

        $repairedOrderIds = [];
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

        return $repairedOrderIds;
    }

    public function reconcileMissingCredentialPins(int $chunk = 100): int
    {
        return $this->credentialPins->reconcileMissingPins($chunk);
    }

    /**
     * Record the two receipt boundaries the baseline measures: the first
     * Telegram acknowledgement for a credential-bearing unit and completion of
     * the whole receipt group. A Telegram acknowledgement proves the Bot API
     * accepted the message, never that the customer read it.
     */
    private function observeSentEffect(TelegramOutboundEffect $effect, Order $order): void
    {
        if (! DogfoodTiming::enabled()) {
            return;
        }

        $context = [
            'order_correlation_id' => DogfoodTiming::correlation($order->getKey()),
            'customer_correlation_id' => DogfoodTiming::correlation($order->customer?->telegram_id ?: $order->customer_id),
            'effect_correlation_id' => DogfoodTiming::correlation($effect->getKey()),
            'sequence' => (int) $effect->sequence,
        ];

        if ($this->isCredentialBearing($effect) && ! $this->hasEarlierCredentialAcknowledgement($effect)) {
            DogfoodTiming::log('dogfood.telegram.receipt_first_credential_acknowledged', $context + [
                'telegram_acknowledged_at' => now()->toISOString(),
            ]);
        }

        if ($this->ledger->isReceiptGroupComplete($effect->effect_group_key)) {
            DogfoodTiming::log('dogfood.telegram.receipt_completed', $context + [
                'receipt_completed_at' => now()->toISOString(),
            ]);
        }
    }

    private function isCredentialBearing(TelegramOutboundEffect $effect): bool
    {
        $metadata = is_array($effect->outcome_metadata) ? $effect->outcome_metadata : [];

        return ($metadata['contains_credentials'] ?? null) !== false
            && ! ((int) $effect->sequence === 1
                && $effect->template_version === TelegramOutboundEffectService::TEMPLATE_RECEIPT_INVOICE);
    }

    private function hasEarlierCredentialAcknowledgement(TelegramOutboundEffect $effect): bool
    {
        return TelegramOutboundEffect::query()
            ->where('effect_group_key', $effect->effect_group_key)
            ->where('sequence', '<', (int) $effect->sequence)
            ->where('state', TelegramOutboundEffectState::Sent->value)
            ->get(['id', 'sequence', 'template_version', 'outcome_metadata'])
            ->contains(fn (TelegramOutboundEffect $earlier): bool => $this->isCredentialBearing($earlier));
    }

    private function receiptIsEligible(Order $order): bool
    {
        return $order->payment_status === PaymentStatus::Paid
            && in_array($order->order_status, [OrderStatus::PendingPayment, OrderStatus::Processing, OrderStatus::Completed], true)
            && $order->customer?->telegram_id !== null
            && ! app(SupplierFulfillmentService::class)->hasExternalHandoff($order)
            && ! app(SupplierFulfillmentService::class)->hasPending($order);
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

    private function isInitialInvoicePhoto(TelegramOutboundEffect $effect): bool
    {
        return $effect->template_version === TelegramOutboundEffectService::TEMPLATE_RECEIPT_PHOTO_CAPTION
            && (int) $effect->sequence === 1
            && $effect->telegram_method === 'sendPhoto';
    }

    private function deliverReceiptTextFallback(
        TelegramOutboundEffect $fallback,
        ?TelegramReceiptNotifier $notifier,
        bool $dispatchNext,
        bool $throwOnAmbiguous,
    ): void {
        if ($dispatchNext) {
            $this->dispatcher->dispatch($fallback);

            return;
        }

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
