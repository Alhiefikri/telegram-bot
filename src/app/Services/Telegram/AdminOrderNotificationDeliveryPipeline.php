<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Models\ManualFulfillmentReview;
use App\Models\Order;
use App\Models\TelegramOutboundEffect;
use InvalidArgumentException;
use Throwable;

/**
 * Adapter for Order lifecycle notifications sent to the configured admin
 * chat. Eligibility, rendering, cleanup, and the Order projection stay here;
 * the ledger protocol owns every durable delivery transition.
 */
final class AdminOrderNotificationDeliveryPipeline
{
    /** @var list<string> */
    private const EFFECT_TYPES = [
        TelegramOutboundEffectType::AdminOrderPendingNotification->value,
        TelegramOutboundEffectType::AdminOrderPaidNotification->value,
        TelegramOutboundEffectType::AdminOrderExpiredNotification->value,
        TelegramOutboundEffectType::AdminOrderReviewNotification->value,
    ];

    public function __construct(
        private readonly TelegramOutboundEffectLedgerProtocol $ledger,
        private readonly TelegramAdminOrderNotifier $adminOrderNotifier,
        private readonly TelegramAdminPaidOrderNotifier $adminPaidOrderNotifier,
        private readonly TelegramOutboundResponseValidator $responseValidator,
    ) {}

