<?php

namespace App\Services\Pakasir;

use App\Models\Order;
use App\Support\PakasirCheckoutFlow;
use App\Models\Payment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PakasirNativeQrisService
{
    public function __construct(
        private readonly PakasirProviderAdapter $pakasir,
        private readonly PakasirProviderReadiness $readiness,
        private readonly PakasirPaymentEventLogger $events,
    ) {}

    public function ensureCreated(Payment $payment): PakasirQrisTransaction
    {
        $this->readiness->verification(PakasirCheckoutFlow::API_QRIS)->assertReady('Pakasir native QRIS cannot be created.');

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

                $transaction = $this->pakasir->createQrisTransaction($payment);
                $transaction->assertMatches($payment, (string) config('services.pakasir.slug'));

                return $this->persistCreatedTransaction($payment, $transaction);
            });
    }

    private function persistCreatedTransaction(Payment $payment, PakasirQrisTransaction $transaction): PakasirQrisTransaction
    {
        return DB::transaction(function () use ($payment, $transaction): PakasirQrisTransaction {
            $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->first();
            $lockedPayment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($this->hasPersistedQris($lockedPayment)) {
                $payment->refresh();

                return $this->transactionFromPayment($payment);
            }

            $providerExpiresAt = $transaction->expiresAt()?->setTimezone((string) config('app.timezone'));
            $paymentUpdates = [
                'provider_payload' => $transaction->sanitizedPayload(),
                'provider_fee' => $transaction->fee(),
                'provider_total_payment' => $transaction->totalPayment(),
                'provider_payment_method' => $transaction->paymentMethod(),
                'provider_created_at' => now(),
                'provider_expires_at' => $providerExpiresAt,
                'provider_payment_number' => $transaction->paymentNumber(),
                'provider_payment_number_hash' => hash('sha256', $transaction->paymentNumber()),
            ];

            if ($providerExpiresAt !== null && ($lockedPayment->expired_at === null || $providerExpiresAt->lt($lockedPayment->expired_at))) {
                $paymentUpdates['expired_at'] = $providerExpiresAt;
            }

            $lockedPayment->update($paymentUpdates);

            if ($providerExpiresAt !== null && $order && ($order->expires_at === null || $providerExpiresAt->lt($order->expires_at))) {
                $order->update(['expires_at' => $providerExpiresAt]);
            }

            $payment->refresh();
            $this->events->logProviderCreate($payment, $transaction);

            return $transaction;
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
