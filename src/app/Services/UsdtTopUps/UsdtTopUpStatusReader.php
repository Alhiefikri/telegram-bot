<?php

namespace App\Services\UsdtTopUps;

use App\Models\CustomerUsdtTopUp;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Derives honest Customer-facing status for confirmed USDT funding targets from
 * durable work/resolution rows and the immutable ledger. A resolution is only
 * reported as settled when its linked ledger entry row really exists, and a
 * bare settlement state without a resolution is never presented as credited.
 * Display status never credits anything by itself.
 */
final class UsdtTopUpStatusReader
{
    /** @var list<string> */
    private const PREPARATION_OPEN_STATES = ['pending', 'creating', 'creation_unknown'];

    public function for(CustomerUsdtTopUp $target): UsdtTopUpStatus
    {
        return $this->forMany(new Collection([$target]))[(string) $target->id];
    }

    /**
     * @param  Collection<int, CustomerUsdtTopUp>  $targets
     * @return array<string, UsdtTopUpStatus> keyed by top-up id
     */
    public function forMany(Collection $targets): array
    {
        $ids = $targets->pluck('id')->map(static fn ($id): string => (string) $id)->values()->all();
        if ($ids === []) {
            return [];
        }

        $resolutions = DB::table('customer_usdt_top_up_resolutions')
            ->whereIn('top_up_id', $ids)
            ->get(['top_up_id', 'kind', 'entry_id'])
            ->keyBy(static fn (object $row): string => (string) $row->top_up_id);
        $preparation = DB::table('customer_usdt_top_up_work')
            ->whereIn('top_up_id', $ids)
            ->pluck('state', 'top_up_id');
        $settlement = DB::table('customer_usdt_top_up_settlement_work')
            ->whereIn('top_up_id', $ids)
            ->pluck('state', 'top_up_id');

        $settledEntryIds = $resolutions
            ->filter(static fn (object $row): bool => (string) $row->kind === UsdtTopUpStatus::STATE_SETTLED && $row->entry_id !== null)
            ->map(static fn (object $row): int => (int) $row->entry_id)
            ->values()
            ->all();
        $existingEntryIds = $settledEntryIds === []
            ? []
            : DB::table('customer_usdt_balance_entries')->whereIn('id', $settledEntryIds)->pluck('id')
                ->map(static fn ($id): int => (int) $id)->all();

        $statuses = [];
        foreach ($targets as $target) {
            $id = (string) $target->id;
            $resolution = $resolutions->get($id);
            $statuses[$id] = UsdtTopUpStatus::for($target, $this->state(
                resolution: $resolution,
                existingEntryIds: $existingEntryIds,
                preparation: $preparation[$id] ?? null,
                settlement: $settlement[$id] ?? null,
                target: $target,
            ));
        }

        return $statuses;
    }

    /**
     * @param  list<int>  $existingEntryIds
     */
    private function state(
        ?object $resolution,
        array $existingEntryIds,
        ?string $preparation,
        ?string $settlement,
        CustomerUsdtTopUp $target,
    ): string {
        if ($resolution !== null) {
            $kind = (string) $resolution->kind;
            if ($kind === UsdtTopUpStatus::STATE_SETTLED) {
                // A settled resolution without its ledger entry is never shown
                // as a credited balance.
                return $resolution->entry_id !== null && in_array((int) $resolution->entry_id, $existingEntryIds, true)
                    ? UsdtTopUpStatus::STATE_SETTLED
                    : UsdtTopUpStatus::STATE_REVIEW;
            }

            return in_array($kind, UsdtTopUpStatus::TERMINAL, true)
                ? $kind
                : UsdtTopUpStatus::STATE_REVIEW;
        }

        if ($settlement !== null && in_array($settlement, UsdtTopUpStatus::TERMINAL, true)) {
            // A settlement state without its immutable resolution row is never
            // shown as credited funds.
            return $settlement === UsdtTopUpStatus::STATE_SETTLED
                ? UsdtTopUpStatus::STATE_REVIEW
                : $settlement;
        }

        if ($preparation === null || in_array($preparation, self::PREPARATION_OPEN_STATES, true)) {
            return UsdtTopUpStatus::STATE_PREPARING;
        }

        if ($settlement === UsdtTopUpStatus::STATE_REVIEW || $preparation === UsdtTopUpStatus::STATE_REVIEW) {
            return UsdtTopUpStatus::STATE_REVIEW;
        }

        if ($preparation === UsdtTopUpStatus::STATE_EXPIRED_UNRESOLVED
            || $settlement === UsdtTopUpStatus::STATE_EXPIRED_UNRESOLVED
            || $target->expires_at === null
            || $target->expires_at->isPast()) {
            return UsdtTopUpStatus::STATE_EXPIRED_UNRESOLVED;
        }

        return UsdtTopUpStatus::STATE_AWAITING_PAYMENT;
    }
}
