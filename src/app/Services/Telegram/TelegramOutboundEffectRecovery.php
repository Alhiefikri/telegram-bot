<?php

namespace App\Services\Telegram;

use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Models\TelegramBroadcastRecipient;
use App\Models\TelegramOutboundEffect;
use Carbon\CarbonInterface;

/**
 * Coordinates lease recovery with family projection repair and source handoff.
 * The ledger protocol performs the only delivery-state transition; family
 * adapters own projection repair and source-specific materialization rules.
 */
final class TelegramOutboundEffectRecovery
{
    public function __construct(
        private readonly TelegramOutboundEffectLedgerProtocol $ledger,
        private readonly AdminOrderNotificationDeliveryPipeline $adminOrderNotifications,
        private readonly AdminBroadcastDeliveryPipeline $broadcasts,
        private readonly SalesProofDeliveryPipeline $salesProof,
        private readonly ManualPaymentProofDeliveryPipeline $manualPaymentProof,
        private readonly ReceiptDeliveryPipeline $receipts,
        private readonly CheckoutInstructionDeliveryPipeline $checkoutInstructions,
        private readonly StockNotificationDeliveryPipeline $stockNotifications,
        private readonly ProductPriceChangeNotificationService $priceNotifications,
        private readonly StoreBalanceTopUpOutbound $storeBalanceTopUps,
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
     * Pin credential-bearing receipt messages whose delivery worker completed
     * before the Telegram pin side effect.
     */
    public function reconcileMissingCredentialPins(int $chunk = 100): int
    {
        return $this->receipts->reconcileMissingCredentialPins($chunk);
    }

    /**
     * Recover missing committed Store Balance top-up outbound effects.
     */
    public function recoverMissingStoreBalanceTopUpEffects(int $chunk = 100): int
    {
        return $this->storeBalanceTopUps->recoverMissing($chunk);
    }

    /**
     * Rebuild action-panel effects lost between receipt completion and queue
     * dispatch without touching legacy receipt groups.
     */
    public function reconcileMissingReceiptActionPanels(int $chunk = 100): int
    {
        return $this->receipts->reconcileMissingActionPanels($chunk, dispatch: false);
    }

    /**
     * Repair sent admin Order-notification projections without redispatching.
     */
    public function reconcileSentAdminOrderProjections(int $chunk = 100): int
    {
        return $this->adminOrderNotifications->reconcileSentProjections($chunk);
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

    /**
     * Repair Order projections for checkout effects already finalized as sent.
     */
    public function reconcileSentCheckoutInstructionProjections(int $chunk = 100): int
    {
        return $this->checkoutInstructions->reconcileSentProjections($chunk);
    }

    public function recoverExpiredLeases(?CarbonInterface $before = null, int $limit = 100): int
    {
        $at = $before ?? now();
        $count = $this->ledger->recoverExpiredLeases($at, $limit);
        $leaseEffects = TelegramOutboundEffect::query()
            ->where('state', TelegramOutboundEffectState::Unknown->value)
            ->where('last_error_code', 'lease_expired')
            ->where(function ($query) use ($at): void {
                $query->whereNull('unknown_at')->orWhere('unknown_at', '<=', $at);
            })
            ->whereIn('effect_type', [
                TelegramOutboundEffectType::AdminBroadcastRecipient->value,
                TelegramOutboundEffectType::SalesProofPost->value,
                TelegramOutboundEffectType::StockNotificationRecipient->value,
                TelegramOutboundEffectType::PriceChangeNotificationRecipient->value,
                TelegramOutboundEffectType::PriceChangeStoreChannel->value,
            ]);
        app(TelegramOutboundRecoveryPage::class)
            ->load('expired_lease_projections', $leaseEffects, $limit)
            ->each(function (TelegramOutboundEffect $effect): void {
                if (in_array($effect->effect_type, [
                    TelegramOutboundEffectType::PriceChangeNotificationRecipient->value,
                    TelegramOutboundEffectType::PriceChangeStoreChannel->value,
                ], true)) {
                    $this->priceNotifications->synchronizeRecoveredUnknown($effect);

                    return;
                }

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
