<?php

namespace App\Services\Bsc;

use App\Jobs\VerifyBscPayment;
use App\Models\Payment;

final class BscVerificationQueue
{
    public function dispatch(Payment $payment, int $submissionVersion = 0): void
    {
        VerifyBscPayment::dispatch($payment->id, $submissionVersion)
            ->afterCommit()
            ->onQueue($this->queue());
    }

    public function dispatchForScan(Payment $payment): void
    {
        $payment->loadMissing('bscDetail');

        $this->dispatch($payment, (int) ($payment->bscDetail?->tx_submission_version ?? 0));
    }

    private function queue(): string
    {
        return (string) config('services.binance.verification_queue', 'binance-verification');
    }
}
