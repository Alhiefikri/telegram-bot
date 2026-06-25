<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\Tripay\TripayCallbackProcessor;
use App\Services\Tripay\TripayClient;
use Illuminate\Console\Command;

class ReconcileTripayPayments extends Command
{
    protected $signature = 'tripay:reconcile {--chunk=100}';

    protected $description = 'Reconcile pending Tripay payments from Tripay transaction detail.';

    public function handle(TripayClient $client, TripayCallbackProcessor $processor): int
    {
        $processed = 0;
        $skipped = 0;
        $failed = 0;

        Payment::query()
            ->where('provider', 'tripay')
            ->whereIn('status', [PaymentStatus::Pending, PaymentStatus::Expired, PaymentStatus::Failed])
            ->whereNotNull('provider_reference')
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($payments) use ($client, $processor, &$processed, &$skipped, &$failed): void {
                foreach ($payments as $payment) {
                    try {
                        $event = $processor->processReconciliationForPayment($payment, $client->detail($payment->provider_reference));

                        if ($event->processed_at === null) {
                            $skipped++;

                            continue;
                        }

                        $processed++;
                    } catch (\Throwable $e) {
                        $failed++;
                        $this->error('Tripay reconciliation failed payment '.$payment->id.': '.$e->getMessage());
                    }
                }
            });

        $this->writeSummary($processed, $skipped, $failed);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function writeSummary(int $processed, int $skipped, int $failed): void
    {
        $summary = "processed={$processed} skipped={$skipped} failed={$failed}";

        if ($failed > 0) {
            $this->error('tripay:reconcile completed with partial failures: '.$summary);

            return;
        }

        if ($skipped > 0) {
            $this->warn('tripay:reconcile completed with skips: '.$summary);

            return;
        }

        $this->info('tripay:reconcile completed cleanly: '.$summary);
    }
}
