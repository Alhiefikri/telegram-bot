<?php

namespace App\Console\Commands;

use App\Enums\KlikQrisEnvironment;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\KlikQris\KlikQrisCallbackProcessor;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Services\KlikQris\KlikQrisProviderReadiness;
use App\Services\KlikQris\KlikQrisStatusUnavailableException;
use App\Services\KlikQris\KlikQrisTransaction;
use App\Services\Telegram\TelegramKlikQrisRecoveryEscalator;
use App\Support\PaymentProvider;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcileKlikQrisPayments extends Command
{
    private const MAX_MAX_PAYMENTS = 100;

    private const CURSOR_TTL_SECONDS = 86_400;

    protected $signature = 'klikqris:reconcile {--chunk=100} {--max-payments=10} {--after= : Resume the fair sweep after this created_at timestamp instead of the persisted cursor.}';

    protected $description = 'Reconcile eligible KlikQRIS payments from authoritative transaction status.';

    public function __construct(
        private readonly ?\Closure $deadlineFactory = null,
    ) {
        parent::__construct();
    }

    public function handle(
        KlikQrisNativeQrisService $nativeQris,
        KlikQrisCallbackProcessor $processor,
        KlikQrisProviderReadiness $readiness,
        TelegramKlikQrisRecoveryEscalator $escalator,
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

        $processed = 0;
        $skipped = 0;
        $failed = 0;
        $selected = 0;
        $startedAt = hrtime(true);
        $releasedCutoff = now()->subHours($this->releasedLookbackHours());
        $executionDeadline = $this->executionDeadline();
        $cursorKey = $this->cursorKey($environment);
        $cursor = $this->resumeCursor($cursorKey, $afterOverride);
        $wrapped = false;
        $pagesFetched = 0;
        $scanCap = $this->cursorScanCap();

        do {
            $pagesFetched++;

            $page = Payment::query()
                ->where('provider', PaymentProvider::KLIKQRIS)
                ->whereNotNull('merchant_ref')
                ->where(function ($query) use ($releasedCutoff): void {
                    $query
                        ->where('status', PaymentStatus::Pending->value)
                        ->orWhere(fn ($query) => $query
                            ->whereIn('status', [PaymentStatus::Expired->value, PaymentStatus::Failed->value])
                            ->where('updated_at', '>=', $releasedCutoff))
                        ->orWhere('provider_create_state', 'outcome_unknown');
                })
                ->when($cursor !== null, fn (Builder $query): Builder => $this->applyCursor($query, $cursor))
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit($chunk)
                ->get();

            $stoppedEarly = false;
            foreach ($page as $payment) {
                if ($selected >= $maxPayments || hrtime(true) >= $executionDeadline) {
                    $stoppedEarly = true;

                    break;
                }

                // The cursor advances only for rows this sweep actually offers;
                // the row where the budget/max cap breaks stays eligible for the
                // next sweep instead of being skipped.
                $cursor = $this->cursorFor($payment);

                $selected++;
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

                    if ($result === null || $event === null || $event->processed_at === null) {
                        $skipped++;
                        $this->logPaymentResult($payment, $result, $event, $paymentStartedAt);

                        continue;
                    }

                    $processed++;
                    $this->logPaymentResult($payment, $result, $event, $paymentStartedAt);
                } catch (Throwable $exception) {
                    $failed++;
                    $this->error('KlikQRIS reconciliation failed payment '.$payment->id.': '.$this->safeExceptionMessage($exception));
                    Log::warning('klikqris.reconciliation.payment_failed', [
                        'payment_id' => (string) $payment->getKey(),
                        'exception' => $exception::class,
                        'retryable' => $exception instanceof KlikQrisStatusUnavailableException,
                        'duration_ms' => $this->elapsedMilliseconds($paymentStartedAt),
                    ]);
                    $escalator->notifyFor($payment);
                }
            }

            // A short page means no more eligible rows remain after the cursor.
            // Reaching it without an early stop means the whole eligible set has
            // had its turn this cycle, so the cursor wraps for the next one.
            $reachedEnd = $page->count() < $chunk;
            if ($reachedEnd && ! $stoppedEarly) {
                $wrapped = true;
            }
        } while (! $reachedEnd
            && ! $stoppedEarly
            && $selected < $maxPayments
            && hrtime(true) < $executionDeadline
            && $pagesFetched < $scanCap);

        if ($wrapped) {
            // Every eligible row has had its turn this cycle. Clear the cursor so
            // the next sweep starts from the oldest eligible row again, letting
            // still-stuck rows be revisited without starving newer candidates.
            Cache::forget($cursorKey);
            $cursor = null;
        } elseif ($cursor !== null) {
            Cache::put($cursorKey, $cursor, self::CURSOR_TTL_SECONDS);
        }

        $summary = "processed={$processed} skipped={$skipped} failed={$failed}";
        Log::info('klikqris.reconciliation.completed', [
            'environment' => $environment->value,
            'chunk' => $chunk,
            'max_payments' => $maxPayments,
            'selected' => $selected,
            'terminal_lookback_hours' => $this->releasedLookbackHours(),
            'cursor_created_at' => $cursor['created_at'] ?? null,
            'cursor_id' => $cursor['id'] ?? null,
            'cursor_wrapped' => $wrapped,
            'pages_fetched' => $pagesFetched,
            'max_execution_seconds' => config('services.klikqris.reconcile_max_execution_seconds', 45),
            'budget_exhausted' => hrtime(true) >= $executionDeadline,
            'processed' => $processed,
            'skipped' => $skipped,
            'failed' => $failed,
            'duration_ms' => $this->elapsedMilliseconds($startedAt),
        ]);

        if ($failed > 0) {
            $this->error('klikqris:reconcile completed with partial failures: '.$summary);

            return self::FAILURE;
        }

        if ($skipped > 0) {
            $this->warn('klikqris:reconcile completed with skips: '.$summary);

            return self::SUCCESS;
        }

        $this->info('klikqris:reconcile completed cleanly: '.$summary);

        return self::SUCCESS;
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

    private function cursorKey(KlikQrisEnvironment $environment): string
    {
        return 'klikqris:reconcile:cursor:'.$environment->value;
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

    /**
     * @return array{created_at: string, id: string}|null
     */
    private function resumeCursor(string $cursorKey, string|false|null $afterOverride): ?array
    {
        if (is_string($afterOverride)) {
            return $this->cursorPayload($afterOverride);
        }

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

        return $exception::class;
    }
}
