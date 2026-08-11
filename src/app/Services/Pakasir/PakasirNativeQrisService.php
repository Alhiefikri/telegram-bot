<?php

namespace App\Services\Pakasir;

use App\Models\Order;
use App\Models\Payment;
use App\Services\PaymentLifecycleCoordinator;
use App\Services\PaymentLifecycleRollout;
use App\Support\PakasirCheckoutFlow;
use Illuminate\Support\Facades\Cache;

class PakasirNativeQrisService
{
    public function __construct(
        private readonly PakasirProviderAdapter $pakasir,
        private readonly PakasirProviderReadiness $readiness,
        private readonly PakasirPaymentEventLogger $events,
        private readonly PaymentLifecycleCoordinator $lifecycleCoordinator,
        private readonly PaymentLifecycleRollout $rollout,
    ) {}

    public function ensureCreated(Payment $payment): PakasirQrisTransaction
    {
        $payment->refresh();
        $hardened = $this->rollout->usesHardenedPath($payment);
        if ($hardened && ! $this->lifecycleCoordinator->isNativeQrisCreationAllowed($payment)) {
            throw new \RuntimeException('Order is no longer pending payment.');
        }

        $this->readiness->verification(PakasirCheckoutFlow::API_QRIS)->assertReady('Pakasir native QRIS cannot be created.');

        if ($this->hasPayablePersistedQris($payment)) {
            return $this->transactionFromPayment($payment);
        }

        return Cache::lock('pakasir:native-qris:create:'.$payment->getKey(), 30)
            ->block(10, function () use ($payment): PakasirQrisTransaction {
                $payment->refresh();
                $hardened = $this->rollout->usesHardenedPath($payment);
                if ($hardened && ! $this->lifecycleCoordinator->isNativeQrisCreationAllowed($payment)) {
                    throw new \RuntimeException('Order is no longer pending payment.');
                }

                if ($this->hasPayablePersistedQris($payment)) {
                    return $this->transactionFromPayment($payment);
                }

                if ($payment->provider_create_state !== null || $this->hasPersistedQrisPayload($payment)) {
                    $this->recoverCreationOutcome($payment, $hardened);
                    $payment->refresh();
                    if ($this->hasPayablePersistedQris($payment)) {
                        return $this->transactionFromPayment($payment);
                    }

                    if ($payment->provider_create_state === 'provider_expired') {
                        throw new \RuntimeException('Pakasir native QRIS has expired.');
                    }

                    throw new \RuntimeException('Provider belum memberi nominal final QRIS. Coba lagi dari invoice ini sebentar lagi.');
                }

                if (! $this->markCreationAttempted($payment, $hardened)) {
                    $payment->refresh();

                    if ($this->hasPayablePersistedQris($payment)) {
                        return $this->transactionFromPayment($payment);
                    }

                    if ($hardened && ! $this->lifecycleCoordinator->isNativeQrisCreationAllowed($payment)) {
                        throw new \RuntimeException('Order is no longer pending payment.');
                    }

                    if ($payment->provider_create_state !== null || $this->hasPersistedQrisPayload($payment)) {
                        $this->recoverCreationOutcome($payment, $hardened);
                        $payment->refresh();

                        if ($this->hasPayablePersistedQris($payment)) {
                            return $this->transactionFromPayment($payment);
                        }

                        if ($payment->provider_create_state === 'provider_expired') {
                            throw new \RuntimeException('Pakasir native QRIS has expired.');
                        }
                    }

                    throw new \RuntimeException('Pakasir native QRIS creation is already in progress.');
                }

                try {
                    $transaction = $this->pakasir->createQrisTransaction($payment);
                    $transaction->assertMatches($payment, (string) config('services.pakasir.slug'), PakasirQrisFeePolicy::fromConfig());
                } catch (\Throwable $exception) {
                    $this->markCreationFailure($payment, $exception);

                    throw new \RuntimeException('QRIS sedang dipulihkan. Jangan membuat checkout baru; buka invoice ini lagi sebentar.', 0, $exception);
                }

                $transaction = $this->persistCreatedTransaction($payment, $transaction, $hardened);
                if ($transaction === null) {
                    $this->markCreationFailure($payment, new \RuntimeException('Order is no longer pending payment.'));

                    throw new \RuntimeException('Order is no longer pending payment.');
                }

                if (! $transaction->hasValidFinalAmount(PakasirQrisFeePolicy::fromConfig())) {
                    throw new \RuntimeException('Provider belum memberi nominal final QRIS. Coba lagi dari invoice ini sebentar lagi.');
                }

                $payment->refresh();
                if (! $this->hasPayablePersistedQris($payment)) {
                    throw new \RuntimeException('Pakasir native QRIS has expired.');
                }

                return $transaction;
            });
    }

