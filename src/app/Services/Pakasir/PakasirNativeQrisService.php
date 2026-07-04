<?php

namespace App\Services\Pakasir;

use App\Models\Payment;
use Illuminate\Support\Facades\Cache;

class PakasirNativeQrisService
{
    public function __construct(private readonly PakasirProviderAdapter $pakasir, private readonly PakasirProviderReadiness $readiness) {}

    public function ensureCreated(Payment $payment): PakasirQrisTransaction
    {
        $this->readiness->verification()->assertReady('Pakasir native QRIS cannot be created.');

        $payment->refresh();
        if ($this->hasPersistedQris($payment)) {
            return $this->transactionFromPayment($payment);
        }

        return Cache::lock('pakasir:native-qris:create:'.$payment->getKey(), 30)
            ->block(10, function () use ($payment): PakasirQrisTransaction {
                $payment->refresh();
                if ($this->hasPersistedQris($payment)) {
                    return $this->transactionFromPayment($payment);
                }

                return $this->pakasir->createQrisTransaction($payment);
            });
    }

    private function hasPersistedQris(Payment $payment): bool
    {
        return $payment->provider_payment_method === 'qris' && filled($payment->provider_payment_number);
    }

    private function transactionFromPayment(Payment $payment): PakasirQrisTransaction
    {
        $providerPayment = [
            'project' => (string) config('services.pakasir.slug'),
            'order_id' => $payment->merchant_ref,
            'amount' => $payment->amount,
            'payment_method' => $payment->provider_payment_method,
            'payment_number' => $payment->provider_payment_number,
        ];

        if ($payment->provider_fee !== null) {
            $providerPayment['fee'] = $payment->provider_fee;
        }

        if ($payment->provider_total_payment !== null) {
            $providerPayment['total_payment'] = $payment->provider_total_payment;
        }

        if ($payment->provider_expires_at !== null) {
            $providerPayment['expired_at'] = $payment->provider_expires_at->utc()->toAtomString();
        }

        $transaction = PakasirQrisTransaction::fromPayload(['payment' => $providerPayment]);
        $transaction->assertMatches($payment, (string) config('services.pakasir.slug'));

        return $transaction;
    }
}
