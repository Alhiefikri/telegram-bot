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
use App\Support\PaymentProvider;
use Illuminate\Console\Command;
use Throwable;

class ReconcileKlikQrisPayments extends Command
{
    private const ELIGIBLE_PAYMENT_STATUSES = [
        PaymentStatus::Pending->value,
        PaymentStatus::Expired->value,
        PaymentStatus::Failed->value,
    ];

    protected $signature = 'klikqris:reconcile {--chunk=100}';

    protected $description = 'Reconcile eligible KlikQRIS payments from authoritative transaction status.';

    public function handle(
        KlikQrisNativeQrisService $nativeQris,
        KlikQrisCallbackProcessor $processor,
        KlikQrisProviderReadiness $readiness,
    ): int {
        $chunk = $this->validatedChunk();
        if ($chunk === null) {
            $this->error('klikqris:reconcile failed: chunk must be between 1 and 1000.');

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

        Payment::query()
            ->where('provider', PaymentProvider::KLIKQRIS)
            ->whereNotNull('merchant_ref')
            ->whereIn('status', self::ELIGIBLE_PAYMENT_STATUSES)
            ->where(function ($query): void {
                $query
                    ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Expired->value, PaymentStatus::Failed->value])
                    ->orWhere('provider_create_state', 'outcome_unknown');
            })
            ->orderBy('id')
            ->chunkById($chunk, function ($payments) use ($nativeQris, $processor, &$processed, &$skipped, &$failed): void {
                foreach ($payments as $payment) {
                    try {
                        $event = null;
                        $result = $nativeQris->reconcile(
                            $payment,
                            function (Payment $payment, KlikQrisTransaction $transaction) use (&$event, $processor): void {
                                $event = $processor->processReconciliationForPayment($payment, $transaction);
                            },
                        );

                        if ($result === null || $event === null || $event->processed_at === null) {
                            $skipped++;

                            continue;
                        }

                        $processed++;
                    } catch (Throwable $exception) {
                        report($exception);
                        $failed++;
                        $this->error('KlikQRIS reconciliation failed payment '.$payment->id.': '.$this->safeExceptionMessage($exception));
                    }
                }
            });

        $summary = "processed={$processed} skipped={$skipped} failed={$failed}";

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

    private function safeExceptionMessage(Throwable $exception): string
    {
        if ($exception instanceof KlikQrisStatusUnavailableException) {
            return 'KlikQRIS status is temporarily unavailable.';
        }

        return $exception::class;
    }
}
