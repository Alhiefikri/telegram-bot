<?php

namespace App\Console\Commands;

use App\Enums\KlikQrisEnvironment;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\KlikQris\KlikQrisCallbackProcessor;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Services\KlikQris\KlikQrisProviderReadiness;
use App\Services\KlikQris\KlikQrisTransaction;
use App\Services\Telegram\TelegramKlikQrisRecoveryEscalator;
use App\Support\PaymentProvider;
use Illuminate\Console\Command;
use Throwable;

final class BackfillKlikQrisInFlightPayments extends Command
{
    protected $signature = 'klikqris:backfill-in-flight {--chunk=100}';

    protected $description = 'Recover pre-rollout KlikQRIS creating and outcome_unknown Payments without creating another transaction.';

    public function handle(
        KlikQrisNativeQrisService $nativeQris,
        KlikQrisCallbackProcessor $processor,
        KlikQrisProviderReadiness $readiness,
        TelegramKlikQrisRecoveryEscalator $escalator,
    ): int {
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT);
        if ($chunk === false || $chunk < 1 || $chunk > 1000) {
            $this->error('klikqris:backfill-in-flight failed: chunk must be between 1 and 1000.');

            return self::FAILURE;
        }

        $environment = KlikQrisEnvironment::runtime();
        if (! $readiness->forEnvironment($environment)->isReady()) {
            if ($readiness->disabledForEnvironment($environment)) {
                $this->info('klikqris:backfill-in-flight skipped safely: KlikQRIS is disabled for this environment.');

                return self::SUCCESS;
            }

            $this->error('klikqris:backfill-in-flight failed: active KlikQRIS environment is not ready.');

            return self::FAILURE;
        }

        $recovered = 0;
        $skipped = 0;
        $failed = 0;

        Payment::query()
            ->where('provider', PaymentProvider::KLIKQRIS)
            ->where('status', PaymentStatus::Pending->value)
            ->whereNotNull('merchant_ref')
            ->whereIn('provider_create_state', ['creating', 'outcome_unknown'])
            ->orderBy('id')
            ->chunkById($chunk, function ($payments) use ($nativeQris, $processor, $escalator, &$recovered, &$skipped, &$failed): void {
                foreach ($payments as $payment) {
                    try {
                        $result = $nativeQris->reconcileInFlight(
                            $payment,
                            function (Payment $payment, KlikQrisTransaction $transaction) use ($processor): void {
                                $processor->processReconciliationForPayment($payment, $transaction);
                            },
                        );

                        if ($result === null) {
                            $skipped++;

                            continue;
                        }

                        $recovered++;
                        $escalator->notifyFor($payment);
                    } catch (Throwable $exception) {
                        report($exception);
                        $failed++;
                        $this->error('KlikQRIS in-flight backfill failed payment '.$payment->id.': '.$exception::class);
                        $escalator->notifyFor($payment);
                    }
                }
            });

        $summary = "recovered={$recovered} skipped={$skipped} failed={$failed}";
        if ($failed > 0) {
            $this->error('klikqris:backfill-in-flight completed with partial failures: '.$summary);

            return self::FAILURE;
        }

        $this->info('klikqris:backfill-in-flight completed: '.$summary);

        return self::SUCCESS;
    }
}
