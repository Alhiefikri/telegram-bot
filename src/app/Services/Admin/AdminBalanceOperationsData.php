<?php

namespace App\Services\Admin;

use App\Enums\BalanceAlertState;
use App\Enums\BalanceRestoreGateState;
use App\Enums\CustomerUsdtFundingPauseScope;
use App\Models\BalanceOperationsAlertEpisode;
use App\Models\BalanceRestoreGate;
use App\Services\Operations\BalanceOperationMetrics;
use App\Services\UsdtFunding\CustomerUsdtFundingPolicyManager;
use Throwable;

/**
 * Read-only view model for the Admin "Kontrol Operasional" card (#558): pause
 * scopes, the restore gate, open alert episodes and (when available) the
 * bounded balance operation metrics summary. Presentation only: it never
 * mutates operational state and never loads customer, chain or provider
 * identity.
 */
final class AdminBalanceOperationsData
{
    private const MAX_ALERTS = 20;

    private const MAX_METRIC_KEYS = 40;

    private const MAX_METRIC_DEPTH = 2;

    public function __construct(
        private readonly CustomerUsdtFundingPolicyManager $fundingPolicies,
    ) {}

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'pauses' => $this->pauses(),
            'restoreGate' => $this->restoreGate(),
            'alerts' => $this->openAlerts(),
            'alertCounts' => $this->alertCounts(),
            'metrics' => $this->metricsSummary(),
        ];
    }

    /** @return list<array{scope: string, label: string, paused: bool, reason: string|null, paused_at: string|null}> */
    private function pauses(): array
    {
        $pauseStates = $this->fundingPolicies->pauses();

        $pauses = [];
        foreach (CustomerUsdtFundingPauseScope::cases() as $scope) {
            $pause = $pauseStates[$scope->value] ?? null;
            $pauses[] = [
                'scope' => $scope->value,
                'label' => $scope->label(),
                'paused' => $pause !== null,
                'reason' => $pause?->reason,
                'paused_at' => $pause?->pausedAt,
            ];
        }

        return $pauses;
    }

    /** @return array<string, mixed> */
    private function restoreGate(): array
    {
        $gate = BalanceRestoreGate::current();
        $open = $gate->state === BalanceRestoreGateState::Open;

        return [
            'state' => $gate->state->value,
            'state_label' => $open ? 'Terbuka' : 'Tertutup',
            'open' => $open,
            'opened_at' => $gate->opened_at?->toIso8601String(),
            'window_start' => $gate->window_start?->toIso8601String(),
            'window_end' => $gate->window_end?->toIso8601String(),
            'replay_completed_at' => $gate->replay_completed_at?->toIso8601String(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function openAlerts(): array
    {
        return BalanceOperationsAlertEpisode::query()
            ->where('state', BalanceAlertState::Open->value)
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 ELSE 1 END")
            ->orderByDesc('last_seen_at')
            ->limit(self::MAX_ALERTS)
            ->get()
            ->map(static fn (BalanceOperationsAlertEpisode $episode): array => [
                'alert_key' => $episode->alert_key,
                'severity' => $episode->severity->value,
                'severity_label' => $episode->severity->label(),
                'state' => $episode->state->value,
                'state_label' => $episode->state === BalanceAlertState::Open ? 'Terbuka' : 'Selesai',
                'summary' => $episode->summary,
                'occurrences' => (int) $episode->occurrences,
                'last_seen_at' => $episode->last_seen_at?->toIso8601String(),
                'first_seen_at' => $episode->first_seen_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @return array{open: int, critical: int, warning: int} */
    private function alertCounts(): array
    {
        $query = BalanceOperationsAlertEpisode::query()->where('state', BalanceAlertState::Open->value);

        return [
            'open' => (clone $query)->count(),
            'critical' => (clone $query)->where('severity', 'critical')->count(),
            'warning' => (clone $query)->where('severity', 'warning')->count(),
        ];
    }

    /**
     * Optional bounded metrics summary. The metrics service is being added in
     * a sibling change, so this returns null when it is absent or unusable and
     * the card simply omits the block.
     *
     * @return array<string, mixed>|null
     */
    private function metricsSummary(): ?array
    {
        if (! class_exists(BalanceOperationMetrics::class)) {
            return null;
        }

        try {
            $metrics = app(BalanceOperationMetrics::class);
            if (! is_object($metrics) || ! method_exists($metrics, 'report')) {
                return null;
            }

            $report = $metrics->report(60);
        } catch (Throwable) {
            return null;
        }

        return is_array($report) ? $this->boundMetrics($report) : null;
    }

    /**
     * @param  array<array-key, mixed>  $report
     * @return array<string, mixed>
     */
    private function boundMetrics(array $report, int $depth = 0): array
    {
        $bounded = [];
        foreach (array_slice($report, 0, self::MAX_METRIC_KEYS, true) as $key => $value) {
            $bounded[(string) $key] = $this->boundValue($value, $depth);
        }

        return $bounded;
    }

    private function boundValue(mixed $value, int $depth): mixed
    {
        if ($value === null || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return mb_substr($value, 0, 200);
        }

        if (is_array($value)) {
            return $depth < self::MAX_METRIC_DEPTH
                ? $this->boundMetrics($value, $depth + 1)
                : ['count' => count($value)];
        }

        return is_object($value) ? $value::class : null;
    }
}
