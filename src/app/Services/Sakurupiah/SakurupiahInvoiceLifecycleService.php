<?php

namespace App\Services\Sakurupiah;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\SakurupiahEnvironment;
use App\Models\Order;
use App\Models\Payment;
use App\Services\PaymentLifecycleCoordinator;
use App\Support\PaymentProvider;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;
use TypeError;

/**
 * Owns the post-commit Sakurupiah create/recovery lifecycle for one Payment.
 *
 * Provider HTTP is deliberately outside PaymentLifecycleCoordinator database
 * transactions. The cache lock is the single-flight boundary shared by future
 * status refresh, callback, and reconciliation callers.
 */
final class SakurupiahInvoiceLifecycleService
{
    public const STATE_CREATING = 'creating';

    public const STATE_CREATE_FAILED = 'create_failed';

    public const STATE_OUTCOME_UNKNOWN = 'outcome_unknown';

    public const STATE_RECOVERY_UNCONFIRMED = 'recovery_unconfirmed';

    public const STATE_CREATED = 'created';

    public const STATE_PROVIDER_TOTAL_PENDING = 'provider_total_pending';

    public const STATE_PROVIDER_TOTAL_INVALID = 'provider_total_invalid';

    public const STATE_PROVIDER_QRIS_UNUSABLE = 'provider_qris_unusable';

    public const STATE_PROVIDER_EXPIRED = 'provider_expired';

    public const STATE_PROVIDER_BERHASIL = 'provider_berhasil';

    public const STATE_INVALID_PROVIDER_RESULT = 'provider_invalid_result';

    private const PAYMENT_LOCK_WAIT_SECONDS = 10;

    public function __construct(
        private readonly SakurupiahProviderAdapter $provider,
        private readonly SakurupiahProviderReadiness $readiness,
        private readonly SakurupiahPaymentEventLogger $events,
        private readonly PaymentLifecycleCoordinator $lifecycleCoordinator,
        private readonly ?SakurupiahEnvironment $environment = null,
    ) {}

    /**
     * Advance one post-commit Payment. No new Order or Payment is ever made.
     */
    public function lifecycle(Payment $payment): SakurupiahInvoiceLifecycleResult
    {
        $payment->refresh();

        $localOutcome = $this->classifyLocalState($payment);
        if ($localOutcome !== null) {
            return SakurupiahInvoiceLifecycleResult::state($localOutcome);
        }

        if ($this->hasNonAdvancingState($payment)) {
            return $this->classifyPersisted($payment);
        }

        try {
            return Cache::lock($this->paymentLockKey($payment), $this->paymentLockLeaseSeconds())
                ->block(
                    self::PAYMENT_LOCK_WAIT_SECONDS,
                    fn (): SakurupiahInvoiceLifecycleResult => $this->advanceLocked($payment),
                );
        } catch (LockTimeoutException) {
            return SakurupiahInvoiceLifecycleResult::state(SakurupiahInvoiceOutcome::Creating);
        }
    }

    /**
     * Classify local state without provider I/O. Presentation is rebuilt only
     * from the encrypted validated Payment snapshot, never provider_payload.
     */
    public function classify(Payment $payment): SakurupiahInvoiceLifecycleResult
    {
        $payment->refresh();

        $localOutcome = $this->classifyLocalState($payment);
        if ($localOutcome !== null) {
            return SakurupiahInvoiceLifecycleResult::state($localOutcome);
        }

        return $this->classifyPersisted($payment);
    }

    /**
     * Explicitly refresh a persisted provider reference under the same lock as
     * create/recovery. Later callback and reconciliation code can use this seam
     * without opening a second concurrency model.
     */
    public function refreshStatus(Payment $payment): SakurupiahInvoiceLifecycleResult
    {
        $payment->refresh();

        $localOutcome = $this->classifyLocalState($payment);
        if ($localOutcome !== null) {
            return SakurupiahInvoiceLifecycleResult::state($localOutcome);
        }

        try {
            return Cache::lock($this->paymentLockKey($payment), $this->paymentLockLeaseSeconds())
                ->block(
                    self::PAYMENT_LOCK_WAIT_SECONDS,
                    fn (): SakurupiahInvoiceLifecycleResult => $this->recoverLocked($payment),
                );
        } catch (LockTimeoutException) {
            return SakurupiahInvoiceLifecycleResult::state(SakurupiahInvoiceOutcome::Creating);
        }
    }

