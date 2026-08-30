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
 * projections for recovered unknown outcomes or completed receipt delivery.
 */
final class TelegramOutboundEffectRecovery
{
    public function __construct(
        private readonly TelegramOutboundEffectLedgerProtocol $ledger,
        private readonly AdminBroadcastDeliveryPipeline $broadcasts,
        private readonly SalesProofDeliveryPipeline $salesProof,
        private readonly ReceiptDeliveryPipeline $receipts,
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

                $this->salesProof->synchronizeRecoveredUnknown($effect);
            });

        return $count;
    }
}
