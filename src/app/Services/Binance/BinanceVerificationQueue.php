<?php

namespace App\Services\Binance;

use App\Jobs\VerifyBinanceAmountUnique;
use App\Jobs\VerifyBinanceOrderId;
use App\Models\Payment;

final class BinanceVerificationQueue
{
    public function dispatchAmountUnique(Payment $payment): void
    {
        VerifyBinanceAmountUnique::dispatch($payment->id)
            ->onQueue($this->queue());
    }

    public function dispatchOrderId(Payment $payment, int $version, int $retryNumber = 1, ?int $delaySeconds = null): void
    {
        $dispatch = VerifyBinanceOrderId::dispatch(
            $payment->id,
            '',
            (int) $payment->binance_order_id_attempts,
            $retryNumber,
            $version,
        );

        if ($delaySeconds !== null) {
            $dispatch->delay(now()->addSeconds($delaySeconds));
        }

        $dispatch
            ->afterCommit()
            ->onQueue($this->queue());
    }

    private function queue(): string
    {
        return (string) config('services.binance.verification_queue', 'binance-verification');
    }
}
