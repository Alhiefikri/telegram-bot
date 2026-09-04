<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Enums\SakurupiahEnvironment;
use App\Models\Payment;
use App\Services\Sakurupiah\SakurupiahCallbackProcessor;
use App\Services\Sakurupiah\SakurupiahInvoiceLifecycleService;
use App\Services\Sakurupiah\SakurupiahProviderReadiness;
use App\Services\Sakurupiah\SakurupiahReconciliationOutcome;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

final class ReconcileSakurupiahPayments extends Command
{
    protected $signature = 'sakurupiah:reconcile {--chunk=100}';

    protected $description = 'Reconcile bounded eligible Sakurupiah payments from authoritative transaction status.';

    public function handle(
        SakurupiahCallbackProcessor $processor,
        SakurupiahProviderReadiness $readiness,
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
        $paid = 0;
        $expired = 0;
        $pending = 0;
        $verificationPending = 0;
        $invalid = 0;
        $busy = 0;
        $failed = 0;

        try {
            Payment::query()
                ->where('provider', 'sakurupiah')
                ->whereNotNull('merchant_ref')
                ->where('method', 'QRIS')
                ->where('currency', 'IDR')
                ->where(function (Builder $query) use ($releasedCutoff): void {
                    $query
                        // Authoritative recovery candidates: the Payment has a
                        // persisted provider reference (or an unresolved create
                        // outcome that must be re-checked). Rows that never
                        // attempted creation are owned by the post-commit
                        // create worker and must not be relabelled here.
                        ->where(function (Builder $recoverable) use ($releasedCutoff): void {
                            $recoverable
                                ->whereNotNull('provider_reference')
                                ->where(function (Builder $status) use ($releasedCutoff): void {
                                    $status
                                        ->where('status', PaymentStatus::Pending->value)
                                        ->orWhere(fn (Builder $terminal) => $terminal
                                            ->whereIn('status', [PaymentStatus::Expired->value, PaymentStatus::Failed->value])
                                            ->where('updated_at', '>=', $releasedCutoff));
                                });
                        })
                        ->orWhereIn('provider_create_state', [
                            SakurupiahInvoiceLifecycleService::STATE_OUTCOME_UNKNOWN,
                            SakurupiahInvoiceLifecycleService::STATE_RECOVERY_UNCONFIRMED,
                        ]);
                })
                ->with(['order:id,order_status,expires_at'])
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit($chunk)
                ->get()
                ->each(function (Payment $payment) use ($processor, &$paid, &$expired, &$pending, &$verificationPending, &$invalid, &$busy, &$failed): void {
                    try {
                        $outcome = $processor->reconcilePayment($payment);
                    } catch (Throwable $exception) {
                        $failed++;
                        $this->error('Sakurupiah reconciliation failed payment '.$this->redactedPaymentId($payment).': '.$exception::class);

                        return;
                    }

                    match ($outcome) {
                        SakurupiahReconciliationOutcome::Paid => $paid++,
                        SakurupiahReconciliationOutcome::Expired => $expired++,
                        SakurupiahReconciliationOutcome::VerificationPending => $verificationPending++,
                        SakurupiahReconciliationOutcome::Invalid => $invalid++,
                        SakurupiahReconciliationOutcome::Busy => $busy++,
                        default => $pending++,
                    };
                });
        } catch (Throwable $exception) {
            $this->error('sakurupiah:reconcile failed safely: '.$exception::class);

            return self::FAILURE;
        }

        $summary = "paid={$paid} expired={$expired} pending={$pending} verification_pending={$verificationPending} invalid={$invalid} busy={$busy} failed={$failed}";

        if ($failed > 0) {
            $this->error('sakurupiah:reconcile completed with partial failures: '.$summary);

            return self::FAILURE;
        }

        $this->info('sakurupiah:reconcile completed: '.$summary);

        return self::SUCCESS;
    }

    public static function schedulerMutexMinutes(): int
    {
        $budgetSeconds = max(
            1.0,
            (float) config('services.sakurupiah.reconcile_max_execution_seconds', 45),
        );
        $attempts = max(1, (int) config('services.sakurupiah.lookup_max_attempts', 3));
        $timeoutSeconds = max(0.1, (float) config('services.sakurupiah.http_timeout_seconds', 10));

        return max(1, (int) ceil(($budgetSeconds + ($attempts * $timeoutSeconds) + 8) / 60));
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
