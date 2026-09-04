<?php

namespace App\Console\Commands;

use App\Enums\SakurupiahEnvironment;
use App\Models\Payment;
use App\Services\Sakurupiah\SakurupiahCallbackProcessor;
use App\Services\Sakurupiah\SakurupiahProviderLookupBudget;
use App\Services\Sakurupiah\SakurupiahProviderReadiness;
use App\Services\Sakurupiah\SakurupiahReconciliationOutcome;
use App\Services\Sakurupiah\SakurupiahReconciliationQuery;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class ReconcileSakurupiahPayments extends Command
{
    private const CURSOR_TTL_SECONDS = 86_400;

    protected $signature = 'sakurupiah:reconcile {--chunk=100}';

    protected $description = 'Reconcile bounded eligible Sakurupiah payments from authoritative transaction status.';

    public function handle(
        SakurupiahCallbackProcessor $processor,
        SakurupiahProviderReadiness $readiness,
        SakurupiahReconciliationQuery $reconciliationQuery,
    ): int {
        $chunk = $this->validatedChunk();
        if ($chunk === null) {
            $this->error('sakurupiah:reconcile failed: chunk must be between 1 and 1000.');

            return self::FAILURE;
        }

        $environment = SakurupiahEnvironment::runtime();
        $report = $readiness->configurationReport($environment);
        if (! $report->enabled()) {
            $this->info('sakurupiah:reconcile skipped safely: Sakurupiah is disabled for this environment.');

            return self::SUCCESS;
        }

        if (! $report->isReady()) {
            $this->error('sakurupiah:reconcile failed: the active Sakurupiah environment is not configured and ready.');

            return self::FAILURE;
        }

        $releasedCutoff = now()->subHours($this->releasedLookbackHours());
        $executionDeadline = $this->executionDeadline();
        $cursorKey = $this->cursorKey($environment);
        $cursor = null;
        $stoppedEarly = false;
        $budgetExhausted = false;
        $paid = 0;
        $expired = 0;
        $pending = 0;
        $verificationPending = 0;
        $invalid = 0;
        $busy = 0;
        $failed = 0;

        try {
            $cursor = $this->resumeCursor($cursorKey);

            $page = $reconciliationQuery
                ->eligible($releasedCutoff)
                ->when($cursor !== null, fn (Builder $query): Builder => $this->applyCursor($query, $cursor))
                ->with(['order:id,order_status,expires_at'])
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit($chunk)
                ->get();

            foreach ($page as $payment) {
                if (hrtime(true) >= $executionDeadline) {
                    $budgetExhausted = true;
                    $stoppedEarly = true;

                    break;
                }

                // Advance only after this row produces a durable outcome. A
                // failed row stays at the cursor for the next scheduled retry.
                $paymentCursor = $this->cursorFor($payment);

                try {
                    $outcome = $processor->reconcilePayment($payment);
                } catch (Throwable $exception) {
                    $failed++;
                    $stoppedEarly = true;
                    $this->error('Sakurupiah reconciliation failed payment '.$this->redactedPaymentId($payment).': '.$exception::class);

                    break;
                }

                $cursor = $paymentCursor;

                match ($outcome) {
                    SakurupiahReconciliationOutcome::Paid => $paid++,
                    SakurupiahReconciliationOutcome::Expired => $expired++,
                    SakurupiahReconciliationOutcome::VerificationPending => $verificationPending++,
                    SakurupiahReconciliationOutcome::Invalid => $invalid++,
                    SakurupiahReconciliationOutcome::Busy => $busy++,
                    default => $pending++,
                };

                if (hrtime(true) >= $executionDeadline) {
                    $budgetExhausted = true;
                }
            }

            if (hrtime(true) >= $executionDeadline) {
                $budgetExhausted = true;
            }

            $reachedEnd = $page->count() < $chunk;
            if ($reachedEnd && ! $stoppedEarly) {
                Cache::forget($cursorKey);
                $cursor = null;
            } elseif ($cursor !== null) {
                Cache::put($cursorKey, $cursor, self::CURSOR_TTL_SECONDS);
            }
        } catch (Throwable $exception) {
            $this->error('sakurupiah:reconcile failed safely: '.$exception::class);

            return self::FAILURE;
        }

        $summary = "paid={$paid} expired={$expired} pending={$pending} verification_pending={$verificationPending} invalid={$invalid} busy={$busy} failed={$failed} budget_exhausted=".($budgetExhausted ? '1' : '0');

        if ($failed > 0) {
            $this->error('sakurupiah:reconcile completed with partial failures: '.$summary);

            return self::FAILURE;
        }

        $this->info('sakurupiah:reconcile completed: '.$summary);

        return self::SUCCESS;
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
            $createdAt = new DateTimeImmutable((string) $cached['created_at']);
        } catch (\Exception) {
            return null;
        }

        return [
            'created_at' => $this->cursorTimestamp($createdAt),
            'id' => (string) $cached['id'],
        ];
    }

    private function cursorKey(SakurupiahEnvironment $environment): string
    {
        return 'sakurupiah:reconcile:cursor:'.$environment->value;
    }

    /**
     * @param  array{created_at: string, id: string}  $cursor
     */
    private function applyCursor(Builder $query, array $cursor): Builder
    {
        $createdAt = $cursor['created_at'];

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

        if ($createdAt === false) {
            $createdAt = new DateTimeImmutable((string) $payment->created_at);
        }

        return [
            'created_at' => $this->cursorTimestamp($createdAt),
            'id' => (string) $payment->getKey(),
        ];
    }

    private function cursorTimestamp(DateTimeInterface $createdAt): string
    {
        $timestamp = $createdAt->format('Y-m-d H:i:s.u');

        return str_ends_with($timestamp, '.000000')
            ? substr($timestamp, 0, -7)
            : $timestamp;
    }

    private function executionDeadline(): int
    {
        $budgetSeconds = max(
            1.0,
            (float) config('services.sakurupiah.reconcile_max_execution_seconds', 45),
        );

        return hrtime(true) + (int) ($budgetSeconds * 1_000_000_000);
    }

    public static function schedulerMutexMinutes(): int
    {
        $budgetSeconds = max(
            1.0,
            (float) config('services.sakurupiah.reconcile_max_execution_seconds', 45),
        );

        return max(
            1,
            (int) ceil(
                ($budgetSeconds + SakurupiahProviderLookupBudget::requiredLeaseSeconds()) / 60,
            ),
        );
    }

    private function validatedChunk(): ?int
    {
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT);
        if ($chunk === false || $chunk < 1 || $chunk > 1000) {
            return null;
        }

        return $chunk;
    }

    private function releasedLookbackHours(): int
    {
        return max(1, (int) config('services.sakurupiah.reconcile_terminal_lookback_hours', 72));
    }

    private function redactedPaymentId(Payment $payment): string
    {
        return hash('sha256', (string) $payment->getKey());
    }
}
