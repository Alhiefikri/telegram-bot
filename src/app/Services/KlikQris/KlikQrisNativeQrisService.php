<?php

namespace App\Services\KlikQris;

use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Support\PaymentProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Central KlikQRIS Native QRIS lifecycle.
 *
 * One Payment lifecycle owns dynamic QR create, recovery, persistence, expiry
 * tightening, payability evaluation, and safe outcome classification. Callers
 * only ever receive KlikQrisNativeQrisLifecycleResult outcomes; raw provider
 * payloads and persistence-state strings stay internal.
 */
class KlikQrisNativeQrisService
{
    public function __construct(
        private readonly KlikQrisProviderAdapter $provider,
        private readonly KlikQrisPaymentEventLogger $events,
    ) {}

    public function lifecycle(Payment $payment): KlikQrisNativeQrisLifecycleResult
    {
        $payment->refresh();

        if ($this->hasReusablePersistedQris($payment)) {
            return $this->payableResult($payment);
        }

        if ($this->hasNonRecoverableState($payment)) {
            return $this->classifyPersisted($payment);
        }

        try {
            return Cache::lock('klikqris:native-qris:create:'.$payment->getKey(), 30)
                ->block(10, fn (): KlikQrisNativeQrisLifecycleResult => $this->createOrRecover($payment));
        } catch (LockTimeoutException) {
            // Another attempt holds the single-flight lock; the QR is still being prepared.
            return KlikQrisNativeQrisLifecycleResult::state(KlikQrisNativeQrisOutcome::Creating);
        }
    }

    private function createOrRecover(Payment $payment): KlikQrisNativeQrisLifecycleResult
    {
        $payment->refresh();

        if ($this->hasReusablePersistedQris($payment)) {
            return $this->payableResult($payment);
        }

        if ($payment->provider_create_state !== null || $this->hasPersistedQrisPayload($payment)) {
            if ($payment->provider_create_state === 'create_failed' && ! $this->hasPersistedQrisPayload($payment)) {
                $this->resetPreRequestFailure($payment);
                $payment->refresh();
            } else {
                return $this->recoverCreationOutcome($payment);
            }
        }

        $this->markCreationAttempted($payment);

        try {
            $transaction = $this->provider->createTransaction($payment);
            $transaction->assertMatches($payment);
        } catch (KlikQrisCreatePreRequestException $exception) {
            // The create request was never sent; the caller may retry after configuration recovers.
            $this->markCreationDefinitivelyFailed($payment, $exception);

            throw $exception;
        } catch (Throwable $exception) {
            // The create may or may not have reached the provider; recover on the same Payment.
            $this->markCreationOutcomeUnknown($payment, $exception);

            return KlikQrisNativeQrisLifecycleResult::state(KlikQrisNativeQrisOutcome::RecoveryPending);
        }

        $this->persistCreatedTransaction($payment, $transaction);

        return $this->postWorkResult($payment);
    }

    private function recoverCreationOutcome(Payment $payment): KlikQrisNativeQrisLifecycleResult
    {
        try {
            $transaction = $this->provider->transactionStatus($payment);
            $transaction->assertMatches($payment);
            $this->persistCreatedTransaction($payment, $transaction);
        } catch (Throwable $exception) {
            $this->markRecoveryUnconfirmed($payment, $exception);
            $payment->refresh();

            return KlikQrisNativeQrisLifecycleResult::state(KlikQrisNativeQrisOutcome::RecoveryPending);
        }

        return $this->postWorkResult($payment);
    }

    private function postWorkResult(Payment $payment): KlikQrisNativeQrisLifecycleResult
    {
        $payment->refresh();

        if ($this->hasReusablePersistedQris($payment)) {
            return $this->payableResult($payment);
        }

        return $this->classifyPersisted($payment);
    }

