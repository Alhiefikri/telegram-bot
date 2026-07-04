<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\Pakasir\PakasirCallbackProcessor;
use App\Services\Pakasir\PakasirProviderAdapter;
use App\Services\Pakasir\PakasirProviderReadiness;
use Illuminate\Console\Command;

class ReconcilePakasirPayments extends Command
{
    protected $signature = 'pakasir:reconcile {--chunk=100}';

    protected $description = 'Reconcile pending Pakasir payments from Pakasir transaction detail.';

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

        Payment::query()
            ->where('provider', 'pakasir')
            ->where('status', PaymentStatus::Pending)
            ->whereNotNull('merchant_ref')
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
                    } catch (\Throwable $e) {
                        $failed++;
                        $this->error('Pakasir reconciliation failed payment '.$payment->id.': '.$e->getMessage());
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
}
