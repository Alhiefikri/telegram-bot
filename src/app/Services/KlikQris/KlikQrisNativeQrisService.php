<?php

namespace App\Services\KlikQris;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class KlikQrisNativeQrisService
{
    public function __construct(
        private readonly KlikQrisProviderAdapter $provider,
        private readonly KlikQrisPaymentEventLogger $events,
    ) {}

    public function ensureCreated(Payment $payment): KlikQrisTransaction
    {
        $payment->refresh();
        if ($this->hasPayablePersistedQris($payment)) {
            return KlikQrisTransaction::fromPersistedPayment($payment);
        }

        return Cache::lock('klikqris:native-qris:create:'.$payment->getKey(), 30)
            ->block(10, function () use ($payment): KlikQrisTransaction {
                $payment->refresh();
                if ($this->hasPayablePersistedQris($payment)) {
                    return KlikQrisTransaction::fromPersistedPayment($payment);
                }

                if ($payment->provider_create_state !== null || $this->hasPersistedQrisPayload($payment)) {
                    $this->recoverCreationOutcome($payment);
                    $payment->refresh();
                    if ($this->hasPayablePersistedQris($payment)) {
                        return KlikQrisTransaction::fromPersistedPayment($payment);
                    }

                    throw new RuntimeException('Provider belum memberi nominal final QRIS. Coba lagi dari invoice ini sebentar lagi.');
                }

                $this->markCreationAttempted($payment);

                try {
                    $transaction = $this->provider->createTransaction($payment);
                    $transaction->assertMatches($payment);
                } catch (Throwable $exception) {
                    if ($this->isDefinitiveCreateFailure($exception)) {
                        $this->markCreationDefinitivelyFailed($payment, $exception);

                        throw $exception;
                    }

                    $this->markCreationOutcomeUnknown($payment, $exception);

                    throw new RuntimeException('QRIS sedang dipulihkan. Jangan membuat checkout baru; buka invoice ini lagi sebentar.', 0, $exception);
                }

                $transaction = $this->persistCreatedTransaction($payment, $transaction);

                if (! $this->hasPayablePersistedQris($payment->fresh())) {
                    throw new RuntimeException('Provider belum memberi nominal final QRIS. Coba lagi dari invoice ini sebentar lagi.');
                }

                return $transaction;
            });
    }

    private function persistCreatedTransaction(Payment $payment, KlikQrisTransaction $transaction): KlikQrisTransaction
    {
        return DB::transaction(function () use ($payment, $transaction): KlikQrisTransaction {
            $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->first();
            $lockedPayment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($this->hasPayablePersistedQris($lockedPayment)) {
                return KlikQrisTransaction::fromPersistedPayment($lockedPayment);
            }

            $providerExpiresAt = $transaction->expiresAt()?->setTimezone((string) config('app.timezone'));
            $providerTotal = $transaction->totalAmount();
            $isValidFinalAmount = $providerTotal >= (int) $lockedPayment->amount;
            $paymentUpdates = [
                'provider_payload' => $transaction->sanitizedPayload(),
                'provider_fee' => $isValidFinalAmount ? $providerTotal - (int) $lockedPayment->amount : null,
                'provider_total_payment' => $providerTotal,
                'provider_payment_method' => 'qris',
                'provider_created_at' => now(),
                'provider_expires_at' => $providerExpiresAt,
                'provider_transaction_token' => $transaction->signature(),
                'provider_create_state' => $isValidFinalAmount ? 'created' : 'provider_total_invalid',
                'provider_create_last_error' => $isValidFinalAmount ? null : 'invalid_provider_total_payment',
            ];

            if ($providerExpiresAt !== null && ($lockedPayment->expired_at === null || $providerExpiresAt->lt($lockedPayment->expired_at))) {
                $paymentUpdates['expired_at'] = $providerExpiresAt;
            }

            $lockedPayment->update($paymentUpdates);

            if ($providerExpiresAt !== null && $order && ($order->expires_at === null || $providerExpiresAt->lt($order->expires_at))) {
                $order->update(['expires_at' => $providerExpiresAt]);
            }

            $payment->refresh();
            if ($isValidFinalAmount) {
                $this->events->logProviderCreate($payment, $transaction);
            }

            return $transaction;
        });
    }

    private function markCreationAttempted(Payment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($this->hasPayablePersistedQris($locked) || $locked->provider_create_state !== null) {
                return;
            }

            $locked->update([
                'provider_create_state' => 'creating',
                'provider_create_attempted_at' => now(),
            ]);
        });

        $payment->refresh();
    }

    private function markCreationOutcomeUnknown(Payment $payment, Throwable $exception): void
    {
        $providerException = $exception->getPrevious() ?? $exception;

        Payment::query()
            ->whereKey($payment->id)
            ->update([
                'provider_create_state' => 'outcome_unknown',
                'provider_create_last_error' => substr($providerException::class, 0, 500),
            ]);
    }

    private function markCreationDefinitivelyFailed(Payment $payment, Throwable $exception): void
    {
        $providerException = $exception->getPrevious() ?? $exception;

        Payment::query()
            ->whereKey($payment->id)
            ->update([
                'provider_create_state' => 'create_failed',
                'provider_create_last_error' => substr($providerException::class, 0, 500),
            ]);
    }

    private function isDefinitiveCreateFailure(Throwable $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'sandbox is not ready')
            || str_contains($message, 'response validation failed')
            || str_contains($message, 'response parse failed')
            || str_contains($message, 'HTTP 400')
            || str_contains($message, 'HTTP 401')
            || str_contains($message, 'HTTP 403')
            || str_contains($message, 'HTTP 404')
            || str_contains($message, 'HTTP 422');
    }

    private function recoverCreationOutcome(Payment $payment): void
    {
        try {
            $transaction = $this->provider->transactionStatus($payment);
            $transaction->assertMatches($payment);
            $this->persistCreatedTransaction($payment, $transaction);
        } catch (Throwable $exception) {
            $this->markCreationOutcomeUnknown($payment, $exception);
        }
    }

    private function hasPersistedQrisPayload(Payment $payment): bool
    {
        return $payment->provider_payment_method === 'qris' && is_array($payment->provider_payload);
    }

    private function hasPayablePersistedQris(Payment $payment): bool
    {
        return $payment->isPayableNativeKlikQris();
    }
}
