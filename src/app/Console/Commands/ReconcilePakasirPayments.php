<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\Pakasir\PakasirCallbackProcessor;
use App\Services\Pakasir\PakasirPayloadSanitizer;
use App\Services\Pakasir\PakasirProviderAdapter;
use App\Services\Pakasir\PakasirProviderReadiness;
use Illuminate\Console\Command;
use Throwable;

class ReconcilePakasirPayments extends Command
{
    private const RELEASED_PAYMENT_STATUSES = [
        PaymentStatus::Expired->value,
        PaymentStatus::Failed->value,
    ];

    protected $signature = 'pakasir:reconcile {--chunk=100}';

    protected $description = 'Reconcile pending or locally released Pakasir payments from Pakasir transaction detail.';

    public function handle(PakasirProviderAdapter $client, PakasirCallbackProcessor $processor, PakasirProviderReadiness $pakasirReadiness): int
    {
        $processed = 0;
        $skipped = 0;
        $failed = 0;

        $readiness = $pakasirReadiness->verification();
        if (! $readiness->isReady()) {
            $this->error('pakasir:reconcile failed: '.$readiness->configurationMessage());

            return self::FAILURE;
        }

        $releasedCutoff = now()->subHours($this->releasedLookbackHours());

        Payment::query()
            ->where('provider', 'pakasir')
            ->whereNotNull('merchant_ref')
            ->where(function ($query) use ($releasedCutoff): void {
                $query
                    ->where('status', PaymentStatus::Pending->value)
                    ->orWhere(fn ($query) => $query
                        ->whereIn('status', self::RELEASED_PAYMENT_STATUSES)
                        ->where('updated_at', '>=', $releasedCutoff));
            })
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($payments) use ($client, $processor, &$processed, &$skipped, &$failed): void {
                foreach ($payments as $payment) {
                    try {
                        $event = $processor->processReconciliationForPayment($payment, $client->transactionDetail($payment));

                        if ($event->processed_at === null) {
                            $skipped++;

                            continue;
                        }

                        $processed++;
                    } catch (Throwable $e) {
                        $failed++;
                        $this->error('Pakasir reconciliation failed payment '.$payment->id.': '.$this->safeExceptionMessage($e));
                    }
                }
            });

        $summary = "processed={$processed} skipped={$skipped} failed={$failed}";

        if ($failed > 0) {
            $this->error('pakasir:reconcile completed with partial failures: '.$summary);

            return self::FAILURE;
        }

        if ($skipped > 0) {
            $this->warn('pakasir:reconcile completed with skips: '.$summary);

            return self::SUCCESS;
        }

        $this->info('pakasir:reconcile completed cleanly: '.$summary);

        return self::SUCCESS;
    }

    private function safeExceptionMessage(Throwable $exception): string
    {
        $message = PakasirPayloadSanitizer::redactText($exception->getMessage(), [
            (string) config('services.pakasir.api_key', ''),
        ]);

        return trim($message) === '' ? $exception::class : $message;
    }

    private function releasedLookbackHours(): int
    {
        return max(1, (int) config('services.pakasir.reconcile_released_lookback_hours', 72));
    }
}
