<?php

namespace App\Services\MoneyReceipts;

use App\Enums\ExternalMoneyReceiptStatus;
use App\Models\ExternalMoneyReceipt;
use App\Models\ExternalMoneyReceiptBackfillState;
use App\Models\ExternalMoneyReceiptReservation;

/**
 * Completeness and collision report for the external money receipt cutover.
 *
 * Readiness is deliberately strict: every backfill source must have completed
 * a full scan with zero conflicts, and no blocking reservation may remain
 * open. USDT funding is reported as disabled because R2 cuts writers over
 * without activating any funding rail.
 */
final class ExternalMoneyReceiptCutoverReport
{
    /** @var array<string, string> */
    public const WRITERS = [
        'binance_checkout' => 'registry',
        'bsc_checkout' => 'registry',
        'qris_checkout' => 'registry',
        'store_balance_topup' => 'registry',
        'manual_approval' => 'registry',
        'bsc_review_fulfillment' => 'registry',
        'cancellation_recheck' => 'registry',
        'owner_compensation' => 'reservation',
    ];

    /** @var list<string> */
    private const REASONS = [
        ExternalMoneyReceiptReservation::REASON_REGISTRY_CONFLICT,
        ExternalMoneyReceiptReservation::REASON_REGISTRY_REVIEW,
        ExternalMoneyReceiptReservation::REASON_COMPENSATED_UNRESOLVED_MONEY,
        ExternalMoneyReceiptReservation::REASON_MISSING_RECEIVER_NAMESPACE,
        ExternalMoneyReceiptReservation::REASON_MISSING_CHAIN_IDENTITY,
        ExternalMoneyReceiptReservation::REASON_UNNORMALIZABLE_IDENTITY,
    ];

    public function __construct(private readonly ExternalMoneyReceiptReservations $reservations) {}

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $sources = [];
        foreach (ExternalMoneyReceiptBackfillService::SOURCES as $source) {
            $state = ExternalMoneyReceiptBackfillState::query()->where('source', $source)->first();
            $sources[$source] = [
                'status' => $state?->status ?? ExternalMoneyReceiptBackfillState::STATUS_PENDING,
                'complete' => $state?->completed_at !== null,
                'ready' => $state?->isReady() ?? false,
                'scanned' => (int) ($state?->scanned_count ?? 0),
                'claimed' => (int) ($state?->claimed_count ?? 0),
                'replayed' => (int) ($state?->replayed_count ?? 0),
                'reserved' => (int) ($state?->reserved_count ?? 0),
                'conflicts' => (int) ($state?->conflict_count ?? 0),
                'reviews' => (int) ($state?->review_count ?? 0),
                'skipped' => (int) ($state?->skipped_count ?? 0),
                'open_reservations' => $this->reservations->openCount($source),
            ];
        }

        $open = $this->reservations->open();
        $byReason = array_fill_keys(self::REASONS, 0);
        foreach ($open->groupBy('reason') as $reason => $group) {
            $byReason[(string) $reason] = $group->count();
        }

        $openCount = $open->count();
        $sourcesReady = collect($sources)->every(fn (array $source): bool => $source['ready'] === true);

        return [
            'ready' => $sourcesReady && $openCount === 0,
            'usdt_funding' => 'disabled',
            'registry' => [
                'receipts' => ExternalMoneyReceipt::query()->count(),
                'claimed' => ExternalMoneyReceipt::query()
                    ->where('status', ExternalMoneyReceiptStatus::Claimed->value)
                    ->count(),
                'review' => ExternalMoneyReceipt::query()
                    ->where('status', ExternalMoneyReceiptStatus::Review->value)
                    ->count(),
            ],
            'sources' => $sources,
            'reservations' => [
                'open' => $openCount,
                'collisions' => $byReason[ExternalMoneyReceiptReservation::REASON_REGISTRY_CONFLICT],
                'by_reason' => $byReason,
            ],
            'writers' => self::WRITERS,
            'compatibility' => 'New workers claim the registry before any money effect; legacy uniqueness constraints remain the mixed-version guard. Drain and restart old workers before activation. USDT funding stays disabled in R2.',
        ];
    }
}
