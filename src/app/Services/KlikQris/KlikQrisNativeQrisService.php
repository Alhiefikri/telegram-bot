<?php

namespace App\Services\KlikQris;

use App\Enums\KlikQrisEnvironment;
use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Services\PaymentLifecycleCoordinator;
use App\Services\PaymentLifecycleRollout;
use App\Services\ProviderVerification\ProviderVerificationProfiles;
use App\Support\PaymentProvider;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
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
    private const PAYMENT_LOCK_WAIT_SECONDS = 10;

    private const OWNER_ALERT_TERMINAL_STATE = 'terminal';

    public function __construct(
        private readonly KlikQrisProviderAdapter $provider,
        private readonly KlikQrisPaymentEventLogger $events,
        private readonly PaymentLifecycleCoordinator $lifecycleCoordinator,
        private readonly PaymentLifecycleRollout $rollout,
    ) {}

    /**
     * Classify the persisted Payment without performing any provider work.
     *
     * Read-only surfaces (invoice rendering, status reads) use this to present
     * exactly the same safe outcomes lifecycle() returns, so they never classify
     * provider payloads or persistence-state strings themselves. Only lifecycle()
     * may create or recover a QR.
     */
    public function classify(Payment $payment): KlikQrisNativeQrisLifecycleResult
    {
        $payment->refresh();

        if ($this->usesHardenedPath($payment)
            && ! $this->lifecycleCoordinator->isNativeQrisExposureAllowed($payment)) {
            return KlikQrisNativeQrisLifecycleResult::state(KlikQrisNativeQrisOutcome::Expired);
        }

        if ($this->hasReusablePersistedQris($payment)) {
            return $this->payableResult($payment);
        }

        return $this->classifyPersisted($payment);
    }

    /**
     * Customer status refresh may recover a request whose create outcome is
     * uncertain, but it must never turn a fresh, not-yet-submitted Payment into
     * a provider status lookup or a second create attempt.
     */
    public function isCustomerStatusRefreshable(Payment $payment): bool
    {
        if (strtolower(trim((string) $payment->provider)) !== PaymentProvider::KLIKQRIS
            || ! in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Expired, PaymentStatus::Failed], true)
            || blank($payment->merchant_ref)) {
            return false;
        }

        $state = trim((string) $payment->provider_create_state);

        return ($state !== '' && $state !== 'create_failed')
            || ($payment->provider_payment_method === 'qris' && is_array($payment->provider_payload));
    }

    /**
     * Observe the current lifecycle state and return an owner alert only when
     * the state is actionable and its retry policy has been met. This stays in
     * the lifecycle module so customer-facing callers never inspect Payment
     * persistence fields or maintain a second retry counter.
     */
    public function ownerAlert(Payment $payment): ?KlikQrisOwnerAlert
    {
        $alert = $this->tryWithPaymentLock($payment, function () use ($payment): ?KlikQrisOwnerAlert {
            $payment->refresh();

            $outcome = $payment->status === PaymentStatus::Pending
                ? $this->classifyPersisted($payment)->outcome()
                : null;
            $state = $outcome?->value ?? self::OWNER_ALERT_TERMINAL_STATE;
            $observation = $this->events->observeOwnerAlert($payment, $state);

            if ($outcome === null
                || ! $this->isOwnerActionableOutcome($outcome)
                || $observation['alreadyAlerted']
                || ($outcome === KlikQrisNativeQrisOutcome::RecoveryPending
                    && $observation['observations'] < $this->ownerAlertRetryBudget())) {
                return null;
            }

            return new KlikQrisOwnerAlert(
                $outcome,
                $observation['observations'],
                $this->ownerAlertRetryBudget(),
                $this->recoveryDiagnostic($payment->provider_create_last_error),
            );
        });

        return $alert instanceof KlikQrisOwnerAlert ? $alert : null;
    }

    /**
     * Deliver an owner alert while holding the same Payment lock as lifecycle,
     * callback, and reconciliation work. The delivery marker is written only
     * after the callback confirms a successful Telegram response.
     *
     * @param  Closure(): void  $deliver
     */
    public function deliverOwnerAlert(Payment $payment, KlikQrisOwnerAlert $alert, Closure $deliver): bool
    {
        $lock = Cache::lock($this->paymentLockKey($payment), $this->paymentLockLeaseSeconds());
        if (! $lock->get()) {
            return false;
        }

        try {
            $payment->refresh();
            if ($payment->status !== PaymentStatus::Pending
                || $this->classifyPersisted($payment)->outcome() !== $alert->outcome()
                || ! $this->events->ownerAlertNeedsDelivery($payment, $alert->outcome()->value, $alert->observations())) {
                return false;
            }

            $deliver();

            return $this->events->markOwnerAlertDelivered(
                $payment,
                $alert->outcome()->value,
                $alert->observations(),
            );
        } finally {
            $lock->release();
        }
    }

    public function lifecycle(Payment $payment): KlikQrisNativeQrisLifecycleResult
    {
        $payment->refresh();
        $hardened = $this->usesHardenedPath($payment);

        if ($hardened && ! $this->lifecycleCoordinator->isNativeQrisExposureAllowed($payment)) {
            return KlikQrisNativeQrisLifecycleResult::state(KlikQrisNativeQrisOutcome::Expired);
        }

        if ($this->hasReusablePersistedQris($payment)) {
            return $this->payableResult($payment);
        }

        if ($this->hasNonRecoverableState($payment)) {
            return $this->classifyPersisted($payment);
        }

        try {
            return Cache::lock($this->paymentLockKey($payment), $this->paymentLockLeaseSeconds())
                ->block(self::PAYMENT_LOCK_WAIT_SECONDS, fn (): KlikQrisNativeQrisLifecycleResult => $this->createOrRecover($payment, $hardened));
        } catch (LockTimeoutException) {
            // Another attempt holds the single-flight lock; the QR is still being prepared.
            return KlikQrisNativeQrisLifecycleResult::state(KlikQrisNativeQrisOutcome::Creating);
        }
    }

    /**
     * Recover a provider-supplied QR image for a payable Payment when its
     * trusted URL cannot be rendered. The image stays transient and is never
     * added to the persisted provider payload.
     */
    public function recoverPresentationImage(Payment $payment): ?KlikQrisPayableNativeQris
    {
        $result = $this->tryWithPaymentLock($payment, function () use ($payment): ?KlikQrisPayableNativeQris {
            $payment->refresh();
            if (! $this->hasReusablePersistedQris($payment)) {
                return null;
            }

            try {
                $transaction = $this->provider->transactionStatus($payment);
                $transaction->assertMatches($payment);
                if (! $transaction->isPending() || blank($transaction->qrisImageBase64())) {
                    return null;
                }

                $payment->refresh();

                $payable = KlikQrisPayableNativeQris::fromPaymentWithTransientImage(
                    $payment,
                    $transaction->qrisImageBase64(),
                );

                return $payable->fallbackImageContents() === null ? null : $payable;
            } catch (Throwable) {
                return null;
            }
        });

        return $result instanceof KlikQrisPayableNativeQris ? $result : null;
    }

    /**
     * Reconcile one Payment while holding the same lock used by customer
     * refreshes and callback verification.
     *
     * The callback performs authoritative paid-status handling after this
     * module has recovered QR state.
     *
     * @param  Closure(Payment, KlikQrisTransaction): mixed  $onAuthoritativeStatus
     */
    public function reconcile(Payment $payment, Closure $onAuthoritativeStatus): ?KlikQrisNativeQrisLifecycleResult
    {
        $lock = Cache::lock($this->paymentLockKey($payment), $this->paymentLockLeaseSeconds());
        if (! $lock->get()) {
            return null;
        }

        try {
            $payment->refresh();

            if ($payment->status !== PaymentStatus::Pending || $this->hasNonRecoverableState($payment)) {
                $transaction = $this->provider->transactionStatus($payment);
                $transaction->assertMatches($payment);
                $onAuthoritativeStatus($payment, $transaction);

                return $this->classifyPersisted($payment->fresh());
            }

            $work = $this->createOrRecoverWithTransaction(
                $payment,
                throwRecoveryFailure: true,
                hardened: $this->usesHardenedPath($payment),
            );
            if ($work['authoritativeTransaction'] !== null) {
                $onAuthoritativeStatus($payment, $work['authoritativeTransaction']);

                return $work['result'];
            }

            if (in_array($work['result']->outcome(), [
                KlikQrisNativeQrisOutcome::Creating,
                KlikQrisNativeQrisOutcome::RecoveryPending,
            ], true)) {
                return $work['result'];
            }

            $transaction = $this->provider->transactionStatus($payment);
            $transaction->assertMatches($payment);
            $onAuthoritativeStatus($payment, $transaction);

            return $work['result'];
        } finally {
            $lock->release();
        }
    }

    /**
     * Reconcile a Payment which entered its uncertain create state before the
     * rollout switch. This never sends a create request, and intentionally
     * remains hardened in rollback so existing in-flight money is recoverable.
     *
     * @param  Closure(Payment, KlikQrisTransaction): mixed  $onAuthoritativeStatus
     */
    public function reconcileInFlight(Payment $payment, Closure $onAuthoritativeStatus): ?KlikQrisNativeQrisLifecycleResult
    {
        $lock = Cache::lock($this->paymentLockKey($payment), $this->paymentLockLeaseSeconds());
        if (! $lock->get()) {
            return null;
        }

        try {
            $payment->refresh();
            if (! in_array($payment->provider_create_state, ['creating', 'outcome_unknown'], true)) {
                return null;
            }

            if ($payment->status !== PaymentStatus::Pending || $this->hasNonRecoverableState($payment)) {
                $transaction = $this->provider->transactionStatus($payment);
                $transaction->assertMatches($payment);
                $onAuthoritativeStatus($payment, $transaction);

                return $this->classifyPersisted($payment->fresh());
            }

            $work = $this->createOrRecoverWithTransaction($payment, throwRecoveryFailure: true, hardened: true);
            if ($work['authoritativeTransaction'] !== null) {
                $onAuthoritativeStatus($payment, $work['authoritativeTransaction']);
            }

            return $work['result'];
        } finally {
            $lock->release();
        }
    }

    /**
     * Persist signature evidence returned by the authoritative status endpoint.
     */
    public function persistAuthoritativeTransactionSignature(Payment $payment, KlikQrisTransaction $transaction): bool
    {
        return $this->lifecycleCoordinator->withLockedOrderPayment(
            $payment,
            fn (Order $order, Payment $lockedPayment): bool => $this->persistTransactionSignature($lockedPayment, $transaction),
        );
    }

    /**
     * Execute a status or persistence operation without waiting for another
     * recovery attempt to finish. A null return means this Payment is busy.
     */
    public function tryWithPaymentLock(Payment $payment, Closure $callback): mixed
    {
        $lock = Cache::lock($this->paymentLockKey($payment), $this->paymentLockLeaseSeconds());
        if (! $lock->get()) {
            return null;
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    public function paymentLockKey(Payment $payment): string
    {
        // Keep the established key so callback/reconciliation workers already
        // holding the old status lock continue to serialize with this module.
        return 'klikqris:callback-status:'.$payment->getKey();
    }

    private function usesHardenedPath(Payment $payment): bool
    {
        return $this->rollout->usesHardenedPath($payment);
    }

    private function paymentLockLeaseSeconds(): int
    {
        // The lease must cover the most aggressive (background) verification
        // budget even when this caller is interactive or a webhook.
        return max(60, ProviderVerificationProfiles::requiredLeaseSeconds());
    }

    private function createOrRecover(Payment $payment, bool $hardened): KlikQrisNativeQrisLifecycleResult
    {
        return $this->createOrRecoverWithTransaction($payment, hardened: $hardened)['result'];
    }

    /**
     * @return array{result: KlikQrisNativeQrisLifecycleResult, authoritativeTransaction: ?KlikQrisTransaction}
     */
    private function createOrRecoverWithTransaction(Payment $payment, bool $throwRecoveryFailure = false, bool $hardened = true): array
    {
        $payment->refresh();

        if ($hardened && ! $this->lifecycleCoordinator->isNativeQrisCreationAllowed($payment)) {
            return [
                'result' => KlikQrisNativeQrisLifecycleResult::state(KlikQrisNativeQrisOutcome::Expired),
                'authoritativeTransaction' => null,
            ];
        }

        if ($this->hasReusablePersistedQris($payment)) {
            return [
                'result' => $this->payableResult($payment),
                'authoritativeTransaction' => null,
            ];
        }

        if ($payment->provider_create_state !== null || $this->hasPersistedQrisPayload($payment)) {
            if ($payment->provider_create_state === 'create_failed' && ! $this->hasPersistedQrisPayload($payment)) {
                $this->resetPreRequestFailure($payment);
                $payment->refresh();
            } else {
                return $this->recoverCreationOutcome($payment, $throwRecoveryFailure, $hardened);
            }
        }

        if (! $this->markCreationAttempted($payment, $hardened)) {
            $payment->refresh();

            if ($this->hasReusablePersistedQris($payment)) {
                return [
                    'result' => $this->payableResult($payment),
                    'authoritativeTransaction' => null,
                ];
            }

            if ($hardened && ! $this->lifecycleCoordinator->isNativeQrisCreationAllowed($payment)) {
                return [
                    'result' => KlikQrisNativeQrisLifecycleResult::state(KlikQrisNativeQrisOutcome::Expired),
                    'authoritativeTransaction' => null,
                ];
            }

            if ($payment->provider_create_state !== null || $this->hasPersistedQrisPayload($payment)) {
                return $this->recoverCreationOutcome($payment, hardened: $hardened);
            }

            return [
                'result' => $this->classifyPersisted($payment),
                'authoritativeTransaction' => null,
            ];
        }

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

            return [
                'result' => KlikQrisNativeQrisLifecycleResult::state(KlikQrisNativeQrisOutcome::RecoveryPending),
                'authoritativeTransaction' => null,
            ];
        }

        if (! $this->persistCreatedTransaction($payment, $transaction, $hardened)) {
            $this->markCreationOutcomeUnknown($payment, new RuntimeException('Order is no longer pending payment.'));

            return [
                'result' => KlikQrisNativeQrisLifecycleResult::state(KlikQrisNativeQrisOutcome::Expired),
                'authoritativeTransaction' => null,
            ];
        }

        return [
            'result' => $this->postWorkResult($payment, $transaction),
            'authoritativeTransaction' => null,
        ];
    }

    /**
     * @return array{result: KlikQrisNativeQrisLifecycleResult, authoritativeTransaction: ?KlikQrisTransaction}
     */
    private function recoverCreationOutcome(Payment $payment, bool $throwFailure = false, bool $hardened = true): array
    {
        try {
            $transaction = $this->provider->transactionStatus($payment);
            $transaction->assertMatches($payment);
            if (! $this->persistCreatedTransaction($payment, $transaction, $hardened)) {
                $this->markCreationOutcomeUnknown($payment, new RuntimeException('Order is no longer pending payment.'));

                return [
                    'result' => KlikQrisNativeQrisLifecycleResult::state(KlikQrisNativeQrisOutcome::Expired),
                    'authoritativeTransaction' => $transaction,
                ];
            }
        } catch (Throwable $exception) {
            $this->markRecoveryUnconfirmed($payment, $exception);
            $payment->refresh();

            if ($throwFailure) {
                throw $exception;
            }

            return [
                'result' => KlikQrisNativeQrisLifecycleResult::state(KlikQrisNativeQrisOutcome::RecoveryPending),
                'authoritativeTransaction' => null,
            ];
        }

        return [
            'result' => $this->postWorkResult($payment, $transaction),
            'authoritativeTransaction' => $transaction,
        ];
    }

    private function postWorkResult(Payment $payment, ?KlikQrisTransaction $transaction = null): KlikQrisNativeQrisLifecycleResult
    {
        $payment->refresh();

        if ($this->hasReusablePersistedQris($payment)) {
            return $this->payableResult($payment, $transaction?->qrisImageBase64());
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
            'transient_qris_image', 'provider_total_pending' => KlikQrisNativeQrisOutcome::ProviderTotalPending,
            'creating', null => KlikQrisNativeQrisOutcome::Creating,
            'provider_total_invalid', 'provider_qris_unusable', 'provider_not_payable', 'provider_SUCCESS', 'created', 'financial_integrity_failed' => KlikQrisNativeQrisOutcome::InvalidProviderResult,
            'provider_expired', 'provider_EXPIRED' => KlikQrisNativeQrisOutcome::Expired,
            default => KlikQrisNativeQrisOutcome::InvalidProviderResult,
        };

        return KlikQrisNativeQrisLifecycleResult::state($outcome);
    }

    private function isOwnerActionableOutcome(KlikQrisNativeQrisOutcome $outcome): bool
    {
        return in_array($outcome, [
            KlikQrisNativeQrisOutcome::RecoveryPending,
            KlikQrisNativeQrisOutcome::InvalidProviderResult,
        ], true);
    }

    private function ownerAlertRetryBudget(): int
    {
        return max(1, (int) config('services.klikqris.recovery_alert_max_attempts', 3));
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

    private function payableResult(Payment $payment, ?string $qrisImageBase64 = null): KlikQrisNativeQrisLifecycleResult
    {
        try {
            $payable = KlikQrisPayableNativeQris::fromPaymentWithTransientImage($payment, $qrisImageBase64);
        } catch (RuntimeException) {
            return $this->classifyPersisted($payment);
        }

        return KlikQrisNativeQrisLifecycleResult::payableResult($payable);
    }

    private function persistCreatedTransaction(Payment $payment, KlikQrisTransaction $transaction, bool $hardened): bool
    {
        return $this->lifecycleCoordinator->withLockedOrderPayment($payment, function (Order $order, Payment $lockedPayment) use ($payment, $transaction, $hardened): bool {
            if ($hardened && ! $this->lifecycleCoordinator->canCreateNativeQris($order, $lockedPayment)) {
                return false;
            }

            if ($this->hasPayablePersistedQris($lockedPayment)) {
                return $this->persistTransactionSignature($lockedPayment, $transaction);
            }

            $providerExpiresAt = $transaction->expiresAt()?->setTimezone((string) config('app.timezone'));
            $providerTotal = $transaction->totalAmount();
            $providerPayload = $transaction->sanitizedPayload();
            $providerQrisUrl = filled($transaction->qrisUrl())
                && KlikQrisTransaction::isAllowedQrisUrl($transaction->qrisUrl(), environment: $transaction->environment())
                ? $transaction->qrisUrl()
                : null;
            if (filled($transaction->qrisUrl()) && $providerQrisUrl === null) {
                // Never persist untrusted provider media references.
                $providerPayload = $this->withoutQrisUrlKeys($providerPayload);
            }
            $signaturePersisted = $this->persistTransactionSignature($lockedPayment, $transaction);
            $isPayableCreated = $signaturePersisted
                && $this->isPayableProviderTransaction($transaction, $lockedPayment, $providerExpiresAt);
            $nonPayableState = $signaturePersisted
                ? $this->nonPayableState($transaction, $lockedPayment, $providerExpiresAt)
                : 'provider_signature_missing';

            // Untrusted, missing, or invalid provider QR data is persisted as a
            // non-payable state; it must never become Payable later.
            $paymentUpdates = [
                'provider_payload' => $providerPayload,
                'provider_qris_url' => $providerQrisUrl,
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

            return true;
        });
    }

    private function persistTransactionSignature(Payment $payment, KlikQrisTransaction $transaction): bool
    {
        $signature = $transaction->signature();
        if (blank($signature)) {
            return $transaction->environment() !== KlikQrisEnvironment::Production;
        }

        if (blank($payment->klikqris_transaction_signature)) {
            $payment->update(['klikqris_transaction_signature' => $signature]);

            return true;
        }

        if (hash_equals((string) $payment->klikqris_transaction_signature, $signature)) {
            return true;
        }

        report(new RuntimeException('KlikQRIS transaction signature changed.'));

        return false;
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

    private function markCreationOutcomeUnknown(Payment $payment, Throwable $exception): void
    {
        $providerException = $exception->getPrevious() ?? $exception;

        $this->lifecycleCoordinator->withLockedOrderPayment($payment, function (Order $order, Payment $lockedPayment) use ($providerException): void {
            if ($this->hasPayablePersistedQris($lockedPayment)) {
                return;
            }

            $lockedPayment->update([
                'provider_create_state' => 'outcome_unknown',
                'provider_create_last_error' => substr($providerException::class, 0, 500),
            ]);
        });

        $payment->refresh();
    }

    private function markCreationDefinitivelyFailed(Payment $payment, Throwable $exception): void
    {
        $providerException = $exception->getPrevious() ?? $exception;

        $this->lifecycleCoordinator->withLockedOrderPayment($payment, function (Order $order, Payment $lockedPayment) use ($providerException): void {
            if ($this->hasPayablePersistedQris($lockedPayment)) {
                return;
            }

            $lockedPayment->update([
                'provider_create_state' => 'create_failed',
                'provider_create_last_error' => substr($providerException::class, 0, 500),
            ]);
        });

        $payment->refresh();
    }

    private function resetPreRequestFailure(Payment $payment): void
    {
        $this->lifecycleCoordinator->withLockedOrderPayment($payment, function (Order $order, Payment $lockedPayment): void {
            if ($this->hasPayablePersistedQris($lockedPayment) || $lockedPayment->provider_create_state !== 'create_failed') {
                return;
            }

            $lockedPayment->update([
                'provider_create_state' => null,
                'provider_create_last_error' => null,
            ]);
        });

        $payment->refresh();
    }

    private function markRecoveryUnconfirmed(Payment $payment, Throwable $exception): void
    {
        $diagnostic = $this->recoveryDiagnostic($exception);

        $this->lifecycleCoordinator->withLockedOrderPayment($payment, function (Order $order, Payment $lockedPayment) use ($diagnostic): void {
            if ($this->hasPayablePersistedQris($lockedPayment)) {
                return;
            }

            $lockedPayment->update([
                'provider_create_state' => 'recovery_unconfirmed',
                'provider_create_last_error' => $diagnostic,
            ]);
        });

        $payment->refresh();
    }

    private function recoveryDiagnostic(Throwable|string|null $value): string
    {
        if ($value instanceof KlikQrisStatusUnavailableException) {
            return 'status_unavailable';
        }

        if ($value instanceof KlikQrisStatusResponseException) {
            return $value->httpStatus === null
                ? 'status_response_invalid'
                : 'status_http_'.$value->httpStatus;
        }

        if (is_string($value)) {
            return match (true) {
                in_array($value, ['status_unavailable', 'status_response_invalid'], true) => $value,
                str_starts_with($value, 'status_http_') => $value,
                str_contains($value, KlikQrisStatusUnavailableException::class) => 'status_unavailable',
                str_contains($value, KlikQrisStatusResponseException::class) => 'status_response_invalid',
                default => 'recovery_failed',
            };
        }

        return 'recovery_failed';
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
