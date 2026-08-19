<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectType;
use App\Models\Order;
use App\Models\TelegramOutboundEffect;
use InvalidArgumentException;
use Throwable;

/**
 * Adapter for the three lifecycle notifications sent to the configured admin
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
            $order = Order::query()
                ->with(['customer', 'orderItems', 'payments'])
                ->findOrFail($claimed->source_id);

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

    private function eligible(Order $order, string $effectType): bool
    {
        return match ($effectType) {
            TelegramOutboundEffectType::AdminOrderPendingNotification->value => $order->order_status === OrderStatus::PendingPayment
                && $order->payment_status === PaymentStatus::Pending,
            TelegramOutboundEffectType::AdminOrderPaidNotification->value => in_array($order->order_status, [OrderStatus::Processing, OrderStatus::Completed], true)
                && $order->payment_status === PaymentStatus::Paid,
            TelegramOutboundEffectType::AdminOrderExpiredNotification->value => $order->order_status === OrderStatus::Expired
                && $order->payment_status === PaymentStatus::Expired,
            default => false,
        };
    }

    private function synchronizeSentProjection(TelegramOutboundEffect $effect): void
    {
        $values = match ($effect->effect_type) {
            TelegramOutboundEffectType::AdminOrderPendingNotification->value => [
                'admin_pending_order_notified_at' => $effect->sent_at ?? now(),
                'admin_pending_order_notification_chat_id' => $effect->telegram_chat_id,
                'admin_pending_order_notification_message_id' => $this->responseValidator->isValidMessageId((string) $effect->telegram_message_id)
                    ? (int) $effect->telegram_message_id
                    : null,
                'admin_pending_order_notification_deleted_at' => null,
            ],
            TelegramOutboundEffectType::AdminOrderPaidNotification->value => [
                'admin_paid_order_notified_at' => $effect->sent_at ?? now(),
            ],
            TelegramOutboundEffectType::AdminOrderExpiredNotification->value => [
                'admin_expired_order_notified_at' => $effect->sent_at ?? now(),
            ],
            default => [],
        };

        if ($values !== []) {
            Order::query()->whereKey($effect->source_id)->update($values + ['updated_at' => now()]);
        }
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
