<?php

namespace App\Console\Commands;

use App\Enums\KlikQrisEnvironment;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\KlikQris\KlikQrisCallbackProcessor;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Services\KlikQris\KlikQrisProviderReadiness;
use App\Services\KlikQris\KlikQrisStatusResponseException;
use App\Services\KlikQris\KlikQrisStatusUnavailableException;
use App\Services\KlikQris\KlikQrisTransaction;
use App\Services\ProviderVerification\ProviderVerificationProfiles;
use App\Services\Telegram\TelegramKlikQrisRecoveryEscalator;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Support\PaymentProvider;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class ReconcileKlikQrisPayments extends Command
{
    private const MAX_MAX_PAYMENTS = 100;

    private const CURSOR_TTL_SECONDS = 86_400;

    /**
     * Candidate classes stay disjoint so one sweep never counts a Payment
     * twice. Active payments protect latency, outcome_unknown protects
     * ambiguous creates (including locally paid rows that still need an
     * authoritative signature), and history keeps late-payment recovery moving.
     */
    private const CLASS_ACTIVE = 'active';

    private const CLASS_OUTCOME_UNKNOWN = 'outcome_unknown';

    private const CLASS_HISTORY = 'history';

    /**
     * Processing order for the reserved minimum and for spending spare quota.
     * A nonempty history class always keeps a reserved share of the sweep.
     */
    private const CLASS_PRIORITY = [
        self::CLASS_ACTIVE,
        self::CLASS_OUTCOME_UNKNOWN,
        self::CLASS_HISTORY,
    ];

    private const HISTORY_RESERVED_SHARE_DIVISOR = 4;

    protected $signature = 'klikqris:reconcile {--chunk=100} {--max-payments=10} {--after= : Resume the fair sweep after this created_at timestamp instead of the persisted cursors.}';

    protected $description = 'Reconcile eligible KlikQRIS payments from authoritative transaction status.';

    public function __construct(
        private readonly ?Closure $deadlineFactory = null,
    ) {
        parent::__construct();
    }

    public function handle(
        KlikQrisNativeQrisService $nativeQris,
        KlikQrisCallbackProcessor $processor,
        KlikQrisProviderReadiness $readiness,
        TelegramKlikQrisRecoveryEscalator $escalator,
        TelegramOutboundEffectService $outboundEffects,
        TelegramOutboundEffectDispatcher $outboundEffectDispatcher,
    ): int {
        return ProviderVerificationProfiles::until($this->executionDeadline(), fn (): int => $this->reconcile(
            $nativeQris, $processor, $readiness, $escalator, $outboundEffects, $outboundEffectDispatcher,
        ));
    }

    private function reconcile(
        KlikQrisNativeQrisService $nativeQris,
        KlikQrisCallbackProcessor $processor,
        KlikQrisProviderReadiness $readiness,
        TelegramKlikQrisRecoveryEscalator $escalator,
        TelegramOutboundEffectService $outboundEffects,
        TelegramOutboundEffectDispatcher $outboundEffectDispatcher,
    ): int {
        $chunk = $this->validatedChunk();
        if ($chunk === null) {
            $this->error('klikqris:reconcile failed: chunk must be between 1 and 1000.');

            return self::FAILURE;
        }
        $maxPayments = $this->validatedMaxPayments();
        if ($maxPayments === null) {
            $this->error('klikqris:reconcile failed: max-payments must be between 1 and 100.');

            return self::FAILURE;
        }
        $environment = KlikQrisEnvironment::runtime();
        $readinessReport = $readiness->forEnvironment($environment);
        if (! $readinessReport->isReady()) {
            if ($readiness->disabledForEnvironment($environment)) {
                $this->info('klikqris:reconcile skipped safely: KlikQRIS is disabled for this environment.');

                return self::SUCCESS;
            }

            $this->error('klikqris:reconcile failed: active KlikQRIS environment is not ready.');

            return self::FAILURE;
        }

        $afterOverride = $this->cursorAfterOverride();
        if ($afterOverride === false) {
            $this->error('klikqris:reconcile failed: after must be a valid timestamp.');

            return self::FAILURE;
        }
        $this->seedAfterOverride($afterOverride, $environment);

        $startedAt = hrtime(true);
        $releasedCutoff = now()->subHours($this->releasedLookbackHours());
        $pagesFetched = 0;
        $scanCap = $this->cursorScanCap();
        $totals = ['selected' => 0, 'processed' => 0, 'skipped' => 0, 'failed' => 0];
        $classStats = $this->emptyClassStats();

        $present = [];
        foreach (self::CLASS_PRIORITY as $class) {
            $present[$class] = $this->classQuery($class, $releasedCutoff)->exists();
        }

        $rotationUsed = false;
        $budgets = $this->classBudgets($present, $maxPayments, $environment, $rotationUsed);
        $reserved = $this->classMinimums($present, $budgets, $rotationUsed);

        $reconcile = function (Payment $payment) use (
            $nativeQris,
            $processor,
            $escalator,
            $outboundEffects,
            $outboundEffectDispatcher,
        ): string {
            return $this->reconcilePayment(
                $payment,
                $nativeQris,
                $processor,
                $escalator,
                $outboundEffects,
                $outboundEffectDispatcher,
            );
        };

        // Reserved minimum per class runs first: every nonempty class makes
        // progress before one class can consume the whole provider/time budget.
        $processedIds = [];
        $exhausted = [];
        foreach (self::CLASS_PRIORITY as $class) {
            $exhausted[$class] = ! $present[$class];
            $quota = min($reserved[$class] ?? 0, $maxPayments - $totals['selected']);
            if ($quota <= 0) {
                continue;
            }

            $classStats[$class]['budget'] += $quota;
            $result = $this->sweepClass(
                $class,
                $quota,
                $releasedCutoff,
                $chunk,
                $scanCap,
                $pagesFetched,
                $totals,
                $classStats,
                $processedIds,
                $reconcile,
                $environment,
            );
            $exhausted[$class] = $result['exhausted'];
            $classStats[$class]['wrapped'] = $result['wrapped'];
        }

        // Spare quota from a class that ran out of rows goes to the next
        // priority class instead of leaving the sweep underused. When the
        // max-payments cap is smaller than the number of nonempty classes,
        // the rotation branch above already guarantees progress over sweeps.
        if (! $rotationUsed) {
            foreach (self::CLASS_PRIORITY as $class) {
                if ($totals['selected'] >= $maxPayments || ProviderVerificationProfiles::stopReason() !== null) {
                    break;
                }
                if ($exhausted[$class] ?? true) {
                    continue;
                }

                $quota = $maxPayments - $totals['selected'];
                $classStats[$class]['budget'] += $quota;
                $result = $this->sweepClass(
                    $class,
                    $quota,
                    $releasedCutoff,
                    $chunk,
                    $scanCap,
                    $pagesFetched,
                    $totals,
                    $classStats,
                    $processedIds,
                    $reconcile,
                    $environment,
                );
                $exhausted[$class] = $result['exhausted'];
                $classStats[$class]['wrapped'] = $result['wrapped'];
            }
        }

        $summary = "processed={$totals['processed']} skipped={$totals['skipped']} failed={$totals['failed']}";
        $unresolved = Payment::query()
            ->where('provider', PaymentProvider::KLIKQRIS)
            ->where('klikqris_status_last_error', 'status_http_404')
            ->count();
        $this->line("klikqris:reconcile verification: unresolved_status_404={$unresolved}"
            .($unresolved > 0 ? ' operator_action=verify_original_provider_context payment_outcome=unknown' : ''));
        $this->line('klikqris:reconcile classes: '.$this->classSummary($classStats));
        $stopReason = ProviderVerificationProfiles::stopReason() ?? ($totals['selected'] >= $maxPayments ? 'payment_limit' : ($pagesFetched >= $scanCap ? 'scan_limit' : 'exhausted'));
        $this->line('klikqris:reconcile sweep: requests='.ProviderVerificationProfiles::requests().' stop_reason='.$stopReason.' carry_over='.($stopReason !== 'exhausted' ? 'yes' : 'no'));

        Log::info('klikqris.reconciliation.completed', [
            'environment' => $environment->value,
            'chunk' => $chunk,
            'max_payments' => $maxPayments,
            'selected' => $totals['selected'],
            'terminal_lookback_hours' => $this->releasedLookbackHours(),
            'pages_fetched' => $pagesFetched,
            'cursor_scan_cap' => $scanCap,
            'max_execution_seconds' => config('services.klikqris.reconcile_max_execution_seconds', 45),
            'budget_exhausted' => ProviderVerificationProfiles::stopReason() === 'deadline',
            'rotation_used' => $rotationUsed,
            'class_budgets' => $budgets,
            'class_stats' => $classStats,
            'processed' => $totals['processed'],
            'skipped' => $totals['skipped'],
            'failed' => $totals['failed'],
            'unresolved_status_404' => $unresolved,
            'http_attempt_count' => ProviderVerificationProfiles::requests(),
            'stop_reason' => $stopReason,
            'carry_over' => $stopReason !== 'exhausted',
            'duration_ms' => $this->elapsedMilliseconds($startedAt),
        ]);

        if ($totals['failed'] > 0) {
            $this->error('klikqris:reconcile completed with partial failures: '.$summary);

            return self::FAILURE;
        }
        if ($unresolved > 0) {
            $this->error('klikqris:reconcile has unresolved provider verification; deferred discovery is not recovery.');

            return self::FAILURE;
        }

        if ($totals['skipped'] > 0) {
            $this->warn('klikqris:reconcile completed with skips: '.$summary);

            return self::SUCCESS;
        }

        $this->info('klikqris:reconcile completed cleanly: '.$summary);

        return self::SUCCESS;
    }

    /**
     * Walk one class from its own durable cursor until its quota, the class
     * end, the scan cap, or the execution deadline stops it.
     *
     * @param  array{selected: int, processed: int, skipped: int, failed: int}  $totals
     * @param  array<string, array{budget: int, selected: int, processed: int, skipped: int, failed: int, wrapped: bool}>  $classStats
     * @param  array<string, bool>  $processedIds
     * @param  Closure(Payment): string  $reconcile
     * @return array{exhausted: bool, wrapped: bool}
     */
    private function sweepClass(
        string $class,
        int $budget,
        DateTimeInterface $releasedCutoff,
        int $chunk,
        int $scanCap,
        int &$pagesFetched,
        array &$totals,
        array &$classStats,
        array &$processedIds,
        Closure $reconcile,
        KlikQrisEnvironment $environment,
    ): array {
        $cursorKey = self::cursorKey($environment, $class);
        $cursor = $this->resumeCursor($cursorKey);
        $selected = 0;
        $exhausted = false;
        $wrapped = false;

        while ($selected < $budget
            && ProviderVerificationProfiles::stopReason() === null
            && $pagesFetched < $scanCap) {
            $pagesFetched++;
            $pageLimit = min($chunk, $budget - $selected);
            $page = $this->classQuery($class, $releasedCutoff)
                ->when($cursor !== null, fn (Builder $query): Builder => $this->applyCursor($query, $cursor))
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit($pageLimit)
                ->get();

            if ($page->isEmpty()) {
                $exhausted = true;
                $wrapped = true;

                break;
            }

            $stoppedEarly = false;
            foreach ($page as $payment) {
                if ($selected >= $budget || ProviderVerificationProfiles::stopReason() !== null) {
                    $stoppedEarly = true;

                    break;
                }

                // Advance past duplicates without spending the budget twice.
                // A state change can move a Payment into a later class inside
                // the same sweep; it was already offered once. The budget/
                // deadline check above leaves its stopping row eligible for
                // the next sweep instead of skipping it.
                $cursor = $this->cursorFor($payment);
                $paymentId = (string) $payment->getKey();
                if (isset($processedIds[$paymentId])) {
                    continue;
                }
                $processedIds[$paymentId] = true;

                $selected++;
                $totals['selected']++;
                $classStats[$class]['selected']++;
                $outcome = $reconcile($payment);
                $totals[$outcome]++;
                $classStats[$class][$outcome]++;
            }

            // A short page means no more eligible rows remain after the cursor.
            // Reaching it without an early stop means the whole class has had
            // its turn, so the class cursor wraps for the next sweep.
            $reachedEnd = $page->count() < $pageLimit;
            if ($reachedEnd && ! $stoppedEarly) {
                $exhausted = true;
                $wrapped = true;

                break;
            }

            if ($stoppedEarly || $reachedEnd) {
                $exhausted = $reachedEnd;

                break;
            }
        }

        if ($wrapped) {
            // Every eligible row in this class has had its turn. Clear only
            // this class cursor so still-stuck older rows are revisited.
            Cache::forget($cursorKey);
        } elseif ($cursor !== null) {
            Cache::put($cursorKey, $cursor, self::CURSOR_TTL_SECONDS);
        }

        return ['exhausted' => $exhausted, 'wrapped' => $wrapped];
    }

    private function reconcilePayment(
        Payment $payment,
        KlikQrisNativeQrisService $nativeQris,
        KlikQrisCallbackProcessor $processor,
        TelegramKlikQrisRecoveryEscalator $escalator,
        TelegramOutboundEffectService $outboundEffects,
        TelegramOutboundEffectDispatcher $outboundEffectDispatcher,
    ): string {
        $paymentStartedAt = hrtime(true);
        try {
            $event = null;
            $result = $nativeQris->reconcile(
                $payment,
                function (Payment $payment, KlikQrisTransaction $transaction) use (&$event, $processor): void {
                    $event = $processor->processReconciliationForPayment($payment, $transaction);
                },
            );

            if ($result !== null) {
                $escalator->notifyFor($payment);
            }
            if ($result?->isPayable()) {
                $recoveredPayment = $payment->fresh(['order.customer']);
                $recoveredOrder = $recoveredPayment?->order;
                $recoveredCustomer = $recoveredOrder?->customer;
                if ($recoveredPayment !== null && $recoveredOrder !== null && $recoveredCustomer !== null) {
                    $effect = $outboundEffects->recordKlikQrisPresentationRecovery(
                        $recoveredOrder,
                        $recoveredPayment,
                        $recoveredCustomer,
                    );
                    if ($effect !== null) {
                        $outboundEffectDispatcher->dispatch($effect);
                    }
                }
            }

            if ($result === null || $event === null || $event->processed_at === null) {
                $this->logPaymentResult($payment, $result, $event, $paymentStartedAt);

                return 'skipped';
            }

            $this->logPaymentResult($payment, $result, $event, $paymentStartedAt);

            return 'processed';
        } catch (Throwable $exception) {
            $this->error('KlikQRIS reconciliation failed payment '.$payment->id.': '.$this->safeExceptionMessage($exception));
            Log::warning('klikqris.reconciliation.payment_failed', [
                'payment_id' => (string) $payment->getKey(),
                'exception' => $exception::class,
                'retryable' => $exception instanceof KlikQrisStatusUnavailableException,
                'diagnostic_code' => $exception instanceof KlikQrisStatusResponseException ? $exception->diagnosticCode() : null,
                'http_status' => $exception instanceof KlikQrisStatusResponseException ? $exception->httpStatus : null,
                'duration_ms' => $this->elapsedMilliseconds($paymentStartedAt),
            ]);
            $escalator->notifyFor($payment);

            return 'failed';
        }
    }

    /**
     * Disjoint predicates: every eligible Payment belongs to exactly one class.
     */
    private function classQuery(string $class, DateTimeInterface $releasedCutoff): Builder
    {
        $query = Payment::query()
            ->where('provider', PaymentProvider::KLIKQRIS)
            ->whereNotNull('merchant_ref')
            ->where(function (Builder $query): void {
                $query->whereNull('klikqris_status_next_check_at')
                    ->orWhere('klikqris_status_next_check_at', '<=', now());
            });

        return match ($class) {
            self::CLASS_ACTIVE => $query
                ->where('status', PaymentStatus::Pending->value)
                ->where(function (Builder $query): void {
                    $query->whereNull('provider_create_state')
                        ->orWhereNotIn('provider_create_state', ['outcome_unknown', 'recovery_unconfirmed']);
                }),
            self::CLASS_OUTCOME_UNKNOWN => $query
                ->whereIn('provider_create_state', ['outcome_unknown', 'recovery_unconfirmed']),
            self::CLASS_HISTORY => $query
                ->whereIn('status', [PaymentStatus::Expired->value, PaymentStatus::Failed->value])
                ->where(function (Builder $query) use ($releasedCutoff): void {
                    $query->where('updated_at', '>=', $releasedCutoff)
                        ->orWhereNotNull('klikqris_status_last_error');
                })
                ->where(function (Builder $query): void {
                    $query->whereNull('provider_create_state')
                        ->orWhereNotIn('provider_create_state', ['outcome_unknown', 'recovery_unconfirmed']);
                }),
            default => throw new InvalidArgumentException('Unknown KlikQRIS reconciliation class.'),
        };
    }

    /**
     * Reserve a bounded share for history and a minimum for ambiguous creates;
     * active payments spend the remainder. When the cap cannot cover every
     * nonempty class, a durable rotation funds each class in turn so no class
     * is starved across sweeps.
     *
     * @param  array<string, bool>  $present
     * @return array<string, int>
     */
    private function classBudgets(
        array $present,
        int $maxPayments,
        KlikQrisEnvironment $environment,
        bool &$rotationUsed,
    ): array {
        $budgets = array_fill_keys(self::CLASS_PRIORITY, 0);
        $presentClasses = array_values(array_filter(
            self::CLASS_PRIORITY,
            fn (string $class): bool => $present[$class] ?? false,
        ));

        if ($presentClasses === []) {
            $rotationUsed = false;

            return $budgets;
        }

        if (count($presentClasses) > $maxPayments) {
            $rotationUsed = true;
            $rotationKey = self::rotationKey($environment);
            $offset = ((int) Cache::get($rotationKey, 0)) % count($presentClasses);
            $ordered = array_merge(
                array_slice($presentClasses, $offset),
                array_slice($presentClasses, 0, $offset),
            );

            foreach (array_slice($ordered, 0, $maxPayments) as $class) {
                $budgets[$class] = 1;
            }
            Cache::put(
                $rotationKey,
                ($offset + $maxPayments) % count($presentClasses),
                self::CURSOR_TTL_SECONDS,
            );

            return $budgets;
        }

        $rotationUsed = false;
        $activeMinimum = $present[self::CLASS_ACTIVE] ? 1 : 0;
        $unknownMinimum = $present[self::CLASS_OUTCOME_UNKNOWN] ? 1 : 0;
        $historyReserve = $present[self::CLASS_HISTORY]
            ? max(1, intdiv($maxPayments, self::HISTORY_RESERVED_SHARE_DIVISOR))
            : 0;
        $historyReserve = min(
            $historyReserve,
            max(0, $maxPayments - $activeMinimum - $unknownMinimum),
        );

        $budgets[self::CLASS_HISTORY] = $historyReserve;
        $budgets[self::CLASS_OUTCOME_UNKNOWN] = $unknownMinimum;
        $budgets[self::CLASS_ACTIVE] = $present[self::CLASS_ACTIVE]
            ? max($activeMinimum, $maxPayments - $historyReserve - $unknownMinimum)
            : 0;

        return $budgets;
    }

    /**
     * The minimum each nonempty class may verify before spare quota is spent.
     * History receives its full reserve here; active and ambiguous creates get
     * one turn so a slow active lookup cannot push them past the deadline.
     *
     * @param  array<string, bool>  $present
     * @param  array<string, int>  $budgets
     * @return array<string, int>
     */
    private function classMinimums(array $present, array $budgets, bool $rotationUsed): array
    {
        $minimums = array_fill_keys(self::CLASS_PRIORITY, 0);

        if ($rotationUsed) {
            foreach (self::CLASS_PRIORITY as $class) {
                $minimums[$class] = min(1, $budgets[$class] ?? 0);
            }

            return $minimums;
        }

        $minimums[self::CLASS_ACTIVE] = $present[self::CLASS_ACTIVE]
            ? min(1, $budgets[self::CLASS_ACTIVE])
            : 0;
        $minimums[self::CLASS_OUTCOME_UNKNOWN] = $present[self::CLASS_OUTCOME_UNKNOWN]
            ? min(1, $budgets[self::CLASS_OUTCOME_UNKNOWN])
            : 0;
        $minimums[self::CLASS_HISTORY] = $present[self::CLASS_HISTORY]
            ? $budgets[self::CLASS_HISTORY]
            : 0;

        return $minimums;
    }

    /**
     * @return array<string, array{budget: int, selected: int, processed: int, skipped: int, failed: int, wrapped: bool}>
     */
    private function emptyClassStats(): array
    {
        $stats = [];
        foreach (self::CLASS_PRIORITY as $class) {
            $stats[$class] = [
                'budget' => 0,
                'selected' => 0,
                'processed' => 0,
                'skipped' => 0,
                'failed' => 0,
                'wrapped' => false,
            ];
        }

        return $stats;
    }

    /**
     * @param  array<string, array{budget: int, selected: int, processed: int, skipped: int, failed: int, wrapped: bool}>  $classStats
     */
    private function classSummary(array $classStats): string
    {
        $parts = [];
        foreach (self::CLASS_PRIORITY as $class) {
            $stats = $classStats[$class];
            $parts[] = sprintf(
                '%s[budget=%d selected=%d processed=%d skipped=%d failed=%d wrapped=%s]',
                $class,
                $stats['budget'],
                $stats['selected'],
                $stats['processed'],
                $stats['skipped'],
                $stats['failed'],
                $stats['wrapped'] ? 'yes' : 'no',
            );
        }

        return implode(' ', $parts);
    }

    private function validatedChunk(): ?int
    {
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT);
        if ($chunk === false || $chunk < 1 || $chunk > 1000) {
            return null;
        }

        return $chunk;
    }

    private function validatedMaxPayments(): ?int
    {
        $maxPayments = filter_var($this->option('max-payments'), FILTER_VALIDATE_INT);
        if ($maxPayments === false || $maxPayments < 1 || $maxPayments > self::MAX_MAX_PAYMENTS) {
            return null;
        }

        return $maxPayments;
    }

    private function releasedLookbackHours(): int
    {
        return max(1, (int) config('services.klikqris.reconcile_terminal_lookback_hours', 72));
    }

    private function cursorScanCap(): int
    {
        return max(1, (int) config('services.klikqris.reconcile_cursor_scan_cap', 500));
    }

    private static function cursorKey(KlikQrisEnvironment $environment, string $class): string
    {
        return 'klikqris:reconcile:'.$class.':cursor:'.$environment->value;
    }

    private static function rotationKey(KlikQrisEnvironment $environment): string
    {
        return 'klikqris:reconcile:rotation:'.$environment->value;
    }

    /**
     * Reset the durable class cursors and rotation for a deterministic first
     * sweep. The scheduled command owns these keys; local baselines and
     * operators use this seam instead of duplicating cache key names.
     */
    public static function forgetCursors(KlikQrisEnvironment $environment): void
    {
        foreach (self::CLASS_PRIORITY as $class) {
            Cache::forget(self::cursorKey($environment, $class));
        }
        Cache::forget(self::rotationKey($environment));
    }

    private function cursorAfterOverride(): string|false|null
    {
        $raw = trim((string) $this->option('after'));
        if ($raw === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($raw))->format('c');
        } catch (\Exception) {
            return false;
        }
    }

    private function seedAfterOverride(string|false|null $afterOverride, KlikQrisEnvironment $environment): void
    {
        if (! is_string($afterOverride)) {
            return;
        }

        $cursor = $this->cursorPayload($afterOverride);
        Cache::forget(self::rotationKey($environment));
        foreach (self::CLASS_PRIORITY as $class) {
            Cache::put(self::cursorKey($environment, $class), $cursor, self::CURSOR_TTL_SECONDS);
        }
    }

    /**
     * @return array{created_at: string, id: string}|null
     */
    private function resumeCursor(string $cursorKey): ?array
    {
        $cached = Cache::get($cursorKey);
        if (! is_array($cached) || ! isset($cached['created_at'], $cached['id'])) {
            return null;
        }

        try {
            new DateTimeImmutable((string) $cached['created_at']);
        } catch (\Exception) {
            return null;
        }

        return $this->cursorPayload((string) $cached['created_at'], (string) $cached['id']);
    }

    /**
     * @param  array{created_at: string, id: string}  $cursor
     */
    private function applyCursor(Builder $query, array $cursor): Builder
    {
        $createdAt = new DateTimeImmutable($cursor['created_at']);

        if ($cursor['id'] === '') {
            return $query->where('created_at', '>', $createdAt);
        }

        return $query->where(function (Builder $query) use ($createdAt, $cursor): Builder {
            return $query
                ->where('created_at', '>', $createdAt)
                ->orWhere(function (Builder $query) use ($createdAt, $cursor): Builder {
                    return $query
                        ->where('created_at', '=', $createdAt)
                        ->where('id', '>', $cursor['id']);
                });
        });
    }

    /**
     * @return array{created_at: string, id: string}
     */
    private function cursorFor(Payment $payment): array
    {
        $createdAt = $payment->created_at instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($payment->created_at)
            : new DateTimeImmutable((string) $payment->created_at);

        return $this->cursorPayload($createdAt->format('c'), (string) $payment->getKey());
    }

    /**
     * @return array{created_at: string, id: string}
     */
    private function cursorPayload(string $createdAt, string $id = ''): array
    {
        return [
            'created_at' => $createdAt,
            'id' => $id,
        ];
    }

    /**
     * Monotonic deadline that bounds one scheduler sweep. The scheduler mutex
     * expiry (withoutOverlapping) is configured above this value so a slow
     * provider cannot make consecutive sweeps overlap.
     */
    public static function schedulerMutexMinutes(): int
    {
        $executionBudgetSeconds = max(
            1.0,
            (float) config('services.klikqris.reconcile_max_execution_seconds', 45),
        );

        return max(
            1,
            (int) ceil(
                ($executionBudgetSeconds + ProviderVerificationProfiles::requiredLeaseSeconds()) / 60,
            ),
        );
    }

    private function executionDeadline(): int
    {
        if ($this->deadlineFactory !== null) {
            return (int) ($this->deadlineFactory)();
        }

        $budgetSeconds = max(
            1.0,
            (float) config('services.klikqris.reconcile_max_execution_seconds', 45),
        );

        return (int) hrtime(true) + (int) ($budgetSeconds * 1_000_000_000);
    }

    private function logPaymentResult(Payment $payment, mixed $result, mixed $event, int $startedAt): void
    {
        Log::info('klikqris.reconciliation.payment', [
            'payment_id' => (string) $payment->getKey(),
            'outcome' => $result?->outcome()?->value ?? 'busy',
            'processed' => $event?->processed_at !== null,
            'provider_status' => $event?->provider_status,
            'duration_ms' => $this->elapsedMilliseconds($startedAt),
        ]);
    }

    private function elapsedMilliseconds(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 2);
    }

    private function safeExceptionMessage(Throwable $exception): string
    {
        if ($exception instanceof KlikQrisStatusUnavailableException) {
            return 'KlikQRIS status is temporarily unavailable.';
        }
        if ($exception instanceof KlikQrisStatusResponseException) {
            return $exception::class.' ['.$exception->diagnosticCode().']';
        }

        return $exception::class;
    }
}
