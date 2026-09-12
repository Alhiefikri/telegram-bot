<?php

namespace App\Services\StoreBalance\TopUp;

use App\Enums\StoreBalanceTopUpStatus;
use App\Models\StoreBalanceTopUp;
use App\Services\KlikQris\StoreBalanceTopUpEventLogger;
use Illuminate\Support\Facades\DB;

/**
 * Mark a top-up that needs operator review.
 *
 * Rails use this when authoritative provider evidence conflicts with the
 * persisted quote. No money moves here: the status becomes a terminal
 * provider-result state and the observation is recorded for audit/recovery.
 */
final class StoreBalanceTopUpReviewMarker
{
    public function __construct(private readonly StoreBalanceTopUpEventLogger $events) {}

    public function mark(
        StoreBalanceTopUp $topUp,
        string $reason,
        string $state = 'reconciliation_review',
    ): void {
        DB::transaction(function () use ($topUp, $reason, $state): void {
            $locked = StoreBalanceTopUp::query()
                ->whereKey($topUp->getKey())
                ->lockForUpdate()
                ->first();
            if ($locked === null) {
                return;
            }

            if (! in_array($locked->status, [
                StoreBalanceTopUpStatus::Paid,
                StoreBalanceTopUpStatus::Expired,
                StoreBalanceTopUpStatus::Compensated,
            ], true)) {
                $locked->forceFill([
                    'status' => StoreBalanceTopUpStatus::InvalidProviderResult,
                    'provider_create_state' => $state,
                    'provider_create_last_error' => $reason,
                ])->save();
            }

            $event = $this->events->logReconciliationReview($locked, $reason);
            if ($event->processed_at === null) {
                $event->forceFill(['processed_at' => now()])->save();
            }
        }, attempts: 5);

        $topUp->refresh();
    }
}
