<?php

namespace App\Services\Telegram;

use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Models\TelegramBroadcastRecipient;
use App\Models\TelegramOutboundEffect;
use Carbon\CarbonInterface;

/**
 * Coordinates lease recovery with family read-projection repair. The ledger
 * protocol performs the only state transition; family adapters only refresh
 * projections for recovered unknown outcomes or completed delivery handoffs.
 */
final class TelegramOutboundEffectRecovery
{
    public function __construct(
        private readonly TelegramOutboundEffectLedgerProtocol $ledger,
        private readonly AdminBroadcastDeliveryPipeline $broadcasts,
        private readonly SalesProofDeliveryPipeline $salesProof,
        private readonly ManualPaymentProofDeliveryPipeline $manualPaymentProof,
        private readonly ReceiptDeliveryPipeline $receipts,
        private readonly StockNotificationDeliveryPipeline $stockNotifications,
    ) {}

    /**
     * Repair completed buyer receipt projections without dispatching receipts.
     *
     * The command's cleanup reconciliation scan owns the follow-up cleanup
     * handoff so existing cleanup eligibility and claim recovery stay intact.
     *
     * @return list<string> Order IDs whose receipt marker was repaired.
     */
    public function reconcileCompletedReceiptProjections(int $chunk = 100): array
    {
        return $this->receipts->reconcileCompletedProjections($chunk, dispatchCleanup: false);
    }

    /**
     * Repair sent manual payment-proof projections without dispatching effects.
     */
    public function reconcileSentManualPaymentProofProjections(int $chunk = 100): int
    {
        return $this->manualPaymentProof->reconcileSentProjections($chunk);
    }

    /**
     * Repair sent Sales Proof projections without dispatching their effects.
     */
    public function reconcileSentSalesProofProjections(int $chunk = 100): int
    {
        return $this->salesProof->reconcileSentProjections($chunk);
    }

    /**
     * Repair sent Admin Broadcast recipient and aggregate projections without
     * redispatching the already-sent effects.
     */
    public function reconcileSentAdminBroadcastProjections(int $chunk = 100): int
    {
        return $this->broadcasts->reconcileSentProjections($chunk);
    }

    public function recoverExpiredLeases(?CarbonInterface $before = null): int
    {
        $at = $before ?? now();
        $count = $this->ledger->recoverExpiredLeases($at);

        TelegramOutboundEffect::query()
            ->where('state', TelegramOutboundEffectState::Unknown->value)
            ->where('last_error_code', 'lease_expired')
            ->where(function ($query) use ($at): void {
                $query->whereNull('unknown_at')->orWhere('unknown_at', '<=', $at);
            })
            ->whereIn('effect_type', [
                TelegramOutboundEffectType::AdminBroadcastRecipient->value,
                TelegramOutboundEffectType::SalesProofPost->value,
                TelegramOutboundEffectType::StockNotificationRecipient->value,
            ])
            ->get()
            ->each(function (TelegramOutboundEffect $effect): void {
                if ($effect->effect_type === TelegramOutboundEffectType::AdminBroadcastRecipient->value) {
                    $broadcastId = TelegramBroadcastRecipient::query()
                        ->whereKey($effect->source_id)
                        ->value('broadcast_id');
                    if ($broadcastId !== null) {
                        $this->broadcasts->synchronizeRecoveredUnknown($effect);
                    }

                    return;
                }

                if ($effect->effect_type === TelegramOutboundEffectType::SalesProofPost->value) {
                    $this->salesProof->synchronizeRecoveredUnknown($effect);

                    return;
                }

                $this->stockNotifications->synchronizeRecoveredUnknown($effect);
            });

        return $count;
    }
}
