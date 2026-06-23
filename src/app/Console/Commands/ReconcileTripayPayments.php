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
        Payment::query()
            ->where('provider', 'tripay')
            ->whereIn('status', [PaymentStatus::Pending, PaymentStatus::Expired, PaymentStatus::Failed])
            ->whereNotNull('provider_reference')
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($payments) use ($client, $processor): void {
                foreach ($payments as $payment) {
                    try {
                        $processor->processReconciliationForPayment($payment, $client->detail($payment->provider_reference));
                    } catch (\Throwable $e) {
                        $this->warn('Tripay reconciliation skipped payment '.$payment->id.': '.$e->getMessage());
                    }
                }
            });

        return self::SUCCESS;
    }
}
