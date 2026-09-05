<?php

namespace App\Services\Telegram;

use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Jobs\DeleteBuyerCheckoutMessages;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductPriceChangeEvent;
use App\Models\ProductPriceChangeNotificationRecipient;
use App\Models\SalesProofPost;
use App\Models\StockNotificationRecipient;
use App\Models\TelegramBroadcastRecipient;
use App\Models\TelegramOutboundEffect;
use Carbon\CarbonInterface;

/**
 * Applies the read projection after an operator resolves an ambiguous effect.
 * Worker delivery projections stay in their family adapters; this small
 * review boundary exists only because operator resolution can target any
 * durable family from one admin screen.
 */
final class TelegramOutboundEffectReviewProjection
{
    public function synchronizeSent(
        TelegramOutboundEffect $effect,
        string $messageId,
        CarbonInterface $at,
    ): void {
        match ($effect->effect_type) {
            TelegramOutboundEffectType::SalesProofPost->value => $this->salesProof($effect, $messageId, $at),
            TelegramOutboundEffectType::ReceiptCredentials->value => $this->receipt($effect, $at),
            TelegramOutboundEffectType::ManualPaymentProof->value => $this->paymentProof($effect, $at),
            TelegramOutboundEffectType::StockNotificationRecipient->value => $this->stock($effect, $at),
            TelegramOutboundEffectType::PriceChangeNotificationRecipient->value => $this->priceRecipient($effect, $at),
            TelegramOutboundEffectType::PriceChangeStoreChannel->value => $this->priceStoreChannel($effect, $messageId, $at),
            TelegramOutboundEffectType::AdminBroadcastRecipient->value => $this->broadcast($effect, $at),
            TelegramOutboundEffectType::AdminOrderPendingNotification->value,
            TelegramOutboundEffectType::AdminOrderPaidNotification->value,
            TelegramOutboundEffectType::AdminOrderExpiredNotification->value => $this->adminOrder($effect, $messageId, $at),
            TelegramOutboundEffectType::ReceiptOrderActions->value => null,
            TelegramOutboundEffectType::ManualCheckoutInstructions->value,
            TelegramOutboundEffectType::KlikQrisCheckoutInstructions->value,
            TelegramOutboundEffectType::SakurupiahCheckoutInstructions->value,
            TelegramOutboundEffectType::PakasirCheckoutInstructions->value,
            TelegramOutboundEffectType::BinanceCheckoutInstructions->value,
            TelegramOutboundEffectType::BscCheckoutInstructions->value => $this->checkout($effect, $messageId, $at),
            default => null,
        };
    }

    private function salesProof(TelegramOutboundEffect $effect, string $messageId, CarbonInterface $at): void
    {
        SalesProofPost::query()->where('order_id', $effect->source_id)->update([
            'status' => SalesProofPost::STATUS_SENT,
            'attempts' => (int) $effect->attempts,
            'telegram_message_id' => $messageId,
            'sent_at' => $at,
            'last_error' => null,
            'updated_at' => now(),
        ]);
    }

    private function receipt(TelegramOutboundEffect $effect, CarbonInterface $at): void
    {
        if (! in_array($effect->effect_group_key, [
            TelegramOutboundEffectService::receiptEffectGroupKey((string) $effect->source_id),
            TelegramOutboundEffectService::receiptTextFallbackEffectGroupKey((string) $effect->source_id),
            TelegramOutboundEffectService::legacyReceiptEffectGroupKey((string) $effect->source_id),
        ], true)) {
            return;
        }

        $hasUnsentTextUnit = TelegramOutboundEffect::query()
            ->where('effect_group_key', $effect->effect_group_key)
            ->where('state', '<>', TelegramOutboundEffectState::Sent->value)
            ->get()
            ->contains(fn (TelegramOutboundEffect $candidate): bool => ! $this->isInvoiceEffect($candidate));
        if ($hasUnsentTextUnit) {
            return;
        }

        $updated = Order::query()
            ->whereKey($effect->source_id)
            ->whereNull('telegram_receipt_sent_at')
            ->update([
                'telegram_receipt_sent_at' => $at,
                'updated_at' => now(),
            ]);
        if ($updated === 1) {
            DeleteBuyerCheckoutMessages::dispatch((string) $effect->source_id, now()->toISOString())
                ->onQueue('notifications')
                ->afterCommit();
        }
    }

    private function isInvoiceEffect(TelegramOutboundEffect $effect): bool
    {
        $metadata = is_array($effect->outcome_metadata) ? $effect->outcome_metadata : [];

        return ($metadata['unit_role'] ?? null) === 'invoice'
            || ($effect->telegram_method === 'sendPhoto'
                && $effect->template_version === TelegramOutboundEffectService::TEMPLATE_RECEIPT_INVOICE);
    }