    public function deliver(TelegramOutboundEffect $effect): void
    {
        if (! in_array((string) $effect->effect_type, self::EFFECT_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported Telegram admin notification effect type.');
        }

        $claimed = $this->ledger->claim((string) $effect->getKey());
        if ($claimed === null) {
            return;
        }

        try {
            $sourceId = $claimed->effect_type === TelegramOutboundEffectType::AdminOrderReviewNotification->value
                ? ManualFulfillmentReview::query()->whereKey($claimed->source_id)->value('order_id')
                : $claimed->source_id;
            $order = Order::query()
                ->with(['customer', 'orderItems', 'payments'])
                ->findOrFail($sourceId);

            if (blank(config('services.telegram.token'))
                || preg_match('/^[1-9][0-9]*$/', trim((string) $claimed->telegram_chat_id)) !== 1) {
                $this->finalizeNotDeliverable($claimed, 'admin_target_missing', 'Telegram admin notification has no usable delivery target.');

                return;
            }

            if (! $this->eligible($order, (string) $claimed->effect_type)) {
                $this->finalizeNotDeliverable($claimed, 'admin_notification_not_eligible', 'Telegram admin notification was no longer eligible for delivery.');

                return;
            }

            if ($claimed->effect_type === TelegramOutboundEffectType::AdminOrderPaidNotification->value) {
                // Cleanup is best effort and is not a durable business effect.
                $this->adminOrderNotifier->deletePending($order);
            }

            $response = match ($claimed->effect_type) {
                TelegramOutboundEffectType::AdminOrderPendingNotification->value => $this->adminOrderNotifier->sendPendingEffect(
                    $order,
                    (string) $claimed->telegram_chat_id,
                ),
                TelegramOutboundEffectType::AdminOrderPaidNotification->value => $this->adminPaidOrderNotifier->sendEffect(
                    $order,
                    (string) $claimed->telegram_chat_id,
                ),
                TelegramOutboundEffectType::AdminOrderReviewNotification->value => $this->adminPaidOrderNotifier->sendEffect(
                    $order,
                    (string) $claimed->telegram_chat_id,
                    reviewNotice: true,
                ),
                TelegramOutboundEffectType::AdminOrderExpiredNotification->value => $this->adminOrderNotifier->sendExpiredEffect(
                    $order,
                    (string) $claimed->telegram_chat_id,
                ),
                default => throw new InvalidArgumentException('Unsupported Telegram admin notification effect type.'),
            };
            $messageId = $this->responseValidator->messageIdFromResponse($response, 'Telegram admin notification');
            $finalized = $this->ledger->finalizeSent(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                $messageId,
            );

            if ($finalized !== null) {
                $this->synchronizeSentProjection($finalized);
            }

            if ($claimed->effect_type === TelegramOutboundEffectType::AdminOrderExpiredNotification->value) {
                $this->adminOrderNotifier->deletePending($order);
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
     * Repair Order projections for admin Order-notification effects already
     * finalized as sent.
     *
     * Recovery only scans sent ledger rows; it never claims or dispatches an
     * effect. Pending cleanup metadata remains authoritative when it was
     * already recorded.
     */
    public function reconcileSentProjections(int $chunk = 100): int
    {
        $repaired = 0;
        $effects = app(TelegramOutboundRecoveryPage::class)->load(
            'sent_admin_order_projections',
            TelegramOutboundEffect::query()
                ->where('source_type', Order::class)
                ->whereIn('effect_type', self::EFFECT_TYPES)
                ->where('state', TelegramOutboundEffectState::Sent->value)
                ->whereNotNull('telegram_message_id')
                // An explicit resend is the only sent version allowed to repair.
                ->whereNotExists(function ($newer): void {
                    $newer
                        ->selectRaw('1')
                        ->from('telegram_outbound_effects as newer_admin_effects')
                        ->whereColumn('newer_admin_effects.source_type', 'telegram_outbound_effects.source_type')
                        ->whereColumn('newer_admin_effects.source_id', 'telegram_outbound_effects.source_id')
                        ->whereColumn('newer_admin_effects.effect_type', 'telegram_outbound_effects.effect_type')
                        ->where('newer_admin_effects.state', TelegramOutboundEffectState::Sent->value)
                        ->whereColumn('newer_admin_effects.sequence', '>', 'telegram_outbound_effects.sequence');
                }),
            max(1, $chunk),
        );

        foreach ($effects as $effect) {
            if ($this->synchronizeSentProjection($effect, preservePendingCleanup: true)) {
                $repaired++;
            }
        }

        return $repaired;
    }

    public function recoverMissingReviews(int $chunk = 100): int
    {
        $reviews = app(TelegramOutboundRecoveryPage::class)->load(
            'owner_review_handoffs',
            ManualFulfillmentReview::query()->ownerNotifiable()
                ->whereNotExists(function ($effects): void {
                    $effects->selectRaw('1')->from('telegram_outbound_effects')
                        ->where('source_type', ManualFulfillmentReview::class)
                        ->where('effect_type', TelegramOutboundEffectType::AdminOrderReviewNotification->value)
                        ->whereRaw('source_id = CAST(manual_fulfillment_reviews.id AS TEXT)');
                }),
            max(1, $chunk),
        );
        $dispatched = 0;
        foreach ($reviews as $review) {
            $effect = app(TelegramOutboundEffectService::class)->recordAdminOrderReviewNotification($review);
            if ($effect !== null) {
                app(TelegramOutboundEffectDispatcher::class)->dispatch($effect);
                $dispatched++;
            }
        }

        return $dispatched;
    }

    private function eligible(Order $order, string $effectType): bool
    {
        return match ($effectType) {
            TelegramOutboundEffectType::AdminOrderReviewNotification->value => ManualFulfillmentReview::query()
                ->where('order_id', $order->id)->ownerNotifiable()->exists(),
            TelegramOutboundEffectType::AdminOrderPendingNotification->value => $order->order_status === OrderStatus::PendingPayment
                && $order->payment_status === PaymentStatus::Pending,
            TelegramOutboundEffectType::AdminOrderPaidNotification->value => in_array($order->order_status, [OrderStatus::Processing, OrderStatus::Completed], true)
                && $order->payment_status === PaymentStatus::Paid,
            TelegramOutboundEffectType::AdminOrderExpiredNotification->value => $order->order_status === OrderStatus::Expired
                && $order->payment_status === PaymentStatus::Expired,
            default => false,
        };
    }

    private function synchronizeSentProjection(
        TelegramOutboundEffect $effect,
        bool $preservePendingCleanup = false,
    ): bool {
        $messageId = (string) $effect->telegram_message_id;
        if (! $this->responseValidator->isValidMessageId($messageId)) {
            return false;
        }

        $sentAt = $effect->sent_at ?? now();
        $values = match ($effect->effect_type) {
            TelegramOutboundEffectType::AdminOrderPendingNotification->value => [
                'admin_pending_order_notified_at' => $sentAt,
                'admin_pending_order_notification_chat_id' => $effect->telegram_chat_id,
                'admin_pending_order_notification_message_id' => (int) $messageId,
            ],
            TelegramOutboundEffectType::AdminOrderPaidNotification->value => [
                'admin_paid_order_notified_at' => $sentAt,
            ],
            TelegramOutboundEffectType::AdminOrderExpiredNotification->value => [
                'admin_expired_order_notified_at' => $sentAt,
            ],
            default => [],
        };

        if ($values === []) {
            return false;
        }

        if ($effect->effect_type === TelegramOutboundEffectType::AdminOrderPendingNotification->value) {
            if (! $preservePendingCleanup) {
                $values['admin_pending_order_notification_deleted_at'] = null;
            }

            return Order::query()
                ->whereKey($effect->source_id)
                ->where(function ($order) use ($effect, $messageId, $sentAt, $preservePendingCleanup): void {
                    $order
                        ->whereNull('admin_pending_order_notified_at')
                        ->orWhere('admin_pending_order_notified_at', '<>', $sentAt)
                        ->orWhereNull('admin_pending_order_notification_chat_id')
                        ->orWhere('admin_pending_order_notification_chat_id', '<>', $effect->telegram_chat_id)
                        ->orWhereNull('admin_pending_order_notification_message_id')
                        ->orWhere('admin_pending_order_notification_message_id', '<>', (int) $messageId);

                    if (! $preservePendingCleanup) {
                        $order->orWhereNotNull('admin_pending_order_notification_deleted_at');
                    }
                })
                ->update($values + ['updated_at' => now()]) === 1;
        }

        $notifiedColumn = match ($effect->effect_type) {
            TelegramOutboundEffectType::AdminOrderPaidNotification->value => 'admin_paid_order_notified_at',
            TelegramOutboundEffectType::AdminOrderExpiredNotification->value => 'admin_expired_order_notified_at',
            default => null,
        };
        if ($notifiedColumn === null) {
            return false;
        }

        return Order::query()
            ->whereKey($effect->source_id)
            ->whereNull($notifiedColumn)
            ->update($values + ['updated_at' => now()]) === 1;
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
