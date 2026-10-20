<?php

namespace App\Services\Operations;

use App\Enums\BalanceOperationMetric;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only, side-effect-free operations report for the balance subsystem
 * (#558). Every value is either a bounded counter, an exact integer amount
 * string, an age in seconds or a closed enum label: the report never exposes a
 * customer id, telegram id, chain address/hash or provider reference, and it
 * never writes money or metric state.
 */
final class BalanceOperationMetrics
{
    /** Closed effect types whose pending delivery is an operational backlog. */
    private const OUTBOUND_BACKLOG_EFFECTS = [
        'usdt.top_up_receipt',
        'usdt.refund_receipt',
        'balance.top_up_receipt',
        'admin.balance_top_up_owner_alert',
        'admin.balance_top_up_paid_notification',
        'balance.owner_compensation_notification',
    ];

    /**
     * @return array{
     *     generated_at: string,
     *     window_minutes: int,
     *     counters: array<string, array<string, int>>,
     *     backlog: array<string, array<string, int>>,
     *     drift: array<string, mixed>,
     *     coverage: array<string, mixed>,
     *     conflict: array<string, int>,
     *     controls: array<string, mixed>
     * }
     */
    public function report(int $windowMinutes = 60): array
    {
        $windowMinutes = max(1, $windowMinutes);
        $latest = $this->latestFullReconciliationRun();

        return [
            'generated_at' => now()->toIso8601String(),
            'window_minutes' => $windowMinutes,
            'counters' => $this->counters($windowMinutes),
            'backlog' => [
                'top_up_preparation' => $this->backlogFacet(
                    'customer_usdt_top_up_work',
                    fn (Builder $query) => $query->whereIn('state', ['pending', 'creating', 'creation_unknown']),
                    'next_attempt_at',
                    'pending',
                ),
                'top_up_settlement' => $this->backlogFacet(
                    'customer_usdt_top_up_settlement_work',
                    fn (Builder $query) => $query->whereNotIn('state', ['settled', 'externally_refunded', 'resolved_by_compensation', 'rejected']),
                    'next_check_at',
                    'open',
                ),
                'funding_reservations' => $this->backlogFacet(
                    'customer_usdt_funding_reservations',
                    fn (Builder $query) => $query->whereNull('resolved_at'),
                    'expires_at',
                    'unresolved',
                ),
                'balance_outbound' => $this->backlogFacet(
                    'telegram_outbound_effects',
                    fn (Builder $query) => $query
                        ->whereIn('state', ['pending', 'failed'])
                        ->whereIn('effect_type', self::OUTBOUND_BACKLOG_EFFECTS),
                    'created_at',
                    'pending',
                ),
                'top_up_review' => $this->backlogFacet(
                    'usdt_top_up_review_requests',
                    fn (Builder $query) => $query->where('expires_at', '>', now()),
                    'created_at',
                    'open',
                ),
            ],
            'drift' => [
                'frozen_accounts' => (int) DB::table('customer_usdt_balance_accounts')
                    ->whereNotNull('frozen_reason')
                    ->count(),
                'latest' => $latest === null ? null : [
                    'verdict' => (string) $latest->verdict,
                    'checked_at' => $this->iso($latest->checked_at),
                    'account_findings' => (int) $latest->account_finding_count,
                    'receipt_findings' => (int) $latest->receipt_finding_count,
                    'drift_frozen' => (int) $latest->drift_frozen_count,
                    'custody_state' => $latest->custody_state === null ? null : (string) $latest->custody_state,
                    'unresolved_deposit_micro' => (string) $latest->unresolved_deposit_micro,
                ],
            ],
            'coverage' => [
                'ledger_liability_micro' => $latest === null ? '0' : (string) $latest->liability_micro,
                'unresolved_deposit_micro' => $latest === null ? '0' : (string) $latest->unresolved_deposit_micro,
                'custody_state' => $latest?->custody_state === null ? null : (string) $latest?->custody_state,
                'custody_assets_micro' => $latest?->custody_assets_micro === null ? null : (string) $latest?->custody_assets_micro,
                'verdict' => $latest === null ? null : (string) $latest->verdict,
            ],
            'conflict' => [
                'review_receipts' => (int) DB::table('external_money_receipts')->where('status', 'review')->count(),
                'review_parent_claims' => (int) DB::table('external_money_receipt_parent_claims')->where('status', 'review')->count(),
                'open_registry_conflict_reservations' => (int) DB::table('external_money_receipt_reservations')
                    ->whereNull('resolved_at')
                    ->where('reason', 'registry_conflict')
                    ->count(),
            ],
            'controls' => [
                'pauses' => $this->pauses(),
                'restore_gate' => $this->restoreGate(),
            ],
        ];
    }

    /** @return array<string, array<string, int>> */
    private function counters(int $windowMinutes): array
    {
        $counters = [];
        foreach (BalanceOperationMetric::cases() as $metric) {
            foreach ($metric->outcomes() as $outcome) {
                $counters[$metric->value][$outcome] = 0;
            }
        }

        $rows = DB::table('balance_operation_events')
            ->where('occurred_at', '>=', now()->subMinutes($windowMinutes))
            ->select('metric', 'outcome')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('metric', 'outcome')
            ->get();

        foreach ($rows as $row) {
            $metric = (string) $row->metric;
            $outcome = (string) $row->outcome;
            if (array_key_exists($outcome, $counters[$metric] ?? [])) {
                $counters[$metric][$outcome] = (int) $row->aggregate;
            }
        }

        return $counters;
    }

    /**
     * @return array<string, int>
     */
    private function backlogFacet(string $table, Closure $constrain, string $timestampColumn, string $countLabel): array
    {
        $query = DB::table($table);
        $constrain($query);

        $row = $query
            ->selectRaw("count(*) as aggregate, min({$timestampColumn}) as oldest")
            ->first();

        $count = (int) ($row->aggregate ?? 0);
        $oldest = $row->oldest ?? null;
        $age = 0;
        if ($count > 0 && $oldest !== null) {
            $age = max(0, now()->getTimestamp() - CarbonImmutable::parse((string) $oldest)->getTimestamp());
        }

        return [
            $countLabel => $count,
            'oldest_age_seconds' => $age,
        ];
    }

    private function latestFullReconciliationRun(): ?object
    {
        return DB::table('usdt_balance_reconciliation_runs')
            ->where('scope', 'full')
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->first();
    }

    /** @return list<string> */
    private function pauses(): array
    {
        return DB::table('customer_usdt_funding_pauses')
            ->orderBy('scope')
            ->pluck('scope')
            ->map(static fn ($scope): string => (string) $scope)
            ->values()
            ->all();
    }

    /** @return array{state: string, opened_at: ?string, window_start: ?string, window_end: ?string, replay_completed_at: ?string} */
    private function restoreGate(): array
    {
        $gate = DB::table('balance_restore_gates')->where('id', 1)->first();

        return [
            'state' => $gate === null ? 'cleared' : (string) $gate->state,
            'opened_at' => $this->iso($gate->opened_at ?? null),
            'window_start' => $this->iso($gate->window_start ?? null),
            'window_end' => $this->iso($gate->window_end ?? null),
            'replay_completed_at' => $this->iso($gate->replay_completed_at ?? null),
        ];
    }

    private function iso(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->toIso8601String();
    }
}