    private function paymentProof(TelegramOutboundEffect $effect, CarbonInterface $at): void
    {
        Payment::query()
            ->whereKey($effect->source_id)
            ->whereNull('payment_proof_forwarded_at')
            ->update([
                'payment_proof_admin_chat_id' => $effect->telegram_chat_id,
                'payment_proof_forwarded_at' => $at,
                'updated_at' => now(),
            ]);
    }

    private function stock(TelegramOutboundEffect $effect, CarbonInterface $at): void
    {
        StockNotificationRecipient::query()
            ->whereKey($effect->source_id)
            ->whereNull('sent_at')
            ->update([
                'sent_at' => $at,
                'failed_at' => null,
                'terminal_failed_at' => null,
                'last_error' => null,
                'attempts' => (int) $effect->attempts,
                'updated_at' => now(),
            ]);

        $movementId = StockNotificationRecipient::query()
            ->whereKey($effect->source_id)
            ->join('stock_notification_events', 'stock_notification_events.id', '=', 'stock_notification_recipients.stock_notification_event_id')
            ->value('stock_notification_events.inventory_movement_id');
        if ($movementId !== null) {
            app(StockNotificationDeliveryPipeline::class)->refreshAggregateProjection((int) $movementId);
        }
    }

    private function priceRecipient(TelegramOutboundEffect $effect, CarbonInterface $at): void
    {
        ProductPriceChangeNotificationRecipient::query()
            ->whereKey($effect->source_id)
            ->whereNull('sent_at')
            ->update([
                'sent_at' => $at,
                'failed_at' => null,
                'last_error' => null,
                'attempts' => (int) $effect->attempts,
                'updated_at' => now(),
            ]);
    }

    private function priceStoreChannel(TelegramOutboundEffect $effect, string $messageId, CarbonInterface $at): void
    {
        ProductPriceChangeEvent::query()->whereKey($effect->source_id)->update([
            'store_channel_sent_at' => $at,
            'store_channel_failed_at' => null,
            'store_channel_last_error' => null,
            'store_channel_message_id' => $messageId,
            'store_channel_attempts' => (int) $effect->attempts,
            'updated_at' => now(),
        ]);
    }

    private function broadcast(TelegramOutboundEffect $effect, CarbonInterface $at): void
    {
        TelegramBroadcastRecipient::query()
            ->whereKey($effect->source_id)
            ->whereNull('sent_at')
            ->update([
                'sent_at' => $at,
                'failed_at' => null,
                'retry_at' => null,
                'terminal_failed_at' => null,
                'last_error' => null,
                'attempts' => (int) $effect->attempts,
                'updated_at' => now(),
            ]);

        $broadcastId = TelegramBroadcastRecipient::query()->whereKey($effect->source_id)->value('broadcast_id');
        if ($broadcastId !== null) {
            app(AdminBroadcastDeliveryPipeline::class)->refreshProjection((int) $broadcastId);
        }
    }

    private function adminOrder(TelegramOutboundEffect $effect, string $messageId, CarbonInterface $at): void
    {
        $values = match ($effect->effect_type) {
            TelegramOutboundEffectType::AdminOrderPendingNotification->value => [
                'admin_pending_order_notified_at' => $at,
                'admin_pending_order_notification_chat_id' => $effect->telegram_chat_id,
                'admin_pending_order_notification_message_id' => (int) $messageId,
                'admin_pending_order_notification_deleted_at' => null,
            ],
            TelegramOutboundEffectType::AdminOrderPaidNotification->value => [
                'admin_paid_order_notified_at' => $at,
            ],
            TelegramOutboundEffectType::AdminOrderExpiredNotification->value => [
                'admin_expired_order_notified_at' => $at,
            ],
            default => [],
        };
        if ($values !== []) {
            Order::query()->whereKey($effect->source_id)->update($values + ['updated_at' => now()]);
        }
    }

    private function checkout(TelegramOutboundEffect $effect, string $messageId, CarbonInterface $at): void
    {
        Order::query()->whereKey($effect->source_id)->update([
            'manual_checkout_customer_notified_at' => $at,
            'manual_checkout_customer_notification_chat_id' => $effect->telegram_chat_id,
            'manual_checkout_customer_notification_message_id' => (int) $messageId,
            'manual_checkout_customer_notification_deleted_at' => null,
            'manual_checkout_customer_notification_deletion_started_at' => null,
            'manual_checkout_customer_notification_deletion_token' => null,
            'updated_at' => now(),
        ]);
    }
}
