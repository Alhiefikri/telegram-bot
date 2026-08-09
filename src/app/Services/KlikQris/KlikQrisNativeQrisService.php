<?php

namespace App\Services\KlikQris;

use App\Enums\KlikQrisEnvironment;
use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
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
        if ($this->hasReusablePersistedQris($payment)) {
            return KlikQrisTransaction::fromPersistedPayment($payment, KlikQrisEnvironment::runtime());
        }

        return Cache::lock('klikqris:native-qris:create:'.$payment->getKey(), 30)
            ->block(10, function () use ($payment): KlikQrisTransaction {
                $payment->refresh();
                if ($this->hasReusablePersistedQris($payment)) {
                    return KlikQrisTransaction::fromPersistedPayment($payment, KlikQrisEnvironment::runtime());
                }

                if ($payment->provider_create_state !== null || $this->hasPersistedQrisPayload($payment)) {
                    if ($payment->provider_create_state === 'create_failed' && ! $this->hasPersistedQrisPayload($payment)) {
                        $this->resetPreRequestFailure($payment);
                        $payment->refresh();
                    } else {
                        $this->recoverCreationOutcome($payment);
                        $payment->refresh();
                        if ($this->hasReusablePersistedQris($payment)) {
                            return KlikQrisTransaction::fromPersistedPayment($payment, KlikQrisEnvironment::runtime());
                        }

                        throw new RuntimeException('Provider belum memberi nominal final QRIS. Coba lagi dari invoice ini sebentar lagi.');
                    }
                }

                $this->markCreationAttempted($payment);

                try {
                    $transaction = $this->provider->createTransaction($payment);
                    $transaction->assertMatches($payment);
                } catch (Throwable $exception) {
                    if ($exception instanceof KlikQrisCreatePreRequestException) {
                        $this->markCreationDefinitivelyFailed($payment, $exception);

                        throw $exception;
                    }

                    $this->markCreationOutcomeUnknown($payment, $exception);

                    throw new RuntimeException('QRIS sedang dipulihkan. Jangan membuat checkout baru; buka invoice ini lagi sebentar.', 0, $exception);
                }

                $transaction = $this->persistCreatedTransaction($payment, $transaction);

                if (! $this->hasPayablePersistedQris($payment->fresh())) {
                    if ($this->hasTransientInitialImage($transaction)) {
                        return $transaction;
                    }

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
                return KlikQrisTransaction::fromPersistedPayment($lockedPayment, KlikQrisEnvironment::runtime());
            }

            $providerExpiresAt = $transaction->expiresAt()?->setTimezone((string) config('app.timezone'));
            $providerTotal = $transaction->totalAmount();
            if (filled($transaction->qrisUrl()) && ! KlikQrisTransaction::isAllowedQrisUrl($transaction->qrisUrl(), environment: $transaction->environment())) {
                throw new RuntimeException('KlikQRIS provider returned an untrusted QRIS URL.');
            }

            $isPayableCreated = $this->isPayableProviderTransaction($transaction, $lockedPayment, $providerExpiresAt);
            $nonPayableState = $this->nonPayableState($transaction, $lockedPayment, $providerExpiresAt);
            $paymentUpdates = [
                'provider_payload' => $transaction->sanitizedPayload(),
                'provider_fee' => $providerTotal >= (int) $lockedPayment->amount ? $providerTotal - (int) $lockedPayment->amount : null,
                'provider_total_payment' => $providerTotal,
                'provider_payment_method' => 'qris',
                'provider_created_at' => now(),
                'provider_expires_at' => $providerExpiresAt,
                'provider_create_state' => $isPayableCreated ? 'created' : $nonPayableState,
                'provider_create_last_error' => $isPayableCreated ? null : $nonPayableState,
            ];

            if ($providerExpiresAt !== null && ($lockedPayment->expired_at === null || $providerExpiresAt->lt($lockedPayment->expired_at))) {
                $paymentUpdates['expired_at'] = $providerExpiresAt;
            }

            $lockedPayment->update($paymentUpdates);

            if ($providerExpiresAt !== null && $order && ($order->expires_at === null || $providerExpiresAt->lt($order->expires_at))) {
                $order->update(['expires_at' => $providerExpiresAt]);
            }

            $payment->refresh();
            if ($isPayableCreated && ! $this->hasProviderCreateEvent($lockedPayment)) {
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

        DB::transaction(function () use ($payment, $providerException): void {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($this->hasPayablePersistedQris($locked)) {
                return;
            }

            $locked->update([
                'provider_create_state' => 'outcome_unknown',
                'provider_create_last_error' => substr($providerException::class, 0, 500),
            ]);
        });
    }

    private function markCreationDefinitivelyFailed(Payment $payment, Throwable $exception): void
    {
        $providerException = $exception->getPrevious() ?? $exception;

        DB::transaction(function () use ($payment, $providerException): void {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($this->hasPayablePersistedQris($locked)) {
                return;
            }

            $locked->update([
                'provider_create_state' => 'create_failed',
                'provider_create_last_error' => substr($providerException::class, 0, 500),
            ]);
        });
    }

    private function resetPreRequestFailure(Payment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($this->hasPayablePersistedQris($locked) || $locked->provider_create_state !== 'create_failed') {
                return;
            }

            $locked->update([
                'provider_create_state' => null,
                'provider_create_last_error' => null,
            ]);
        });
    }

    private function recoverCreationOutcome(Payment $payment): void
    {
        try {
            $transaction = $this->provider->transactionStatus($payment);
            $transaction->assertMatches($payment);
            $this->persistCreatedTransaction($payment, $transaction);
        } catch (Throwable $exception) {
            $this->markRecoveryUnconfirmed($payment, $exception);
        }
    }

    private function markRecoveryUnconfirmed(Payment $payment, Throwable $exception): void
    {
        $providerException = $exception->getPrevious() ?? $exception;

        DB::transaction(function () use ($payment, $providerException): void {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($this->hasPayablePersistedQris($locked)) {
                return;
            }

            $locked->update([
                'provider_create_state' => 'recovery_unconfirmed',
                'provider_create_last_error' => substr($providerException::class, 0, 500),
            ]);
        });
    }

    private function hasPersistedQrisPayload(Payment $payment): bool
    {
        return $payment->provider_payment_method === 'qris' && is_array($payment->provider_payload);
    }

    private function hasReusablePersistedQris(Payment $payment): bool
    {
        return $this->hasPayablePersistedQris($payment)
            || ($payment->status === PaymentStatus::Paid
                && $this->hasPersistedQrisPayload($payment)
                && $payment->isNativeKlikQris()
                && $payment->provider_total_payment !== null
                && $payment->provider_expires_at !== null
            );
    }

    private function hasPayablePersistedQris(Payment $payment): bool
    {
        return $payment->isPayableNativeKlikQris();
    }

    private function isPayableProviderTransaction(KlikQrisTransaction $transaction, Payment $payment, mixed $providerExpiresAt): bool
    {
        return $transaction->isPending()
            && $transaction->totalAmount() >= (int) $payment->amount
            && filled($transaction->qrisUrl())
            && KlikQrisTransaction::isAllowedQrisUrl($transaction->qrisUrl(), environment: $transaction->environment())
            && $providerExpiresAt !== null
            && $providerExpiresAt->isFuture();
    }

    private function nonPayableState(KlikQrisTransaction $transaction, Payment $payment, mixed $providerExpiresAt): string
    {
        if (! $transaction->isPending()) {
            return 'provider_'.$transaction->status();
        }

        if ($transaction->totalAmount() < (int) $payment->amount) {
            return 'provider_total_invalid';
        }

        if (! filled($transaction->qrisUrl()) || ! KlikQrisTransaction::isAllowedQrisUrl($transaction->qrisUrl(), environment: $transaction->environment())) {
            if ($this->hasTransientInitialImage($transaction)) {
                return 'transient_qris_image';
            }

            return 'provider_qris_unusable';
        }

        if ($providerExpiresAt === null || ! $providerExpiresAt->isFuture()) {
            return 'provider_expired';
        }

        return 'provider_not_payable';
    }

    private function hasTransientInitialImage(KlikQrisTransaction $transaction): bool
    {
        return $transaction->isPending()
            && ! filled($transaction->qrisUrl())
            && filled($transaction->qrisImageBase64())
            && $transaction->expiresAt()?->isFuture() === true;
    }

    private function hasProviderCreateEvent(Payment $payment): bool
    {
        return $payment->paymentEvents()
            ->where('provider', 'klikqris')
            ->where('event_type', PaymentEventType::ProviderCreate->value)
            ->exists();
    }
}