    /**
     * Attempt one persisted-reference status refresh without waiting on the
     * payment lock. The storefront uses this to return an in-progress result
     * instead of allowing concurrent browser requests to overlap provider I/O.
     */
    public function tryRefreshStatus(Payment $payment): ?SakurupiahInvoiceLifecycleResult
    {
        return $this->tryWithPaymentLock(
            $payment,
            fn (): SakurupiahInvoiceLifecycleResult => $this->recoverLocked($payment),
        );
    }

    /** @param Closure(): mixed $callback */
    public function withPaymentLock(Payment $payment, Closure $callback): mixed
    {
        return Cache::lock($this->paymentLockKey($payment), $this->paymentLockLeaseSeconds())
            ->block(self::PAYMENT_LOCK_WAIT_SECONDS, $callback);
    }

    /** @param Closure(): mixed $callback */
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
        return 'sakurupiah:payment:'.$payment->getKey();
    }

    private function advanceLocked(Payment $payment): SakurupiahInvoiceLifecycleResult
    {
        $payment->refresh();

        $localOutcome = $this->classifyLocalState($payment);
        if ($localOutcome !== null) {
            return SakurupiahInvoiceLifecycleResult::state($localOutcome);
        }

        if ($payment->provider_create_state === self::STATE_CREATE_FAILED
            && blank($payment->provider_reference)) {
            $this->resetPreRequestFailure($payment);
            $payment->refresh();
        }

        if ($this->shouldRecover($payment)) {
            return $this->recoverLocked($payment);
        }

        if ($payment->provider_create_state !== null || filled($payment->provider_reference) || $this->hasProviderProjection($payment)) {
            return $this->classifyPersisted($payment);
        }

        if (! $this->markCreationAttempted($payment)) {
            $payment->refresh();

            if ($this->shouldRecover($payment)) {
                return $this->recoverLocked($payment);
            }

            return $this->classifyPersisted($payment);
        }

        try {
            $request = $this->buildCreateRequest($payment);
            $invoice = $this->provider->createInvoice($request);
        } catch (SakurupiahCreatePreRequestException|SakurupiahReadinessException $exception) {
            $this->markCreationDefinitivelyFailed($payment, $exception);

            if ($exception instanceof SakurupiahCreatePreRequestException) {
                throw $exception;
            }

            throw new SakurupiahCreatePreRequestException(previous: $exception);
        } catch (Throwable $exception) {
            $this->markCreationOutcomeUnknown($payment, $exception);

            return SakurupiahInvoiceLifecycleResult::state(SakurupiahInvoiceOutcome::RecoveryPending);
        }

        try {
            $assessment = $this->assessInvoice($payment, $invoice);
            $this->persistInvoiceObservation(
                $payment,
                $invoice,
                $assessment['state'],
                $assessment['reason'],
                $assessment['payable'],
            );
        } catch (Throwable $exception) {
            // A response was obtained but local persistence/validation did not
            // complete. Never send another create for this Payment.
            $this->markCreationOutcomeUnknown($payment, $exception);

            return SakurupiahInvoiceLifecycleResult::state(SakurupiahInvoiceOutcome::RecoveryPending);
        }

        $payment->refresh();
        if ($assessment['payable'] !== null) {
            $localOutcome = $this->classifyLocalState($payment);
            if ($localOutcome !== null) {
                return SakurupiahInvoiceLifecycleResult::state($localOutcome);
            }

            return SakurupiahInvoiceLifecycleResult::payableResult($assessment['payable']);
        }

        return SakurupiahInvoiceLifecycleResult::state($assessment['outcome']);
    }

    private function recoverLocked(Payment $payment): SakurupiahInvoiceLifecycleResult
    {
        $payment->refresh();

        $localOutcome = $this->classifyLocalState($payment);
        if ($localOutcome !== null) {
            return SakurupiahInvoiceLifecycleResult::state($localOutcome);
        }

        $providerReference = trim((string) $payment->provider_reference);
        if ($providerReference === '') {
            $this->markRecoveryUnconfirmed($payment, 'missing_provider_reference');

            return SakurupiahInvoiceLifecycleResult::state(SakurupiahInvoiceOutcome::RecoveryPending);
        }

        try {
            $status = $this->provider->transactionStatus($providerReference);
        } catch (Throwable $exception) {
            $this->markRecoveryUnconfirmed($payment, $this->exceptionCode($exception));

            return SakurupiahInvoiceLifecycleResult::state(SakurupiahInvoiceOutcome::RecoveryPending);
        }

        $identityReason = $this->statusIdentityMismatchReason($payment, $status);
        if ($identityReason !== null) {
            try {
                $this->persistStatusObservation($payment, $status, self::STATE_INVALID_PROVIDER_RESULT, $identityReason);
            } catch (Throwable $exception) {
                $this->markRecoveryUnconfirmed($payment, $this->exceptionCode($exception));

                return SakurupiahInvoiceLifecycleResult::state(SakurupiahInvoiceOutcome::RecoveryPending);
            }

            return SakurupiahInvoiceLifecycleResult::state(SakurupiahInvoiceOutcome::InvalidProviderResult);
        }

        try {
            $assessment = $this->assessStatus($payment, $status);
            $this->persistStatusObservation(
                $payment,
                $status,
                $assessment['state'],
                $assessment['reason'],
                $assessment['payable'],
            );
        } catch (Throwable $exception) {
            $this->markRecoveryUnconfirmed($payment, $this->exceptionCode($exception));

            return SakurupiahInvoiceLifecycleResult::state(SakurupiahInvoiceOutcome::RecoveryPending);
        }

        $payment->refresh();
        if ($assessment['payable'] !== null) {
            $localOutcome = $this->classifyLocalState($payment);
            if ($localOutcome !== null) {
                return SakurupiahInvoiceLifecycleResult::state($localOutcome);
            }

            return SakurupiahInvoiceLifecycleResult::payableResult($assessment['payable']);
        }

        return SakurupiahInvoiceLifecycleResult::state($assessment['outcome']);
    }

    /**
     * @return array{outcome: SakurupiahInvoiceOutcome, state: string, reason: ?string, payable: ?SakurupiahPayableInvoice}
     */
    private function assessInvoice(Payment $payment, SakurupiahInvoice $invoice): array
    {
        if ($invoice->isExpired()) {
            return $this->assessment(SakurupiahInvoiceOutcome::Expired, self::STATE_PROVIDER_EXPIRED, 'provider_expired');
        }

        if ($invoice->isSuccessful()) {
            return $this->assessment(SakurupiahInvoiceOutcome::InvalidProviderResult, self::STATE_PROVIDER_BERHASIL, 'paid_transition_not_owned');
        }

        if (! $invoice->isPending()) {
            return $this->assessment(SakurupiahInvoiceOutcome::InvalidProviderResult, self::STATE_INVALID_PROVIDER_RESULT, 'provider_status');
        }

        try {
            $payable = SakurupiahPayableInvoice::fromInvoice(
                payment: $payment,
                invoice: $invoice,
                effectiveExpiresAt: $this->effectiveExpiry($payment, $invoice->expiresAt),
                environment: $this->runtimeEnvironment(),
            );
        } catch (SakurupiahInvoiceIntegrityException $exception) {
            return $this->assessmentForIntegrityFailure($invoice, $exception);
        }

        return $this->assessment(SakurupiahInvoiceOutcome::Payable, self::STATE_CREATED, null, $payable);
    }

    /**
     * @return array{outcome: SakurupiahInvoiceOutcome, state: string, reason: ?string, payable: ?SakurupiahPayableInvoice}
     */
    private function assessStatus(Payment $payment, SakurupiahStatus $status): array
    {
        if ($status->isExpired()) {
            return $this->assessment(SakurupiahInvoiceOutcome::Expired, self::STATE_PROVIDER_EXPIRED, 'provider_expired');
        }

        if ($status->isSuccessful()) {
            if ($status->totalValue() === null) {
                return $this->assessment(SakurupiahInvoiceOutcome::InvalidProviderResult, self::STATE_PROVIDER_BERHASIL, 'authoritative_amount_missing');
            }
        }

        $invoice = $this->invoiceFromStatus($payment, $status);
        if ($invoice === null) {
            $payable = $this->persistedPayableForStatus($payment, $status);
            if ($payable !== null) {
                return $this->assessment(SakurupiahInvoiceOutcome::Payable, self::STATE_CREATED, null, $payable);
            }

            return $this->assessment(
                $status->isSuccessful()
                    ? SakurupiahInvoiceOutcome::InvalidProviderResult
                    : SakurupiahInvoiceOutcome::ProviderTotalPending,
                $status->isSuccessful() ? self::STATE_PROVIDER_BERHASIL : self::STATE_PROVIDER_TOTAL_PENDING,
                $status->isSuccessful() ? 'authoritative_amount_missing' : 'provider_total_pending',
            );
        }

        $assessment = $this->assessInvoice($payment, $invoice);
        if ($assessment['payable'] === null) {
            $payable = $this->persistedPayableForStatus($payment, $status);
            if ($payable !== null) {
                return $this->assessment(SakurupiahInvoiceOutcome::Payable, self::STATE_CREATED, null, $payable);
            }
        }

        return $assessment;
    }

    private function persistedPayableForStatus(Payment $payment, SakurupiahStatus $status): ?SakurupiahPayableInvoice
    {
        if (! $status->isPending()
            || $status->qrPayload() !== null
            || $status->checkoutUrl() !== null) {
            return null;
        }

        if (($status->totalValue() !== null && $status->totalValue() !== $payment->provider_total_payment)
            || ($status->feeValue() !== null && $status->feeValue() !== $payment->provider_fee)) {
            return null;
        }

        $merchantFee = $status->merchantFee();
        $configuredMerchantFee = config('services.sakurupiah.qris_contract.merchant_fee');
        if ($merchantFee !== null
            && (! is_int($configuredMerchantFee) || $merchantFee !== $configuredMerchantFee)) {
            return null;
        }

        try {
            return SakurupiahPayableInvoice::fromPersistedPayment(
                payment: $payment,
                environment: $this->runtimeEnvironment(),
                observedExpiresAt: $status->expiresAt,
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{outcome: SakurupiahInvoiceOutcome, state: string, reason: ?string, payable: ?SakurupiahPayableInvoice}
     */
    private function assessment(
        SakurupiahInvoiceOutcome $outcome,
        string $state,
        ?string $reason,
        ?SakurupiahPayableInvoice $payable = null,
    ): array {
        return compact('outcome', 'state', 'reason', 'payable');
    }

    /**
     * @return array{outcome: SakurupiahInvoiceOutcome, state: string, reason: ?string, payable: ?SakurupiahPayableInvoice}
     */
    private function assessmentForIntegrityFailure(SakurupiahInvoice $invoice, SakurupiahInvoiceIntegrityException $exception): array
    {
        return match ($exception->reason) {
            'expiry', 'effective_expiry' => $invoice->expiresAt !== null && $invoice->expiresAt->isPast()
                ? $this->assessment(SakurupiahInvoiceOutcome::Expired, self::STATE_PROVIDER_EXPIRED, $exception->reason)
                : $this->assessment(SakurupiahInvoiceOutcome::ProviderTotalPending, self::STATE_PROVIDER_TOTAL_PENDING, $exception->reason),
            'fee' => $invoice->fee === null
                ? $this->assessment(SakurupiahInvoiceOutcome::ProviderTotalPending, self::STATE_PROVIDER_TOTAL_PENDING, 'provider_fee_pending')
                : $this->assessment(SakurupiahInvoiceOutcome::InvalidProviderResult, self::STATE_PROVIDER_TOTAL_INVALID, 'provider_fee_invalid'),
            'fee_payer' => $this->assessment(
                SakurupiahInvoiceOutcome::InvalidProviderResult,
                self::STATE_PROVIDER_TOTAL_INVALID,
                'provider_fee_payer_invalid',
            ),
            'qris_presentation', 'hosted_presentation' => $this->assessment(
                SakurupiahInvoiceOutcome::ProviderTotalPending,
                self::STATE_PROVIDER_QRIS_UNUSABLE,
                'provider_presentation_unusable',
            ),
            'amount' => $this->assessment(SakurupiahInvoiceOutcome::InvalidProviderResult, self::STATE_PROVIDER_TOTAL_INVALID, 'provider_amount_invalid'),
            default => $this->assessment(SakurupiahInvoiceOutcome::InvalidProviderResult, self::STATE_INVALID_PROVIDER_RESULT, $exception->reason),
        };
    }

    private function persistInvoiceObservation(
        Payment $payment,
        SakurupiahInvoice $invoice,
        string $state,
        ?string $reason,
        ?SakurupiahPayableInvoice $payable = null,
    ): void {
        $this->lifecycleCoordinator->withLockedOrderPayment(
            $payment,
            function (Order $order, Payment $lockedPayment) use ($invoice, $state, $reason, $payable): void {
                $merchantMatches = $invoice->merchantRef === (string) $lockedPayment->merchant_ref;
                $methodMatches = strtoupper($invoice->paymentCode) === SakurupiahCreateInvoice::METHOD_QRIS;
                $referenceMatches = blank($lockedPayment->provider_reference)
                    || $lockedPayment->provider_reference === $invoice->trxId;

                $effectiveState = $state;
                if (! $referenceMatches) {
                    $effectiveState = self::STATE_INVALID_PROVIDER_RESULT;
                }

                $updates = [
                    'provider_payment_method' => $invoice->paymentCode,
                    'provider_total_payment' => $invoice->totalValue(),
                    'provider_fee' => $invoice->fee?->fixedAmount(),
                    'provider_payload' => $invoice->sanitizedPayload(),
                    'provider_created_at' => $invoice->createdAt,
                    'provider_expires_at' => $invoice->expiresAt,
                    'provider_presentation' => $effectiveState === self::STATE_CREATED && $payable !== null
                        ? $payable->persistenceSnapshot()
                        : null,
                    'provider_create_state' => $effectiveState,
                    'provider_create_last_error' => $effectiveState === self::STATE_CREATED
                        ? null
                        : ($reason ?? $effectiveState),
                ];

                if ($merchantMatches && $methodMatches && $referenceMatches) {
                    $updates['provider_reference'] = $invoice->trxId;
                }

                $this->tightenExpiry($order, $lockedPayment, $invoice->expiresAt);
                $updates['expired_at'] = $lockedPayment->expired_at;
                $lockedPayment->update($updates);
                $this->events->logProviderCreate($lockedPayment, $invoice);
            },
        );

        $payment->refresh();
    }

    private function persistStatusObservation(
        Payment $payment,
        SakurupiahStatus $status,
        string $state,
        ?string $reason,
        ?SakurupiahPayableInvoice $payable = null,
    ): void {
        $this->lifecycleCoordinator->withLockedOrderPayment(
            $payment,
            function (Order $order, Payment $lockedPayment) use ($status, $state, $reason, $payable): void {
                if (filled($lockedPayment->provider_reference)
                    && $lockedPayment->provider_reference !== $status->trxId) {
                    $state = self::STATE_INVALID_PROVIDER_RESULT;
                    $reason = 'provider_reference_changed';
                }

                $payload = $this->mergeSanitizedPayload(
                    is_array($lockedPayment->provider_payload) ? $lockedPayment->provider_payload : [],
                    $status->sanitizedPayload(),
                );
                $updates = [
                    'provider_reference' => $lockedPayment->provider_reference ?: $status->trxId,
                    'provider_payment_method' => $status->paymentCode ?? $lockedPayment->provider_payment_method,
                    'provider_total_payment' => $status->totalValue() ?? $lockedPayment->provider_total_payment,
                    'provider_fee' => $status->feeValue() ?? $lockedPayment->provider_fee,
                    'provider_payload' => $payload,
                    'provider_created_at' => $lockedPayment->provider_created_at ?? $status->occurredAt,
                    'provider_expires_at' => $status->expiresAt ?? $lockedPayment->provider_expires_at,
                    'provider_presentation' => $state === self::STATE_CREATED && $payable !== null
                        ? $payable->persistenceSnapshot()
                        : null,
                    'provider_create_state' => $state,
                    'provider_create_last_error' => $state === self::STATE_CREATED ? null : ($reason ?? $state),
                ];

                $this->tightenExpiry($order, $lockedPayment, $status->expiresAt);
                $updates['expired_at'] = $lockedPayment->expired_at;
                $lockedPayment->update($updates);
                $this->events->logReconciliation($lockedPayment, $status);
            },
        );

        $payment->refresh();
    }

    private function markCreationAttempted(Payment $payment): bool
    {
        $reserved = $this->lifecycleCoordinator->withLockedOrderPayment(
            $payment,
            function (Order $order, Payment $lockedPayment): bool {
                if (! $this->canCreate($order, $lockedPayment)
                    || $lockedPayment->provider_create_state !== null
                    || filled($lockedPayment->provider_reference)
                    || $this->hasProviderProjection($lockedPayment)) {
                    return false;
                }

                $lockedPayment->update([
                    'provider_create_state' => self::STATE_CREATING,
                    'provider_create_attempted_at' => now(),
                ]);

                return true;
            },
        );

        if ($reserved) {
            $payment->refresh();
        }

        return $reserved;
    }

    private function markCreationOutcomeUnknown(Payment $payment, Throwable|string $error): void
    {
        $errorCode = is_string($error) ? $error : $this->exceptionCode($error);

        $this->lifecycleCoordinator->withLockedOrderPayment(
            $payment,
            function (Order $_order, Payment $lockedPayment) use ($errorCode): void {
                if ($lockedPayment->provider_create_state === self::STATE_CREATED
                    && filled($lockedPayment->provider_reference)) {
                    return;
                }

                $lockedPayment->update([
                    'provider_create_state' => self::STATE_OUTCOME_UNKNOWN,
                    'provider_create_last_error' => $errorCode,
                ]);
            },
        );

        $payment->refresh();
        $this->events->logCreateObservation($payment, self::STATE_OUTCOME_UNKNOWN, $errorCode);
    }

    private function markCreationDefinitivelyFailed(Payment $payment, Throwable $exception): void
    {
        $this->lifecycleCoordinator->withLockedOrderPayment(
            $payment,
            function (Order $_order, Payment $lockedPayment) use ($exception): void {
                if (filled($lockedPayment->provider_reference)) {
                    return;
                }

                $lockedPayment->update([
                    'provider_create_state' => self::STATE_CREATE_FAILED,
                    'provider_create_last_error' => $this->exceptionCode($exception),
                ]);
            },
        );

        $payment->refresh();
        $this->events->logCreateObservation($payment, self::STATE_CREATE_FAILED, $this->exceptionCode($exception));
    }

    private function markRecoveryUnconfirmed(Payment $payment, string $reason): void
    {
        $this->lifecycleCoordinator->withLockedOrderPayment(
            $payment,
            function (Order $_order, Payment $lockedPayment) use ($reason): void {
                if ($lockedPayment->status !== PaymentStatus::Pending) {
                    return;
                }

                $lockedPayment->update([
                    'provider_create_state' => self::STATE_RECOVERY_UNCONFIRMED,
                    'provider_create_last_error' => $reason,
                ]);
            },
        );

        $payment->refresh();
        $this->events->logRecoveryObservation($payment, self::STATE_RECOVERY_UNCONFIRMED, $reason);
    }

    private function resetPreRequestFailure(Payment $payment): void
    {
        $this->lifecycleCoordinator->withLockedOrderPayment(
            $payment,
            function (Order $_order, Payment $lockedPayment): void {
                if ($lockedPayment->provider_create_state !== self::STATE_CREATE_FAILED
                    || filled($lockedPayment->provider_reference)) {
                    return;
                }

                $lockedPayment->update([
                    'provider_create_state' => null,
                    'provider_create_last_error' => null,
                ]);
            },
        );
    }

    private function buildCreateRequest(Payment $payment): SakurupiahCreateInvoice
    {
        $environment = $this->runtimeEnvironment();
        try {
            $this->readiness->configurationReport($environment)->assertReady(
                'Sakurupiah '.$environment->label().' environment is not ready.',
            );

            $contract = config('services.sakurupiah.qris_contract', []);
            $merchantFee = $contract['merchant_fee'] ?? null;
            $expiryHours = $contract['expiry_hours'] ?? null;
            if (($contract['currency'] ?? null) !== 'IDR'
                || ($contract['currency_confirmed'] ?? false) !== true
                || ($contract['fee_payer'] ?? null) !== 'merchant'
                || ($contract['fee_confirmed'] ?? false) !== true
                || ! is_int($merchantFee)
                || ! in_array($merchantFee, [1, 2], true)
                || ($contract['expiry_confirmed'] ?? false) !== true
                || ! is_int($expiryHours)
                || $expiryHours < 1
            ) {
                throw new SakurupiahCreatePreRequestException('Sakurupiah QRIS contract is not ready.');
            }

            $order = $payment->order;
            $customer = $order?->customer;
            $phone = trim((string) $customer?->phone_number);
            if ($phone === '' && $order?->support_contact_channel === 'whatsapp') {
                $phone = trim((string) $order->support_contact_value);
            }
            if ($phone === '') {
                throw new SakurupiahCreatePreRequestException('Sakurupiah customer phone is required before invoice creation.');
            }

            return new SakurupiahCreateInvoice(
                method: SakurupiahCreateInvoice::METHOD_QRIS,
                phone: $phone,
                amount: (int) $payment->amount,
                merchantFee: $merchantFee,
                merchantRef: (string) $payment->merchant_ref,
                callbackUrl: (string) config($environment->configKey('callback_url')),
                returnUrl: (string) config($environment->configKey('return_url')),
                name: $customer?->first_name,
                email: $customer?->email,
                expired: $expiryHours,
            );
        } catch (SakurupiahCreatePreRequestException $exception) {
            throw $exception;
        } catch (InvalidArgumentException|TypeError $exception) {
            throw new SakurupiahCreatePreRequestException(previous: $exception);
        }
    }

    private function classifyLocalState(Payment $payment): ?SakurupiahInvoiceOutcome
    {
        if ($payment->provider !== PaymentProvider::SAKURUPIAH
            || strtoupper(trim((string) $payment->method)) !== SakurupiahCreateInvoice::METHOD_QRIS
            || strtoupper(trim((string) $payment->currency)) !== 'IDR') {
            return SakurupiahInvoiceOutcome::InvalidProviderResult;
        }

        $order = $payment->order;
        if ($payment->status === PaymentStatus::Expired
            || $payment->expired_at?->isPast()
            || $payment->provider_expires_at?->isPast()
            || $order?->expires_at?->isPast()
            || $order?->order_status === OrderStatus::Expired) {
            return SakurupiahInvoiceOutcome::Expired;
        }

        if ($payment->status !== PaymentStatus::Pending
            || $order?->order_status !== OrderStatus::PendingPayment) {
            return SakurupiahInvoiceOutcome::InvalidProviderResult;
        }

        return null;
    }

    private function classifyPersisted(Payment $payment): SakurupiahInvoiceLifecycleResult
    {
        if ($payment->provider_create_state === self::STATE_CREATED) {
            try {
                return SakurupiahInvoiceLifecycleResult::payableResult(
                    SakurupiahPayableInvoice::fromPersistedPayment($payment, $this->runtimeEnvironment()),
                );
            } catch (Throwable) {
                return SakurupiahInvoiceLifecycleResult::state(SakurupiahInvoiceOutcome::ProviderTotalPending);
            }
        }

        $outcome = match ($payment->provider_create_state) {
            self::STATE_OUTCOME_UNKNOWN, self::STATE_RECOVERY_UNCONFIRMED => SakurupiahInvoiceOutcome::RecoveryPending,
            self::STATE_CREATING, self::STATE_CREATE_FAILED, null => filled($payment->provider_reference)
                ? SakurupiahInvoiceOutcome::RecoveryPending
                : SakurupiahInvoiceOutcome::Creating,
            self::STATE_PROVIDER_TOTAL_PENDING, self::STATE_PROVIDER_QRIS_UNUSABLE => SakurupiahInvoiceOutcome::ProviderTotalPending,
            self::STATE_PROVIDER_EXPIRED => SakurupiahInvoiceOutcome::Expired,
            default => SakurupiahInvoiceOutcome::InvalidProviderResult,
        };

        return SakurupiahInvoiceLifecycleResult::state($outcome);
    }

    private function shouldRecover(Payment $payment): bool
    {
        if (blank($payment->provider_reference)) {
            return in_array($payment->provider_create_state, [
                self::STATE_OUTCOME_UNKNOWN,
                self::STATE_RECOVERY_UNCONFIRMED,
            ], true);
        }

        return in_array($payment->provider_create_state, [
            null,
            self::STATE_CREATING,
            self::STATE_CREATE_FAILED,
            self::STATE_OUTCOME_UNKNOWN,
            self::STATE_RECOVERY_UNCONFIRMED,
        ], true);
    }

    private function hasNonAdvancingState(Payment $payment): bool
    {
        return in_array($payment->provider_create_state, [
            self::STATE_PROVIDER_EXPIRED,
            self::STATE_PROVIDER_TOTAL_INVALID,
            self::STATE_PROVIDER_BERHASIL,
            self::STATE_INVALID_PROVIDER_RESULT,
        ], true);
    }

    private function hasProviderProjection(Payment $payment): bool
    {
        return is_array($payment->provider_payload) && $payment->provider_payload !== [];
    }

    private function canCreate(Order $order, Payment $payment): bool
    {
        return $this->lifecycleCoordinator->canCreateNativeQris($order, $payment);
    }

    private function tightenExpiry(Order $order, Payment $payment, ?CarbonImmutable $providerExpiresAt): void
    {
        if ($providerExpiresAt === null) {
            return;
        }

        if ($payment->expired_at === null || $providerExpiresAt->lt($payment->expired_at)) {
            $payment->expired_at = $providerExpiresAt;
        }

        if ($order->expires_at === null || $providerExpiresAt->lt($order->expires_at)) {
            $order->expires_at = $providerExpiresAt;
            $order->save();
        }
    }

    private function effectiveExpiry(Payment $payment, ?CarbonImmutable $providerExpiresAt): CarbonImmutable
    {
        $deadlines = array_values(array_filter([
            $providerExpiresAt,
            $payment->expired_at,
            $payment->order?->expires_at,
        ], static fn (mixed $deadline): bool => $deadline instanceof CarbonInterface));

        if ($deadlines === []) {
            throw new SakurupiahInvoiceIntegrityException('expiry');
        }

        $deadlines = array_map(
            static fn (CarbonInterface $deadline): CarbonImmutable => CarbonImmutable::instance($deadline),
            $deadlines,
        );
        usort($deadlines, static fn (CarbonImmutable $left, CarbonImmutable $right): int => $left <=> $right);

        return $deadlines[0];
    }

    private function statusIdentityMismatchReason(Payment $payment, SakurupiahStatus $status): ?string
    {
        if ($status->trxId !== (string) $payment->provider_reference) {
            return 'provider_reference_mismatch';
        }

        if ($status->merchantRef !== null && $status->merchantRef !== (string) $payment->merchant_ref) {
            return 'merchant_reference_mismatch';
        }

        if ($status->paymentCode !== null
            && strtoupper($status->paymentCode) !== SakurupiahCreateInvoice::METHOD_QRIS) {
            return 'payment_method_mismatch';
        }

        if ($status->amountValue() !== null && $status->amountValue() !== (int) $payment->amount) {
            return 'amount_mismatch';
        }

        return null;
    }

    private function invoiceFromStatus(Payment $payment, SakurupiahStatus $status): ?SakurupiahInvoice
    {
        if ($status->total === null) {
            return null;
        }

        return new SakurupiahInvoice(
            trxId: $status->trxId,
            merchantRef: $status->merchantRef ?? (string) $payment->merchant_ref,
            paymentCode: $status->paymentCode === null
                ? SakurupiahCreateInvoice::METHOD_QRIS
                : strtoupper($status->paymentCode),
            status: $status->status,
            total: $status->total,
            amount: $status->amount,
            fee: $status->fee,
            createdAt: $status->occurredAt,
            expiresAt: $status->expiresAt,
            qr: $status->qrPayload(),
            checkoutUrl: $status->checkoutUrl(),
            sanitizedPayload: $status->sanitizedPayload(),
            merchantFee: $status->merchantFee(),
        );
    }

    /** @param array<mixed> $existing @param array<mixed> $observed @return array<mixed> */
    private function mergeSanitizedPayload(array $existing, array $observed): array
    {
        return SakurupiahPayloadSanitizer::sanitize(array_replace_recursive($existing, $observed));
    }

    private function exceptionCode(Throwable $exception): string
    {
        return substr($exception::class, 0, 500);
    }

    private function runtimeEnvironment(): SakurupiahEnvironment
    {
        return $this->environment ?? SakurupiahEnvironment::runtime();
    }

    private function paymentLockLeaseSeconds(): int
    {
        return max(60, (int) config('services.sakurupiah.lock_lease_seconds', 90));
    }
}