    private function persistCreatedTransaction(Payment $payment, PakasirQrisTransaction $transaction, bool $hardened): ?PakasirQrisTransaction
    {
        return $this->lifecycleCoordinator->withLockedOrderPayment($payment, function (Order $order, Payment $lockedPayment) use ($payment, $transaction, $hardened): ?PakasirQrisTransaction {
            if ($hardened && ! $this->lifecycleCoordinator->canCreateNativeQris($order, $lockedPayment)) {
                return null;
            }

            if ($this->hasPayablePersistedQris($lockedPayment)) {
                return $this->transactionFromPayment($lockedPayment);
            }

            $providerExpiresAt = $transaction->expiresAt()?->setTimezone((string) config('app.timezone'));
            $providerTotalPayment = $transaction->totalPayment();
            $hasValidFinalAmount = $transaction->hasValidFinalAmount(PakasirQrisFeePolicy::fromConfig());
            $providerExpired = $providerExpiresAt !== null && ! $providerExpiresAt->isFuture();
            $paymentUpdates = [
                'provider_payload' => $transaction->sanitizedPayload(),
                'provider_fee' => $transaction->fee() ?? ($providerTotalPayment !== null ? max(0, $providerTotalPayment - $lockedPayment->amount) : null),
                'provider_total_payment' => $providerTotalPayment,
                'provider_payment_method' => $transaction->paymentMethod(),
                'provider_created_at' => now(),
                'provider_expires_at' => $providerExpiresAt,
                'provider_payment_number' => $transaction->paymentNumber(),
                'provider_payment_number_hash' => hash('sha256', $transaction->paymentNumber()),
                'provider_create_state' => $providerExpired ? 'provider_expired' : ($hasValidFinalAmount ? 'created' : 'provider_total_pending'),
                'provider_create_last_error' => $providerExpired ? 'provider_expired' : ($hasValidFinalAmount ? null : 'missing_provider_total_payment'),
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

    private function hasPersistedQrisPayload(Payment $payment): bool
    {
        return $payment->provider_payment_method === 'qris' && filled($payment->provider_payment_number);
    }

    private function hasPayablePersistedQris(Payment $payment): bool
    {
        return $payment->isPayableNativePakasirQris();
    }

    private function markCreationAttempted(Payment $payment, bool $hardened): bool
    {
        $reserved = $this->lifecycleCoordinator->withLockedOrderPayment($payment, function (Order $order, Payment $lockedPayment) use ($hardened): bool {
            if (($hardened && ! $this->lifecycleCoordinator->canCreateNativeQris($order, $lockedPayment))
                || $this->hasPayablePersistedQris($lockedPayment)
                || $lockedPayment->provider_create_state !== null
                || $this->hasPersistedQrisPayload($lockedPayment)) {
                return false;
            }

            $lockedPayment->update([
                'provider_create_state' => 'creating',
                'provider_create_attempted_at' => now(),
            ]);

            return true;
        });

        if ($reserved) {
            $payment->refresh();
        }

        return $reserved;
    }

    private function markCreationFailure(Payment $payment, \Throwable $exception): void
    {
        $integrityFailure = $exception instanceof \UnexpectedValueException;
        $this->lifecycleCoordinator->withLockedOrderPayment($payment, function (Order $order, Payment $lockedPayment) use ($integrityFailure, $exception): void {
            if ($lockedPayment->provider_payment_number_hash !== null) {
                return;
            }

            $lockedPayment->update([
                'provider_create_state' => $integrityFailure ? 'financial_integrity_failed' : 'outcome_unknown',
                'provider_create_last_error' => substr($integrityFailure ? $exception->getMessage() : $exception::class, 0, 500),
            ]);
        });

        $payment->refresh();
    }

    private function recoverCreationOutcome(Payment $payment, bool $hardened): void
    {
        try {
            $transaction = PakasirQrisTransaction::fromPayload($this->pakasir->transactionDetail($payment));
            $transaction->assertMatches($payment, (string) config('services.pakasir.slug'), PakasirQrisFeePolicy::fromConfig());
            if ($this->persistCreatedTransaction($payment, $transaction, $hardened) === null) {
                $this->markCreationFailure($payment, new \RuntimeException('Order is no longer pending payment.'));
            }
        } catch (\Throwable $exception) {
            $this->markCreationFailure($payment, $exception);
        }
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
        $transaction->assertMatches($payment, (string) config('services.pakasir.slug'), PakasirQrisFeePolicy::fromConfig());

        return $transaction;
    }
}
