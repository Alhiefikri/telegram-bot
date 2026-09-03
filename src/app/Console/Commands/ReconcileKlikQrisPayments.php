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
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcileKlikQrisPayments extends Command
{
    private const MAX_MAX_PAYMENTS = 100;

    protected $signature = 'klikqris:reconcile {--chunk=100} {--max-payments=10}';

    protected $description = 'Reconcile eligible KlikQRIS payments from authoritative transaction status.';

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

        $processed = 0;
        $skipped = 0;
        $failed = 0;
        $selected = 0;
        $startedAt = hrtime(true);
        $releasedCutoff = now()->subHours($this->releasedLookbackHours());

        Payment::query()
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
            ->orderBy('id')
            ->chunkById($chunk, function ($payments) use ($nativeQris, $processor, $escalator, $maxPayments, &$selected, &$processed, &$skipped, &$failed): bool {
                foreach ($payments as $payment) {
                    if ($selected >= $maxPayments) {
                        return false;
                    }

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

                return $selected < $maxPayments;
            });

        $summary = "processed={$processed} skipped={$skipped} failed={$failed}";
        Log::info('klikqris.reconciliation.completed', [
            'environment' => $environment->value,
            'chunk' => $chunk,
            'max_payments' => $maxPayments,
            'selected' => $selected,
            'terminal_lookback_hours' => $this->releasedLookbackHours(),
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
