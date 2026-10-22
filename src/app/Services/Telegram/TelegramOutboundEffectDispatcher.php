<?php

namespace App\Services\Telegram;

use App\Enums\TelegramOutboundEffectType;
use App\Jobs\ForwardManualPaymentProof;
use App\Jobs\PublishBalanceTopUpChannelProof;
use App\Jobs\PublishSalesProofPost;
use App\Jobs\SendAdminOrderExpiredNotification;
use App\Jobs\SendAdminOrderPaidNotification;
use App\Jobs\SendAdminOrderPendingNotification;
use App\Jobs\SendBalanceAdjustmentNotice;
use App\Jobs\SendBalanceOperationsAlert;
use App\Jobs\SendBinanceCheckoutInstructions;
use App\Jobs\SendBscCheckoutInstructions;
use App\Jobs\SendKlikQrisCheckoutInstructions;
use App\Jobs\SendManualCheckoutNotifications;
use App\Jobs\SendPakasirCheckoutInstructions;
use App\Jobs\SendProductPriceChangeNotifications;
use App\Jobs\SendProductStockNotifications;
use App\Jobs\SendSakurupiahCheckoutInstructions;
use App\Jobs\SendStoreBalanceOwnerCompensationNotification;
use App\Jobs\SendStoreBalanceTopUpInstructions;
use App\Jobs\SendStoreBalanceTopUpOwnerAlert;
use App\Jobs\SendStoreBalanceTopUpPaymentProof;
use App\Jobs\SendStoreBalanceTopUpReceipt;
use App\Jobs\SendSupplierHealthNotification;
use App\Jobs\SendTelegramBroadcastBatch;
use App\Jobs\SendTelegramReceipt;
use App\Jobs\SendTelegramReceiptActionPanel;
use App\Jobs\SendTelegramReceiptTxt;
use App\Jobs\SendUsdtTopUpReceipt;
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
        ?string $enqueueStage = null,
        ?string $enqueueReason = null,
    ): void {
        if (! $force && ! $this->ledger->isDispatchable($effect)) {
            return;
        }

        $jobEnqueuedAt ??= now()->toISOString();
        $pending = match ($effect->effect_type) {
            TelegramOutboundEffectType::BalanceAdjustmentNotice->value => SendBalanceAdjustmentNotice::dispatch((string) $effect->id),
            TelegramOutboundEffectType::SupplierHealth->value => SendSupplierHealthNotification::dispatch((string) $effect->id),
            TelegramOutboundEffectType::AdminOrderReviewNotification->value => SendAdminOrderPaidNotification::dispatch(
                (string) $effect->source_id, $jobEnqueuedAt, (string) $effect->id,
            ),
            TelegramOutboundEffectType::BalanceOperationsAlert->value => SendBalanceOperationsAlert::dispatch((string) $effect->id),
            TelegramOutboundEffectType::UsdtTopUpReceipt->value,
            TelegramOutboundEffectType::UsdtRefundReceipt->value => SendUsdtTopUpReceipt::dispatch((string) $effect->id),
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
            TelegramOutboundEffectType::SakurupiahCheckoutInstructions->value => SendSakurupiahCheckoutInstructions::dispatch(
                (string) $effect->source_id,
                $jobEnqueuedAt,
                (string) $effect->getKey(),
            ),
            TelegramOutboundEffectType::PakasirCheckoutInstructions->value => SendPakasirCheckoutInstructions::dispatch(
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
            TelegramOutboundEffectType::BalanceTopUpInstructions->value => SendStoreBalanceTopUpInstructions::dispatch(
                (string) $effect->source_id,
                $jobEnqueuedAt,
                (string) $effect->getKey(),
            ),
            TelegramOutboundEffectType::BalanceTopUpReceipt->value => SendStoreBalanceTopUpReceipt::dispatch(
                (string) $effect->source_id,
                $jobEnqueuedAt,
                (string) $effect->getKey(),
            ),
            TelegramOutboundEffectType::BalanceTopUpPaymentProof->value => SendStoreBalanceTopUpPaymentProof::dispatch(
                (string) $effect->source_id,
                $jobEnqueuedAt,
                (string) $effect->getKey(),
            ),
            TelegramOutboundEffectType::BalanceTopUpOwnerAlert->value,
            TelegramOutboundEffectType::BalanceTopUpPaidNotification->value => SendStoreBalanceTopUpOwnerAlert::dispatch(
                (string) $effect->source_id,
                $jobEnqueuedAt,
                (string) $effect->getKey(),
            ),
            TelegramOutboundEffectType::BalanceTopUpChannelProof->value => PublishBalanceTopUpChannelProof::dispatch(
                (string) $effect->source_id,
                $jobEnqueuedAt,
                (string) $effect->getKey(),
            ),
            TelegramOutboundEffectType::OwnerCompensationNotification->value => SendStoreBalanceOwnerCompensationNotification::dispatch(
                (string) $effect->source_id,
                $jobEnqueuedAt,
                (string) $effect->getKey(),
            ),
            TelegramOutboundEffectType::ReceiptCredentials->value => SendTelegramReceipt::dispatch(
                (string) $effect->source_id,
                $force,
                $jobEnqueuedAt,
                (string) $effect->getKey(),
                $enqueueStage ?? SendTelegramReceipt::ENQUEUE_FIRST,
                $enqueueReason ?? SendTelegramReceipt::REASON_EFFECT_DISPATCH,
            ),
            TelegramOutboundEffectType::ReceiptTxtDownload->value => SendTelegramReceiptTxt::dispatch(
                (string) $effect->source_id,
                $jobEnqueuedAt,
                (string) $effect->getKey(),
            ),
            TelegramOutboundEffectType::ReceiptOrderActions->value => SendTelegramReceiptActionPanel::dispatch(
                (string) $effect->source_id,
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

        $pending?->onQueue($this->queueFor($effect))->afterCommit();
    }

    /**
     * Receipt families are always routed by type. A persisted queue value can
     * predate the dedicated receipt worker, so delivery never depends on the
     * stored metadata being normalized first; the cutover command still
     * rewrites that metadata for operators and review screens.
     */
    public function queueFor(TelegramOutboundEffect $effect): string
    {
        if (in_array($effect->effect_type, TelegramOutboundEffectService::RECEIPT_EFFECT_TYPES, true)) {
            return TelegramOutboundEffectService::RECEIPT_QUEUE;
        }

        return (string) ($effect->queue ?: TelegramOutboundEffect::CHECKOUT_QUEUE);
    }

    public function dispatchExplicitResend(TelegramOutboundEffect $effect): void
    {
        $this->dispatch(
            $effect,
            force: true,
            enqueueStage: SendTelegramReceipt::ENQUEUE_RETRY,
            enqueueReason: SendTelegramReceipt::REASON_EXPLICIT_RESEND,
        );
    }
}
