<?php

namespace App\Services\Telegram;

use App\Enums\TelegramOutboundEffectType;
use App\Jobs\ForwardManualPaymentProof;
use App\Jobs\PublishSalesProofPost;
use App\Jobs\SendAdminOrderExpiredNotification;
use App\Jobs\SendAdminOrderPaidNotification;
use App\Jobs\SendAdminOrderPendingNotification;
use App\Jobs\SendBinanceCheckoutInstructions;
use App\Jobs\SendBscCheckoutInstructions;
use App\Jobs\SendKlikQrisCheckoutInstructions;
use App\Jobs\SendManualCheckoutNotifications;
use App\Jobs\SendProductPriceChangeNotifications;
use App\Jobs\SendProductStockNotifications;
use App\Jobs\SendTelegramBroadcastBatch;
use App\Jobs\SendTelegramReceipt;
use App\Models\ProductPriceChangeNotificationRecipient;
use App\Models\StockNotificationRecipient;
use App\Models\TelegramBroadcastRecipient;
use App\Models\TelegramOutboundEffect;

/**
 * Queue dispatch is transport plumbing, not ledger state management. Keeping
 * the job mapping here lets recovery and lifecycle adapters dispatch through a
 * small explicit boundary without making the ledger protocol know queue jobs.
 */
final class TelegramOutboundEffectDispatcher
{
    public function __construct(
        private readonly TelegramOutboundEffectLedgerProtocol $ledger,
    ) {}

    public function dispatch(
        TelegramOutboundEffect $effect,
        ?string $jobEnqueuedAt = null,
        bool $force = false,
    ): void {
        if (! $force && ! $this->ledger->isDispatchable($effect)) {
            return;
        }

        $jobEnqueuedAt ??= now()->toISOString();
        $pending = match ($effect->effect_type) {
            TelegramOutboundEffectType::ManualCheckoutInstructions->value => SendManualCheckoutNotifications::dispatch(
                (string) $effect->source_id,
                $jobEnqueuedAt,
                (string) $effect->getKey(),
            ),
            TelegramOutboundEffectType::KlikQrisCheckoutInstructions->value => SendKlikQrisCheckoutInstructions::dispatch(
                (string) $effect->source_id,
                $jobEnqueuedAt,
                (string) $effect->getKey(),
            ),
            TelegramOutboundEffectType::BinanceCheckoutInstructions->value => SendBinanceCheckoutInstructions::dispatch(
                (string) $effect->source_id,
                $jobEnqueuedAt,
                (string) $effect->getKey(),
            ),
            TelegramOutboundEffectType::BscCheckoutInstructions->value => SendBscCheckoutInstructions::dispatch(
                (string) $effect->source_id,
                $jobEnqueuedAt,
                (string) $effect->getKey(),
            ),
            TelegramOutboundEffectType::ReceiptCredentials->value => SendTelegramReceipt::dispatch(
                (string) $effect->source_id,
                $force,
                $jobEnqueuedAt,
                (string) $effect->getKey(),
            ),
            TelegramOutboundEffectType::AdminOrderPendingNotification->value => SendAdminOrderPendingNotification::dispatch(
                (string) $effect->source_id,
                $jobEnqueuedAt,
                (string) $effect->getKey(),
            ),
            TelegramOutboundEffectType::AdminOrderPaidNotification->value => SendAdminOrderPaidNotification::dispatch(
                (string) $effect->source_id,
                $jobEnqueuedAt,
                (string) $effect->getKey(),
            ),
            TelegramOutboundEffectType::AdminOrderExpiredNotification->value => SendAdminOrderExpiredNotification::dispatch(
                (string) $effect->source_id,
                $jobEnqueuedAt,
                (string) $effect->getKey(),
            ),
            TelegramOutboundEffectType::AdminBroadcastRecipient->value => SendTelegramBroadcastBatch::dispatch(
                (int) TelegramBroadcastRecipient::query()
                    ->whereKey($effect->source_id)
                    ->value('broadcast_id'),
                $jobEnqueuedAt,
            ),
            TelegramOutboundEffectType::StockNotificationRecipient->value => SendProductStockNotifications::dispatch(
                (int) StockNotificationRecipient::query()
                    ->whereKey($effect->source_id)
                    ->with('event:id,inventory_movement_id')
                    ->first()?->event?->inventory_movement_id,
                $jobEnqueuedAt,
            ),
            TelegramOutboundEffectType::PriceChangeNotificationRecipient->value => SendProductPriceChangeNotifications::dispatch(
                (int) ProductPriceChangeNotificationRecipient::query()
                    ->whereKey($effect->source_id)
                    ->value('event_id'),
                $jobEnqueuedAt,
            ),
            TelegramOutboundEffectType::PriceChangeStoreChannel->value => SendProductPriceChangeNotifications::dispatch(
                (int) $effect->source_id,
                $jobEnqueuedAt,
            ),
            TelegramOutboundEffectType::ManualPaymentProof->value => ForwardManualPaymentProof::dispatch(
                (string) $effect->source_id,
                $jobEnqueuedAt,
                (string) $effect->getKey(),
            ),
            TelegramOutboundEffectType::SalesProofPost->value => PublishSalesProofPost::dispatch(
                (string) $effect->source_id,
                false,
                $jobEnqueuedAt,
                (string) $effect->getKey(),
            ),
            default => null,
        };

        $pending?->onQueue((string) ($effect->queue ?: TelegramOutboundEffect::CHECKOUT_QUEUE))->afterCommit();
    }

    public function dispatchExplicitResend(TelegramOutboundEffect $effect): void
    {
        $this->dispatch($effect, force: true);
    }
}