    /**
     * Classify a persisted Payment without performing provider work.
     *
     * Pending payments still in a creating/recovery window are classified too,
     * but lifecycle() only returns this classification directly when provider
     * work cannot advance the Payment (payload reuse, terminal or expired
     * deadlines). Create and recovery attempts always run through the explicit
     * createOrRecover() path.
     */
    private function classifyPersisted(Payment $payment): KlikQrisNativeQrisLifecycleResult
    {
        try {
            return KlikQrisNativeQrisLifecycleResult::payableResult(
                KlikQrisPayableNativeQris::fromPayment($payment),
            );
        } catch (RuntimeException) {
            // Not payable; classify from persistence below.
        }

        if ($payment->status === PaymentStatus::Expired) {
            return KlikQrisNativeQrisLifecycleResult::state(KlikQrisNativeQrisOutcome::Expired);
        }

        if ($payment->status !== PaymentStatus::Pending) {
            return KlikQrisNativeQrisLifecycleResult::state(KlikQrisNativeQrisOutcome::InvalidProviderResult);
        }

        if ($this->hasNonRecoverableState($payment)) {
            return KlikQrisNativeQrisLifecycleResult::state(KlikQrisNativeQrisOutcome::Expired);
        }

        return $this->classifyRecoverableState($payment);
    }

    private function classifyRecoverableState(Payment $payment): KlikQrisNativeQrisLifecycleResult
    {
        $outcome = match ($payment->provider_create_state) {
            'outcome_unknown', 'recovery_unconfirmed' => KlikQrisNativeQrisOutcome::RecoveryPending,
            'transient_qris_image' => KlikQrisNativeQrisOutcome::ProviderTotalPending,
            'provider_total_invalid', 'provider_qris_unusable', 'provider_not_payable', 'provider_SUCCESS', 'created' => KlikQrisNativeQrisOutcome::InvalidProviderResult,
            'provider_expired', 'provider_EXPIRED' => KlikQrisNativeQrisOutcome::Expired,
            default => KlikQrisNativeQrisOutcome::InvalidProviderResult,
        };

        return KlikQrisNativeQrisLifecycleResult::state($outcome);
    }

    private function hasNonRecoverableState(Payment $payment): bool
    {
        if ($payment->status !== PaymentStatus::Pending) {
            return true;
        }

        if ($payment->provider_expires_at !== null && ! $payment->provider_expires_at->isFuture()) {
            return true;
        }

        if ($payment->expired_at !== null && $payment->expired_at->isPast()) {
            return true;
        }

        return $payment->order?->expires_at !== null && $payment->order->expires_at->isPast();
    }

    private function payableResult(Payment $payment): KlikQrisNativeQrisLifecycleResult
    {
        try {
            $payable = KlikQrisPayableNativeQris::fromPayment($payment);
        } catch (RuntimeException) {
            return $this->classifyPersisted($payment);
        }

        return KlikQrisNativeQrisLifecycleResult::payableResult($payable);
    }

    private function persistCreatedTransaction(Payment $payment, KlikQrisTransaction $transaction): void
    {
        DB::transaction(function () use ($payment, $transaction): void {
            $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->first();
            $lockedPayment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($this->hasPayablePersistedQris($lockedPayment)) {
                return;
            }

            $providerExpiresAt = $transaction->expiresAt()?->setTimezone((string) config('app.timezone'));
            $providerTotal = $transaction->totalAmount();
            $providerPayload = $transaction->sanitizedPayload();
            if (filled($transaction->qrisUrl()) && ! KlikQrisTransaction::isAllowedQrisUrl($transaction->qrisUrl(), environment: $transaction->environment())) {
                // Never persist untrusted provider media references.
                $providerPayload = $this->withoutQrisUrlKeys($providerPayload);
            }
            $isPayableCreated = $this->isPayableProviderTransaction($transaction, $lockedPayment, $providerExpiresAt);
            $nonPayableState = $this->nonPayableState($transaction, $lockedPayment, $providerExpiresAt);

            // Untrusted, missing, or invalid provider QR data is persisted as a
            // non-payable state; it must never become Payable later.
            $paymentUpdates = [
                'provider_payload' => $providerPayload,
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

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    private function withoutQrisUrlKeys(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if ($key === 'qris_url') {
                unset($payload[$key]);

                continue;
            }

            if (is_array($value)) {
                $payload[$key] = $this->withoutQrisUrlKeys($value);
            }
        }

        return $payload;
    }

    private function hasProviderCreateEvent(Payment $payment): bool
    {
        return $payment->paymentEvents()
            ->where('provider', PaymentProvider::KLIKQRIS)
            ->where('event_type', PaymentEventType::ProviderCreate->value)
            ->exists();
    }
}
