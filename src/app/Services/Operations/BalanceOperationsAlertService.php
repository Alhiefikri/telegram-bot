<?php

namespace App\Services\Operations;

use App\Enums\BalanceAlertSeverity;
use App\Enums\BalanceAlertState;
use App\Enums\BalanceRestoreGateState;
use App\Enums\CustomerUsdtFundingPauseScope;
use App\Enums\ExternalMoneyReceiptStatus;
use App\Enums\TelegramOutboundEffectType;
use App\Enums\UsdtReconciliationVerdict;
use App\Models\BalanceOperationsAlertEpisode;
use App\Models\BalanceRestoreGate;
use App\Models\CustomerUsdtBalanceAccount;
use App\Models\ExternalMoneyReceipt;
use App\Models\ExternalMoneyReceiptReservation;
use App\Models\TelegramOutboundEffect;
use App\Models\UsdtBalanceReconciliationRun;
use App\Services\Telegram\TelegramAdminAccess;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectService;
use Brick\Math\BigInteger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Durable balance operations alert evaluation (#558).
 *
 * Reads sanitized, append-only operational evidence (reconciliation runs,
 * reorg incidents, receipt registry, work backlogs, restore gate, pauses) and
 * reconciles the closed alert-key episode set. It owns episode state only:
 * delivery is delegated to the Telegram Outbound Effect ledger, which dedupes
 * by episode + fingerprint, and a failed delivery can never change money or
 * the underlying condition. No query here derives a money value beyond the
 * stored evidence columns.
 */
final class BalanceOperationsAlertService
{
    public const ALERT_RECONCILIATION_DRIFT = 'reconciliation_drift';

    public const ALERT_RECONCILIATION_RECEIPT_COLLISION = 'reconciliation_receipt_collision';

    public const ALERT_TREASURY_COVERAGE = 'treasury_coverage';

    public const ALERT_REORG_LOSS_UNRESOLVED = 'reorg_loss_unresolved';

    public const ALERT_RECEIPT_REGISTRY_CONFLICT = 'receipt_registry_conflict';

    public const ALERT_TOP_UP_WORK_BACKLOG = 'top_up_work_backlog';

    public const ALERT_SETTLEMENT_BACKLOG = 'settlement_backlog';

    public const ALERT_OUTBOUND_BACKLOG = 'outbound_backlog';

    public const ALERT_RESTORE_GATE_OPEN = 'restore_gate_open';

    public const ALERT_DELIVERY_PAUSED = 'delivery_paused';

    public const ALERT_ACCOUNT_FROZEN = 'account_frozen';

    public const ALERT_SCHEDULER_RUNTIME_FAILURE = 'scheduler_runtime_failure';

    public const ALERT_TELEGRAM_RECOVERY_RUNTIME_FAILURE = 'telegram_recovery_runtime_failure';

    /** @var list<string> */
    public const ALERT_KEYS = [
        self::ALERT_RECONCILIATION_DRIFT,
        self::ALERT_RECONCILIATION_RECEIPT_COLLISION,
        self::ALERT_TREASURY_COVERAGE,
        self::ALERT_REORG_LOSS_UNRESOLVED,
        self::ALERT_RECEIPT_REGISTRY_CONFLICT,
        self::ALERT_TOP_UP_WORK_BACKLOG,
        self::ALERT_SETTLEMENT_BACKLOG,
        self::ALERT_OUTBOUND_BACKLOG,
        self::ALERT_RESTORE_GATE_OPEN,
        self::ALERT_DELIVERY_PAUSED,
        self::ALERT_ACCOUNT_FROZEN,
        self::ALERT_SCHEDULER_RUNTIME_FAILURE,
        self::ALERT_TELEGRAM_RECOVERY_RUNTIME_FAILURE,
    ];

    private const TOP_UP_DUE_STATES = ['pending', 'creating', 'creation_unknown'];

    private const SETTLEMENT_TERMINAL_STATES = [
        'settled',
        'externally_refunded',
        'resolved_by_compensation',
        'rejected',
    ];

    private const BACKLOG_WARNING_SECONDS = 600;

    private const BACKLOG_CRITICAL_SECONDS = 1800;

    private const OUTBOUND_WARNING_SECONDS = 300;

    private const OUTBOUND_CRITICAL_SECONDS = 900;

    public function __construct(
        private readonly TelegramOutboundEffectService $effects,
        private readonly TelegramAdminAccess $adminAccess,
        private readonly TelegramOutboundEffectDispatcher $dispatcher,
    ) {}

    public function evaluate(): BalanceOperationsAlertEvaluation
    {
        return $this->reconcileSignals($this->signals());
    }

    public function reconcileSchedulerRuntime(
        string $component,
        bool $healthy,
        ?string $reason = null,
    ): BalanceOperationsAlertEvaluation {
        $key = match ($component) {
            'scheduler' => self::ALERT_SCHEDULER_RUNTIME_FAILURE,
            'outbound-recovery' => self::ALERT_TELEGRAM_RECOVERY_RUNTIME_FAILURE,
            default => throw new \InvalidArgumentException('Unknown runtime alert component.'),
        };
        $reasonLabels = [
            'schedule_exit' => 'schedule:run keluar dengan status non-zero',
            'cycle_interrupted' => 'proses scheduler berhenti di tengah satu siklus',
            'oom_kill' => 'cgroup mencatat proses scheduler terkena OOM-kill',
            'recovery_exit' => 'proses recovery keluar dengan status non-zero',
            'recovery_timeout' => 'proses recovery melewati batas waktu eksekusi',
            'recovery_interrupted' => 'proses recovery berhenti saat sweep berjalan',
        ];
        $componentReasons = $component === 'scheduler'
            ? ['schedule_exit', 'cycle_interrupted', 'oom_kill']
            : ['recovery_exit', 'recovery_timeout', 'recovery_interrupted'];

        if (! $healthy && (! in_array($reason, $componentReasons, true) || ! isset($reasonLabels[$reason]))) {
            throw new \InvalidArgumentException('Unknown runtime alert reason.');
        }

        $summary = $healthy
            ? 'Kondisi runtime pulih.'
            : sprintf(
                '%s gagal: %s.',
                $component === 'scheduler' ? 'Scheduler' : 'Recovery outbound Telegram',
                $reasonLabels[$reason],
            );

        return $this->reconcileSignals([[
            'key' => $key,
            'active' => ! $healthy,
            'severity' => BalanceAlertSeverity::Critical,
            'summary' => $summary,
            'evidence' => [
                'component' => $component,
            ],
        ]]);
    }

    /**
     * @param  list<array{key: string, active: bool, severity: BalanceAlertSeverity, summary: string, evidence: array<string, int|string>}>  $signals
     */
    private function reconcileSignals(array $signals): BalanceOperationsAlertEvaluation
    {
        $totals = ['opened' => 0, 'updated' => 0, 'resolved' => 0, 'notified' => 0, 'delivery_pending' => 0];
        $effects = [];

        DB::transaction(function () use ($signals, &$totals, &$effects): void {
            foreach ($signals as $signal) {
                [$counts, $signalEffects] = $this->reconcileSignal($signal);
                foreach ($counts as $key => $value) {
                    $totals[$key] += $value;
                }
                foreach ($signalEffects as $effect) {
                    $effects[] = $effect;
                }
            }
        });

        foreach ($effects as $effect) {
            $this->dispatcher->dispatch($effect);
        }

        return new BalanceOperationsAlertEvaluation(
            opened: $totals['opened'],
            updated: $totals['updated'],
            resolved: $totals['resolved'],
            notified: $totals['notified'],
            deliveryPending: $totals['delivery_pending'],
        );
    }

    /**
     * @param  array{key: string, active: bool, severity: BalanceAlertSeverity, summary: string, evidence: array<string, int|string>}  $signal
     * @return array{0: array{opened: int, updated: int, resolved: int, notified: int, delivery_pending: int}, 1: list<TelegramOutboundEffect>}
     */
    private function reconcileSignal(array $signal): array
    {
        $counts = ['opened' => 0, 'updated' => 0, 'resolved' => 0, 'notified' => 0, 'delivery_pending' => 0];
        $effects = [];
        $now = now();

        $episode = BalanceOperationsAlertEpisode::query()->where('alert_key', $signal['key'])->first();

        if ($signal['active']) {
            $fingerprint = $this->fingerprint($signal['key'], $signal['severity'], $signal['evidence']);

            $episode = $this->firstOrCreateEpisode($signal, $fingerprint, $now);

            if ($episode->wasRecentlyCreated) {
                $counts['opened']++;
                $shouldNotify = true;
            } else {
                $wasResolved = $episode->state === BalanceAlertState::Resolved;
                $fingerprintChanged = (string) $episode->fingerprint !== $fingerprint;

                if ($wasResolved) {
                    $episode->forceFill([
                        'severity' => $signal['severity']->value,
                        'state' => BalanceAlertState::Open->value,
                        'summary' => $signal['summary'],
                        'fingerprint' => $fingerprint,
                        'evidence' => $signal['evidence'],
                        'occurrences' => 1,
                        'open_count' => ((int) $episode->open_count) + 1,
                        'first_seen_at' => $now,
                        'last_seen_at' => $now,
                        'last_notified_at' => null,
                        'resolved_at' => null,
                    ])->save();
                    $counts['opened']++;
                    $shouldNotify = true;
                } else {
                    $episode->forceFill([
                        'severity' => $signal['severity']->value,
                        'summary' => $signal['summary'],
                        'fingerprint' => $fingerprint,
                        'evidence' => $signal['evidence'],
                        'occurrences' => ((int) $episode->occurrences) + 1,
                        'last_seen_at' => $now,
                    ])->save();
                    $counts['updated']++;
                    $shouldNotify = $episode->last_notified_at === null || $fingerprintChanged;
                }
            }

            if ($shouldNotify) {
                [$notified, $effect] = $this->materialize(
                    $episode,
                    $this->openMessage($signal['severity'], $signal['summary']),
                    false,
                );
                $counts['notified'] += $notified;
                if ($effect === null) {
                    $counts['delivery_pending']++;
                } else {
                    $effects[] = $effect;
                }
            }
        } elseif ($episode !== null && $episode->state === BalanceAlertState::Open) {
            $episode->forceFill([
                'state' => BalanceAlertState::Resolved->value,
                'resolved_at' => $now,
                'last_seen_at' => $now,
            ])->save();
            $counts['resolved']++;

            [$notified, $effect] = $this->materialize($episode, $this->resolvedMessage($episode), true);
            $counts['notified'] += $notified;
            if ($effect === null) {
                $counts['delivery_pending']++;
            } else {
                $effects[] = $effect;
            }
        }

        return [$counts, $effects];
    }

    /**
     * @return array{0: int, 1: TelegramOutboundEffect|null}
     */
    /**
     * Create the episode row, tolerating a concurrent evaluator that inserted
     * the same bounded alert key first: the retry reads the winner row, so two
     * schedulers can never produce two episodes for one key.
     *
     * @param  array<string, mixed>  $signal
     */
    private function firstOrCreateEpisode(array $signal, string $fingerprint, Carbon $now): BalanceOperationsAlertEpisode
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                return DB::transaction(fn (): BalanceOperationsAlertEpisode => BalanceOperationsAlertEpisode::query()->firstOrCreate(
                    ['alert_key' => $signal['key']],
                    [
                        'severity' => $signal['severity']->value,
                        'state' => BalanceAlertState::Open->value,
                        'summary' => $signal['summary'],
                        'fingerprint' => $fingerprint,
                        'evidence' => $signal['evidence'],
                        'occurrences' => 1,
                        'open_count' => 1,
                        'first_seen_at' => $now,
                        'last_seen_at' => $now,
                        'last_notified_at' => null,
                        'resolved_at' => null,
                    ],
                ), 1);
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt > 0) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('Balance operations alert episode could not be created.');
    }

    private function materialize(BalanceOperationsAlertEpisode $episode, string $message, bool $resolved): array
    {
        $chatId = $this->adminAccess->adminChatId();
        if ($chatId === null) {
            return [0, null];
        }

        // The effect key is deterministic per episode + fingerprint, so a
        // concurrent evaluation that wins the insert is simply re-read on the
        // second attempt instead of failing the whole evaluation.
        $attempts = 0;
        while (true) {
            $attempts++;
            try {
                $effect = DB::transaction(
                    fn () => $this->effects->recordBalanceOperationsAlert($episode, $chatId, $message, $resolved),
                    1,
                );
                break;
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempts >= 2) {
                    throw $exception;
                }
            }
        }

        if ($effect->wasRecentlyCreated) {
            $episode->forceFill(['last_notified_at' => now()])->save();

            return [1, $effect];
        }

        return [0, $effect];
    }

    /**
     * @return list<array{key: string, active: bool, severity: BalanceAlertSeverity, summary: string, evidence: array<string, int|string>}>
     */
    private function signals(): array
    {
        $run = $this->latestFullRun();

        return [
            $this->reconciliationDriftSignal($run),
            $this->reconciliationReceiptCollisionSignal($run),
            $this->treasuryCoverageSignal($run),
            $this->reorgLossSignal(),
            $this->receiptRegistryConflictSignal(),
            $this->topUpWorkBacklogSignal(),
            $this->settlementBacklogSignal(),
            $this->outboundBacklogSignal(),
            $this->restoreGateSignal(),
            $this->deliveryPausedSignal(),
            $this->accountFrozenSignal(),
        ];
    }

    private function latestFullRun(): ?UsdtBalanceReconciliationRun
    {
        return UsdtBalanceReconciliationRun::query()
            ->where('scope', 'full')
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{key: string, active: bool, severity: BalanceAlertSeverity, summary: string, evidence: array<string, int|string>}
     */
    private function reconciliationDriftSignal(?UsdtBalanceReconciliationRun $run): array
    {
        $findings = (int) ($run?->account_finding_count ?? 0);
        $active = $run !== null
            && $run->verdict === UsdtReconciliationVerdict::Findings
            && $findings > 0;

        return [
            'key' => self::ALERT_RECONCILIATION_DRIFT,
            'active' => $active,
            'severity' => BalanceAlertSeverity::Critical,
            'summary' => $run === null
                ? 'Belum ada run rekonsiliasi penuh.'
                : sprintf(
                    'Temuan drift saldo USDT pada rekonsiliasi penuh terakhir (run #%d): %d akun diperiksa, %d akun bermasalah, %d dibekukan.',
                    (int) $run->id,
                    (int) $run->accounts_inspected,
                    $findings,
                    (int) $run->drift_frozen_count,
                ),
            'evidence' => [
                'run_id' => (int) ($run?->id ?? 0),
                'checked_at' => $run?->checked_at?->toIso8601String() ?? '',
                'accounts_inspected' => (int) ($run?->accounts_inspected ?? 0),
                'account_finding_count' => $findings,
                'drift_frozen_count' => (int) ($run?->drift_frozen_count ?? 0),
            ],
        ];
    }

    /**
     * @return array{key: string, active: bool, severity: BalanceAlertSeverity, summary: string, evidence: array<string, int|string>}
     */
    private function reconciliationReceiptCollisionSignal(?UsdtBalanceReconciliationRun $run): array
    {
        $findings = (int) ($run?->receipt_finding_count ?? 0);

        return [
            'key' => self::ALERT_RECONCILIATION_RECEIPT_COLLISION,
            'active' => $run !== null && $findings > 0,
            'severity' => BalanceAlertSeverity::Critical,
            'summary' => $run === null
                ? 'Belum ada run rekonsiliasi penuh.'
                : sprintf(
                    'Temuan pencocokan receipt pada rekonsiliasi penuh terakhir (run #%d): %d temuan receipt.',
                    (int) $run->id,
                    $findings,
                ),
            'evidence' => [
                'run_id' => (int) ($run?->id ?? 0),
                'checked_at' => $run?->checked_at?->toIso8601String() ?? '',
                'receipt_finding_count' => $findings,
            ],
        ];
    }

    /**
     * @return array{key: string, active: bool, severity: BalanceAlertSeverity, summary: string, evidence: array<string, int|string>}
     */
    private function treasuryCoverageSignal(?UsdtBalanceReconciliationRun $run): array
    {
        $liability = (string) ($run?->liability_micro ?? '0');
        $custodyState = $run?->custody_state;
        $treasuryFindings = is_array($run?->findings) ? ($run->findings['treasury']['findings'] ?? []) : [];
        $treasuryFindings = is_array($treasuryFindings) ? $treasuryFindings : [];
        $missingCustodyInput = in_array('missing_custody_input', $treasuryFindings, true);
        $unexplainedVariance = in_array('unexplained_variance', $treasuryFindings, true);
        $badCustody = $custodyState === null || in_array($custodyState, ['unavailable', 'unsupported', 'missing'], true);

        $active = $run !== null
            && ((! BigInteger::of($liability)->isZero() && $badCustody) || $missingCustodyInput || $unexplainedVariance);

        return [
            'key' => self::ALERT_TREASURY_COVERAGE,
            'active' => $active,
            'severity' => BalanceAlertSeverity::Critical,
            'summary' => $run === null
                ? 'Belum ada run rekonsiliasi penuh.'
                : sprintf(
                    'Coverage treasury USDT belum terverifikasi pada rekonsiliasi penuh terakhir (run #%d): liabilitas %s micro-USDT, custody %s.',
                    (int) $run->id,
                    $liability,
                    $custodyState === null || $custodyState === '' ? 'tidak tersedia' : $custodyState,
                ),
            'evidence' => [
                'run_id' => (int) ($run?->id ?? 0),
                'checked_at' => $run?->checked_at?->toIso8601String() ?? '',
                'liability_micro' => $liability,
                'custody_state' => $custodyState ?? 'none',
                'missing_custody_input' => $missingCustodyInput ? 1 : 0,
                'unexplained_variance' => $unexplainedVariance ? 1 : 0,
            ],
        ];
    }

    /**
     * @return array{key: string, active: bool, severity: BalanceAlertSeverity, summary: string, evidence: array<string, int|string>}
     */
    private function reorgLossSignal(): array
    {
        $incidents = DB::table('usdt_reorg_incidents')
            ->selectRaw('COUNT(*) AS incident_count, COALESCE(SUM(exposure_micro), 0) AS exposure_micro, COALESCE(SUM(spent_exposure_micro), 0) AS spent_exposure_micro')
            ->first();
        $incidentCount = (int) ($incidents->incident_count ?? 0);
        $exposureMicro = (int) ($incidents->exposure_micro ?? 0);
        $spentMicro = (int) ($incidents->spent_exposure_micro ?? 0);
        $authorizedCases = DB::table('usdt_reorg_recovery_cases')->count();

        return [
            'key' => self::ALERT_REORG_LOSS_UNRESOLVED,
            'active' => $incidentCount > 0,
            'severity' => BalanceAlertSeverity::Critical,
            'summary' => sprintf(
                'Insiden reorg USDT belum terselesaikan: %d insiden, eksposur %d micro-USDT, telah dibelanjakan %d micro-USDT, %d kasus pemulihan terotorisasi.',
                $incidentCount,
                $exposureMicro,
                $spentMicro,
                $authorizedCases,
            ),
            'evidence' => [
                'incident_count' => $incidentCount,
                'exposure_micro' => $exposureMicro,
                'spent_exposure_micro' => $spentMicro,
                'authorized_recovery_case_count' => (int) $authorizedCases,
            ],
        ];
    }

    /**
     * @return array{key: string, active: bool, severity: BalanceAlertSeverity, summary: string, evidence: array<string, int|string>}
     */
    private function receiptRegistryConflictSignal(): array
    {
        $conflicts = ExternalMoneyReceiptReservation::query()
            ->where('reason', ExternalMoneyReceiptReservation::REASON_REGISTRY_CONFLICT)
            ->whereNull('resolved_at')
            ->count();
        $reviews = ExternalMoneyReceipt::query()
            ->where('status', ExternalMoneyReceiptStatus::Review->value)
            ->count();

        $active = $conflicts > 0 || $reviews > 0;
        $severity = $conflicts > 0 ? BalanceAlertSeverity::Critical : BalanceAlertSeverity::Warning;

        return [
            'key' => self::ALERT_RECEIPT_REGISTRY_CONFLICT,
            'active' => $active,
            'severity' => $severity,
            'summary' => $conflicts > 0
                ? sprintf('Konflik registri receipt keuangan: %d reservasi terbuka.', $conflicts)
                : sprintf('Receipt keuangan menunggu review Owner: %d receipt.', $reviews),
            'evidence' => [
                'registry_conflict_open' => (int) $conflicts,
                'review_receipt_count' => (int) $reviews,
            ],
        ];
    }

    /**
     * @return array{key: string, active: bool, severity: BalanceAlertSeverity, summary: string, evidence: array<string, int|string>}
     */
    private function topUpWorkBacklogSignal(): array
    {
        $query = DB::table('customer_usdt_top_up_work')
            ->whereIn('state', self::TOP_UP_DUE_STATES)
            ->where('next_attempt_at', '<=', now());

        $oldest = (clone $query)->orderBy('next_attempt_at')->orderBy('top_up_id')
            ->first(['next_attempt_at', 'state']);
        $dueCount = $query->count();
        $age = $oldest === null ? null : $this->ageSeconds($oldest->next_attempt_at);

        return $this->backlogSignal(
            self::ALERT_TOP_UP_WORK_BACKLOG,
            $age,
            [
                'due_count' => (int) $dueCount,
                'oldest_age_seconds' => (int) ($age ?? 0),
                'oldest_state' => (string) ($oldest->state ?? 'none'),
            ],
            sprintf(
                'Backlog pekerjaan top-up USDT: %d pekerjaan jatuh tempo, tertua %d detik.',
                $dueCount,
                (int) ($age ?? 0),
            ),
        );
    }

    /**
     * @return array{key: string, active: bool, severity: BalanceAlertSeverity, summary: string, evidence: array<string, int|string>}
     */
    private function settlementBacklogSignal(): array
    {
        $query = DB::table('customer_usdt_top_up_settlement_work')
            ->whereNotIn('state', self::SETTLEMENT_TERMINAL_STATES)
            ->where('next_check_at', '<=', now());

        $oldest = (clone $query)->orderBy('next_check_at')->orderBy('top_up_id')
            ->first(['next_check_at', 'state']);
        $dueCount = $query->count();
        $age = $oldest === null ? null : $this->ageSeconds($oldest->next_check_at);

        return $this->backlogSignal(
            self::ALERT_SETTLEMENT_BACKLOG,
            $age,
            [
                'due_count' => (int) $dueCount,
                'oldest_age_seconds' => (int) ($age ?? 0),
                'oldest_state' => (string) ($oldest->state ?? 'none'),
            ],
            sprintf(
                'Backlog penyelesaian top-up USDT: %d pekerjaan jatuh tempo, tertua %d detik.',
                $dueCount,
                (int) ($age ?? 0),
            ),
        );
    }

    /**
     * @return array{key: string, active: bool, severity: BalanceAlertSeverity, summary: string, evidence: array<int|string, int|string>}
     */
    private function outboundBacklogSignal(): array
    {
        $families = [
            TelegramOutboundEffectType::UsdtTopUpReceipt->value,
            TelegramOutboundEffectType::UsdtRefundReceipt->value,
            TelegramOutboundEffectType::BalanceTopUpReceipt->value,
            TelegramOutboundEffectType::BalanceTopUpOwnerAlert->value,
            TelegramOutboundEffectType::BalanceTopUpPaidNotification->value,
            TelegramOutboundEffectType::OwnerCompensationNotification->value,
        ];

        $query = DB::table('telegram_outbound_effects')
            ->whereIn('state', ['pending', 'failed'])
            ->whereIn('effect_type', $families);

        $oldest = (clone $query)->orderBy('created_at')->orderBy('id')
            ->first(['created_at', 'effect_type', 'state']);
        $pendingCount = $query->count();
        $age = $oldest === null ? null : $this->ageSeconds($oldest->created_at);

        return $this->backlogSignal(
            self::ALERT_OUTBOUND_BACKLOG,
            $age,
            [
                'pending_count' => (int) $pendingCount,
                'oldest_age_seconds' => (int) ($age ?? 0),
                'oldest_state' => (string) ($oldest->state ?? 'none'),
                'oldest_effect_type' => (string) ($oldest->effect_type ?? 'none'),
            ],
            sprintf(
                'Backlog pengiriman Telegram: %d efek belum terminal, tertua %d detik.',
                $pendingCount,
                (int) ($age ?? 0),
            ),
            self::OUTBOUND_WARNING_SECONDS,
            self::OUTBOUND_CRITICAL_SECONDS,
        );
    }

    /**
     * @param  array<string, int|string>  $evidence
     * @return array{key: string, active: bool, severity: BalanceAlertSeverity, summary: string, evidence: array<string, int|string>}
     */
    private function backlogSignal(
        string $key,
        ?int $age,
        array $evidence,
        string $summary,
        int $warningSeconds = self::BACKLOG_WARNING_SECONDS,
        int $criticalSeconds = self::BACKLOG_CRITICAL_SECONDS,
    ): array {
        $active = $age !== null && $age > $warningSeconds;

        return [
            'key' => $key,
            'active' => $active,
            'severity' => $age !== null && $age > $criticalSeconds
                ? BalanceAlertSeverity::Critical
                : BalanceAlertSeverity::Warning,
            'summary' => $summary,
            'evidence' => $evidence,
        ];
    }

    /**
     * @return array{key: string, active: bool, severity: BalanceAlertSeverity, summary: string, evidence: array<string, int|string>}
     */
    private function restoreGateSignal(): array
    {
        $gate = BalanceRestoreGate::query()->whereKey(BalanceRestoreGate::SINGLETON_ID)->first();
        $active = $gate !== null && $gate->state === BalanceRestoreGateState::Open;

        return [
            'key' => self::ALERT_RESTORE_GATE_OPEN,
            'active' => $active,
            'severity' => BalanceAlertSeverity::Critical,
            'summary' => sprintf(
                'Gerbang pemulihan saldo terbuka: window %s s.d. %s, dibuka %s, replay %s.',
                $gate?->window_start?->toIso8601String() ?? 'tidak tersedia',
                $gate?->window_end?->toIso8601String() ?? 'tidak tersedia',
                $gate?->opened_at?->toIso8601String() ?? 'tidak tersedia',
                $gate?->replay_completed_at?->toIso8601String() ?? 'belum selesai',
            ),
            'evidence' => [
                'gate_state' => (string) ($gate->state?->value ?? 'missing'),
                'window_start' => $gate?->window_start?->toIso8601String() ?? '',
                'window_end' => $gate?->window_end?->toIso8601String() ?? '',
                'opened_at' => $gate?->opened_at?->toIso8601String() ?? '',
                'replay_completed_at' => $gate?->replay_completed_at?->toIso8601String() ?? '',
            ],
        ];
    }

    /**
     * @return array{key: string, active: bool, severity: BalanceAlertSeverity, summary: string, evidence: array<string, int|string>}
     */
    private function deliveryPausedSignal(): array
    {
        $paused = DB::table('customer_usdt_funding_pauses')
            ->where('scope', CustomerUsdtFundingPauseScope::Delivery->value)
            ->exists();

        return [
            'key' => self::ALERT_DELIVERY_PAUSED,
            'active' => $paused,
            'severity' => BalanceAlertSeverity::Warning,
            'summary' => 'Pengiriman bukti saldo USDT dijeda oleh Owner (scope delivery).',
            'evidence' => [
                'scope' => CustomerUsdtFundingPauseScope::Delivery->value,
            ],
        ];
    }

    /**
     * @return array{key: string, active: bool, severity: BalanceAlertSeverity, summary: string, evidence: array<string, int|string>}
     */
    private function accountFrozenSignal(): array
    {
        $frozen = CustomerUsdtBalanceAccount::query()->whereNotNull('frozen_reason')->count();

        return [
            'key' => self::ALERT_ACCOUNT_FROZEN,
            'active' => $frozen > 0,
            'severity' => BalanceAlertSeverity::Warning,
            'summary' => sprintf('Akun saldo USDT dibekukan: %d akun.', $frozen),
            'evidence' => [
                'frozen_account_count' => (int) $frozen,
            ],
        ];
    }

    private function ageSeconds(mixed $timestamp): int
    {
        if ($timestamp === null || $timestamp === '') {
            return 0;
        }

        $at = $timestamp instanceof \DateTimeInterface ? $timestamp : Carbon::parse((string) $timestamp);

        return max(0, now()->getTimestamp() - $at->getTimestamp());
    }

    /**
     * @param  array<string, int|string>  $evidence
     */
    private function fingerprint(string $key, BalanceAlertSeverity $severity, array $evidence): string
    {
        ksort($evidence);

        $parts = ['alert='.$key, 'severity='.$severity->value];
        foreach ($evidence as $name => $value) {
            $parts[] = $name.'='.$this->canonicalValue($value);
        }

        return hash('sha256', implode('|', $parts));
    }

    private function canonicalValue(int|string $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return preg_replace('/[^A-Za-z0-9_.:-]/', '_', $value) ?? '';
    }

    private function openMessage(BalanceAlertSeverity $severity, string $summary): string
    {
        $marker = $severity === BalanceAlertSeverity::Critical ? 'KRITIS' : 'PERINGATAN';

        return '['.$marker.'] '.$summary;
    }

    private function resolvedMessage(BalanceOperationsAlertEpisode $episode): string
    {
        return '[SELESAI] Kondisi pulih: '.$episode->summary;
    }
}
