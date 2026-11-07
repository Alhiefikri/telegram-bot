<?php

namespace App\Services\WijayaPay;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\PollActiveWijayaPayPayment;
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
 * Post-commit WijayaPay create/recovery lifecycle for one Payment.
 *
 * Provider HTTP is deliberately outside PaymentLifecycleCoordinator database
 * transactions. The cache lock is the single-flight boundary shared by
 * create, customer refresh, callback verification and reconciliation.
 *
 * WijayaPay documents that reusing a `ref_id` returns the existing
 * transaction instead of creating another one, so recovery may retry the same
 * create request after a bounded interval. A new merchant reference is never
 * generated for an existing Payment.
 */
final class WijayaPayInvoiceLifecycleService
{
    public const STATE_CREATING = 'creating';

    public const STATE_CREATE_FAILED = 'create_failed';

    public const STATE_OUTCOME_UNKNOWN = 'outcome_unknown';

    public const STATE_RECOVERY_UNCONFIRMED = 'recovery_unconfirmed';

    public const STATE_CREATED = 'created';

    public const STATE_PROVIDER_TOTAL_PENDING = 'provider_total_pending';

    public const STATE_PROVIDER_PRESENTATION_PENDING = 'provider_presentation_pending';

    public const STATE_PROVIDER_EXPIRED = 'provider_expired';

    public const STATE_PROVIDER_PAID = 'provider_paid';

    public const STATE_INVALID_PROVIDER_RESULT = 'provider_invalid_result';

    private const PAYMENT_LOCK_WAIT_SECONDS = 10;

    public function __construct(
        private readonly WijayaPayProviderAdapter $provider,
        private readonly WijayaPayProviderReadiness $readiness,
        private readonly WijayaPayPaymentEventLogger $events,
        private readonly PaymentLifecycleCoordinator $lifecycleCoordinator,
    ) {}

    public function lifecycle(Payment $payment): WijayaPayInvoiceLifecycleResult
    {
        $payment->refresh();

        $localOutcome = $this->classifyLocalState($payment);
        if ($localOutcome !== null) {
            return WijayaPayInvoiceLifecycleResult::state($localOutcome);
        }

        if ($this->hasNonAdvancingState($payment)) {
            return $this->classifyPersisted($payment);
        }

        try {
            return Cache::lock($this->paymentLockKey($payment), $this->paymentLockLeaseSeconds())
                ->block(
                    self::PAYMENT_LOCK_WAIT_SECONDS,
                    fn (): WijayaPayInvoiceLifecycleResult => $this->advanceLocked($payment),
                );
        } catch (LockTimeoutException) {
            return WijayaPayInvoiceLifecycleResult::state(WijayaPayInvoiceOutcome::Creating);
        }
    }

    public function classify(Payment $payment): WijayaPayInvoiceLifecycleResult
    {
        $payment->refresh();

        $localOutcome = $this->classifyLocalState($payment);
        if ($localOutcome !== null) {
            return WijayaPayInvoiceLifecycleResult::state($localOutcome);
        }

        return $this->classifyPersisted($payment);
    }

    /**
     * @return list<int>
     */
    public static function expectedProviderAmounts(Payment $payment): array
    {
        $amounts = [(int) $payment->amount];
        if ($payment->provider_total_payment !== null) {
            $amounts[] = (int) $payment->provider_total_payment;
        }
        if ($payment->provider_fee !== null && (int) $payment->provider_fee > 0) {
            $amounts[] = (int) $payment->amount + (int) $payment->provider_fee;
        }

        return array_values(array_unique(array_filter($amounts, static fn (int $amount): bool => $amount > 0)));
    }

    public function isCustomerStatusRefreshable(Payment $payment): bool
    {
        if ($this->classifyLocalState($payment) !== null) {
            return false;
        }

        return filled($payment->provider_reference)
            || $this->canRetryCreate($payment)
            || in_array($payment->provider_create_state, [
                self::STATE_OUTCOME_UNKNOWN,
                self::STATE_RECOVERY_UNCONFIRMED,
            ], true);
    }

    public function recoverWhileLocked(Payment $payment): WijayaPayInvoiceLifecycleResult
    {
        return $this->recoverLocked($payment);
    }

    public function refreshStatus(Payment $payment): WijayaPayInvoiceLifecycleResult
    {
        $payment->refresh();

        $localOutcome = $this->classifyLocalState($payment);
        if ($localOutcome !== null) {
            return WijayaPayInvoiceLifecycleResult::state($localOutcome);
        }

        try {
            return Cache::lock($this->paymentLockKey($payment), $this->paymentLockLeaseSeconds())
                ->block(
                    self::PAYMENT_LOCK_WAIT_SECONDS,
                    fn (): WijayaPayInvoiceLifecycleResult => $this->recoverLocked($payment),
                );
        } catch (LockTimeoutException) {
            return WijayaPayInvoiceLifecycleResult::state(WijayaPayInvoiceOutcome::Creating);
        }
    }

    public function tryRefreshStatus(Payment $payment): ?WijayaPayInvoiceLifecycleResult
    {
        return $this->tryWithPaymentLock(
            $payment,
            fn (): WijayaPayInvoiceLifecycleResult => $this->recoverLocked($payment),
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
        return 'wijayapay:payment:'.$payment->getKey();
    }

    public function persistAuthoritativeStatusObservation(
        Payment $payment,
        WijayaPayStatusResponse $status,
        string $state,
        ?string $reason = null,
    ): void {
        $this->persistStatusObservation($payment, $status, $state, $reason);
    }

    public function persistRecoveryUnconfirmed(Payment $payment, string $reason): void
    {
        $this->markRecoveryUnconfirmed($payment, $reason);
    }

    /**
     * Reuse a pending observation already obtained by the sweep without
     * making a second provider request.
     */
    public function recoverPendingObservationWhileLocked(
        Payment $payment,
        WijayaPayStatusResponse $status,
    ): WijayaPayInvoiceLifecycleResult {
        if (! $status->isPending()) {
            throw new InvalidArgumentException('A pending WijayaPay observation is required.');
        }

        $payment->refresh();
        $localOutcome = $this->classifyLocalState($payment);
        if ($localOutcome !== null) {
            return WijayaPayInvoiceLifecycleResult::state($localOutcome);
        }

        return $this->recoverFromStatusWhileLocked($payment, $status);
    }

    private function advanceLocked(Payment $payment): WijayaPayInvoiceLifecycleResult
    {
        $payment->refresh();

        $localOutcome = $this->classifyLocalState($payment);
        if ($localOutcome !== null) {
            return WijayaPayInvoiceLifecycleResult::state($localOutcome);
        }

        if (filled($payment->provider_reference)) {
            return $this->recoverLocked($payment);
        }

        if ($payment->provider_create_state === self::STATE_CREATE_FAILED) {
            if (! $this->createRetryAllowed($payment)) {
                return $this->classifyPersisted($payment);
            }

            $this->resetPreRequestFailure($payment);
            $payment->refresh();
        }

        if (in_array($payment->provider_create_state, [
            self::STATE_OUTCOME_UNKNOWN,
            self::STATE_RECOVERY_UNCONFIRMED,
            self::STATE_CREATING,
        ], true)) {
            return $this->recoverLocked($payment);
        }

        if ($payment->provider_create_state !== null || $this->hasProviderProjection($payment)) {
            return $this->classifyPersisted($payment);
        }

        if (! $this->markCreationAttempted($payment)) {
            return $this->classifyPersisted($payment->refresh());
        }

        return $this->attemptCreateLocked($payment);
    }

    private function attemptCreateLocked(Payment $payment): WijayaPayInvoiceLifecycleResult
    {
        try {
            $request = $this->buildCreateRequest($payment);
        } catch (WijayaPayCreatePreRequestException|WijayaPayReadinessException $exception) {
            $this->markCreationDefinitivelyFailed($payment, $exception);

            return WijayaPayInvoiceLifecycleResult::state(WijayaPayInvoiceOutcome::Creating);
        }

        $this->markCreationDispatched($payment);

        try {
            $invoice = $this->provider->createInvoice($request);
        } catch (WijayaPayCreatePreRequestException|WijayaPayReadinessException $exception) {
            $this->markCreationDefinitivelyFailed($payment, $exception);

            return WijayaPayInvoiceLifecycleResult::state(WijayaPayInvoiceOutcome::Creating);
        } catch (WijayaPayApplicationException $exception) {
            // The provider answered with its own rejection contract. No
            // transaction exists for this ref_id; bounded retry is allowed.
            $this->markCreationDefinitivelyFailed($payment, $exception);

            return WijayaPayInvoiceLifecycleResult::state(WijayaPayInvoiceOutcome::Creating);
        } catch (Throwable $exception) {
            $this->markCreationOutcomeUnknown($payment, $exception);

            return WijayaPayInvoiceLifecycleResult::state(WijayaPayInvoiceOutcome::RecoveryPending);
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
            $this->markCreationOutcomeUnknown($payment, $exception);

            return WijayaPayInvoiceLifecycleResult::state(WijayaPayInvoiceOutcome::RecoveryPending);
        }

        $payment->refresh();
        if ($assessment['outcome'] === WijayaPayInvoiceOutcome::Paid) {
            return WijayaPayInvoiceLifecycleResult::state(WijayaPayInvoiceOutcome::Paid);
        }

        if ($assessment['payable'] !== null) {
            $localOutcome = $this->classifyLocalState($payment);
            if ($localOutcome !== null) {
                return WijayaPayInvoiceLifecycleResult::state($localOutcome);
            }

            PollActiveWijayaPayPayment::schedule($payment);

            return WijayaPayInvoiceLifecycleResult::payableResult($assessment['payable']);
        }

        return WijayaPayInvoiceLifecycleResult::state($assessment['outcome']);
    }

    private function recoverLocked(Payment $payment): WijayaPayInvoiceLifecycleResult
    {
        $payment->refresh();

        $localOutcome = $this->classifyLocalState($payment);
        if ($localOutcome !== null) {
            return WijayaPayInvoiceLifecycleResult::state($localOutcome);
        }

        if ($payment->provider_create_state === self::STATE_CREATE_FAILED
            && blank($payment->provider_reference)
            && $this->canRetryCreate($payment)
        ) {
            $this->resetPreRequestFailure($payment);
            $payment->refresh();

            if ($this->markCreationAttempted($payment)) {
                return $this->attemptCreateLocked($payment);
            }

            return $this->classifyPersisted($payment->refresh());
        }

        try {
            $status = $this->provider->transactionStatus((string) $payment->merchant_ref);
        } catch (WijayaPayProviderException $exception) {
            if ($exception->isNotFound()
                && blank($payment->provider_reference)
                && $this->canRetryCreate($payment)
            ) {
                $this->resetPreRequestFailure($payment);
                $payment->refresh();

                if ($this->markCreationAttempted($payment)) {
                    return $this->attemptCreateLocked($payment);
                }
            }

            $this->markRecoveryUnconfirmed($payment, $this->exceptionCode($exception));

            return WijayaPayInvoiceLifecycleResult::state(WijayaPayInvoiceOutcome::RecoveryPending);
        } catch (Throwable $exception) {
            $this->markRecoveryUnconfirmed($payment, $this->exceptionCode($exception));

            return WijayaPayInvoiceLifecycleResult::state(WijayaPayInvoiceOutcome::RecoveryPending);
        }

        return $this->recoverFromStatusWhileLocked($payment, $status);
    }

    private function recoverFromStatusWhileLocked(
        Payment $payment,
        WijayaPayStatusResponse $status,
    ): WijayaPayInvoiceLifecycleResult {
        $identityReason = $this->statusIdentityMismatchReason($payment, $status);
        if ($identityReason !== null) {
            try {
                $this->persistStatusObservation($payment, $status, self::STATE_INVALID_PROVIDER_RESULT, $identityReason);
            } catch (Throwable $exception) {
                $this->markRecoveryUnconfirmed($payment, $this->exceptionCode($exception));

                return WijayaPayInvoiceLifecycleResult::state(WijayaPayInvoiceOutcome::RecoveryPending);
            }

            return WijayaPayInvoiceLifecycleResult::state(WijayaPayInvoiceOutcome::InvalidProviderResult);
        }

        if ($status->isExpired()) {
            $this->persistStatusObservation($payment, $status, self::STATE_PROVIDER_EXPIRED, 'provider_expired');

            return WijayaPayInvoiceLifecycleResult::state(WijayaPayInvoiceOutcome::Expired);
        }

        if ($status->isPaid()) {
            $this->persistStatusObservation($payment, $status, self::STATE_PROVIDER_PAID, 'paid_transition_not_owned');

            return WijayaPayInvoiceLifecycleResult::state(WijayaPayInvoiceOutcome::Paid);
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

            return WijayaPayInvoiceLifecycleResult::state(WijayaPayInvoiceOutcome::RecoveryPending);
        }

        $payment->refresh();
        if ($assessment['payable'] !== null) {
            $localOutcome = $this->classifyLocalState($payment);
            if ($localOutcome !== null) {
                return WijayaPayInvoiceLifecycleResult::state($localOutcome);
            }

            PollActiveWijayaPayPayment::schedule($payment);

            return WijayaPayInvoiceLifecycleResult::payableResult($assessment['payable']);
        }

        return WijayaPayInvoiceLifecycleResult::state($assessment['outcome']);
    }

    /**
     * @return array{outcome: WijayaPayInvoiceOutcome, state: string, reason: ?string, payable: ?WijayaPayPayableInvoice}
     */
    private function assessInvoice(Payment $payment, WijayaPayInvoice $invoice): array
    {
        if ($invoice->isExpired()) {
            return $this->assessment(WijayaPayInvoiceOutcome::Expired, self::STATE_PROVIDER_EXPIRED, 'provider_expired');
        }

        if ($invoice->isPaid()) {
            return $this->assessment(WijayaPayInvoiceOutcome::Paid, self::STATE_PROVIDER_PAID, 'paid_transition_not_owned');
        }

        if (! $invoice->isPending()) {
            return $this->assessment(
                WijayaPayInvoiceOutcome::InvalidProviderResult,
                self::STATE_INVALID_PROVIDER_RESULT,
                'provider_status',
            );
        }

        try {
            $payable = WijayaPayPayableInvoice::fromInvoice(
                payment: $payment,
                invoice: $invoice,
                effectiveExpiresAt: $this->effectiveExpiry($payment, $invoice->expiresAt),
            );
        } catch (WijayaPaySchemaException $exception) {
            return $this->assessmentForIntegrityFailure($invoice, $exception);
        }

        return $this->assessment(WijayaPayInvoiceOutcome::Payable, self::STATE_CREATED, null, $payable);
    }

    /**
     * @return array{outcome: WijayaPayInvoiceOutcome, state: string, reason: ?string, payable: ?WijayaPayPayableInvoice}
     */
    private function assessStatus(Payment $payment, WijayaPayStatusResponse $status): array
    {
        $total = $status->totalValue();
        $fee = $status->feeValue() ?? ($payment->provider_fee === null ? null : (int) $payment->provider_fee);

        if ($total === null || $fee === null) {
            // A sparse pending status must not discard an already validated
            // QR snapshot. Rebuild only from the encrypted presentation.
            try {
                $payable = WijayaPayPayableInvoice::fromPersistedPayment($payment, $status->expiresAt);

                return $this->assessment(WijayaPayInvoiceOutcome::Payable, self::STATE_CREATED, null, $payable);
            } catch (WijayaPaySchemaException) {
                return $this->assessment(
                    WijayaPayInvoiceOutcome::ProviderTotalPending,
                    self::STATE_PROVIDER_TOTAL_PENDING,
                    $total === null ? 'provider_total_pending' : 'provider_fee_pending',
                );
            }
        }

        try {
            $feePayer = WijayaPayInvoice::feePayer((int) $payment->amount, $total, $fee);
            WijayaPayInvoice::assertFeeWithinCap($fee, (int) $payment->amount);
        } catch (WijayaPaySchemaException) {
            return $this->assessment(
                WijayaPayInvoiceOutcome::InvalidProviderResult,
                self::STATE_INVALID_PROVIDER_RESULT,
                'provider_amount_invalid',
            );
        }

        $invoice = new WijayaPayInvoice(
            refId: $status->refId,
            trxReference: $status->trxReference,
            paymentMethod: WijayaPayCreateInvoice::PAYMENT_METHOD_QRIS,
            status: $status->status,
            totalBayar: $total,
            totalFee: $fee,
            totalDiterima: max(0, $total - $fee),
            feePayer: $feePayer,
            expiresAt: $status->expiresAt,
            updatedAt: $status->updatedAt,
            qrString: $status->qrPayload(),
            qrImageUrl: $status->qrImageUrl(),
            sanitizedPayload: $status->sanitizedPayload,
        );

        if ($status->expiresAt === null) {
            try {
                $payable = WijayaPayPayableInvoice::fromPersistedPayment($payment);

                return $this->assessment(WijayaPayInvoiceOutcome::Payable, self::STATE_CREATED, null, $payable);
            } catch (WijayaPaySchemaException) {
                return $this->assessment(
                    WijayaPayInvoiceOutcome::ProviderPresentationPending,
                    self::STATE_PROVIDER_PRESENTATION_PENDING,
                    'provider_expiry_pending',
                );
            }
        }

        try {
            $payable = WijayaPayPayableInvoice::fromPersistedPayment($payment, $status->expiresAt);
        } catch (WijayaPaySchemaException) {
            try {
                $payable = WijayaPayPayableInvoice::fromInvoice(
                    payment: $payment,
                    invoice: $invoice,
                    effectiveExpiresAt: $this->effectiveExpiry($payment, $status->expiresAt),
                );
            } catch (WijayaPaySchemaException $exception) {
                return $this->assessmentForIntegrityFailure($invoice, $exception);
            }
        }

        return $this->assessment(WijayaPayInvoiceOutcome::Payable, self::STATE_CREATED, null, $payable);
    }

    /**
     * @return array{outcome: WijayaPayInvoiceOutcome, state: string, reason: ?string, payable: ?WijayaPayPayableInvoice}
     */
    private function assessment(
        WijayaPayInvoiceOutcome $outcome,
        string $state,
        ?string $reason,
        ?WijayaPayPayableInvoice $payable = null,
    ): array {
        return compact('outcome', 'state', 'reason', 'payable');
    }

    /**
     * @return array{outcome: WijayaPayInvoiceOutcome, state: string, reason: ?string, payable: ?WijayaPayPayableInvoice}
     */
    private function assessmentForIntegrityFailure(WijayaPayInvoice $invoice, WijayaPaySchemaException $exception): array
    {
        return match ($exception->operation) {
            'expiry', 'effective_expiry' => $invoice->expiresAt !== null && $invoice->expiresAt->isPast()
                ? $this->assessment(WijayaPayInvoiceOutcome::Expired, self::STATE_PROVIDER_EXPIRED, $exception->operation)
                : $this->assessment(
                    WijayaPayInvoiceOutcome::ProviderPresentationPending,
                    self::STATE_PROVIDER_PRESENTATION_PENDING,
                    $exception->operation,
                ),
            'qris_presentation', 'qris_image_presentation', 'presentation_snapshot' => $this->assessment(
                WijayaPayInvoiceOutcome::ProviderPresentationPending,
                self::STATE_PROVIDER_PRESENTATION_PENDING,
                'provider_presentation_unusable',
            ),
            'amount', 'fee' => $this->assessment(
                WijayaPayInvoiceOutcome::InvalidProviderResult,
                self::STATE_INVALID_PROVIDER_RESULT,
                'provider_amount_invalid',
            ),
            default => $this->assessment(
                WijayaPayInvoiceOutcome::InvalidProviderResult,
                self::STATE_INVALID_PROVIDER_RESULT,
                $exception->operation,
            ),
        };
    }

    private function persistInvoiceObservation(
        Payment $payment,
        WijayaPayInvoice $invoice,
        string $state,
        ?string $reason,
        ?WijayaPayPayableInvoice $payable = null,
    ): void {
        $this->lifecycleCoordinator->withLockedOrderPayment(
            $payment,
            function (Order $order, Payment $lockedPayment) use ($invoice, $state, $reason, $payable): void {
                $merchantMatches = $invoice->refId === (string) $lockedPayment->merchant_ref;
                $referenceMatches = blank($lockedPayment->provider_reference)
                    || $lockedPayment->provider_reference === $invoice->trxReference;

                $effectiveState = $state;
                if (! $merchantMatches || ! $referenceMatches) {
                    $effectiveState = self::STATE_INVALID_PROVIDER_RESULT;
                }

                $updates = [
                    'provider_payment_method' => WijayaPayCreateInvoice::PAYMENT_METHOD_QRIS,
                    'provider_total_payment' => $invoice->totalValue(),
                    'provider_fee' => $invoice->totalFee,
                    'provider_payload' => WijayaPayPayloadSanitizer::sanitize($invoice->sanitizedPayload),
                    'provider_created_at' => $lockedPayment->provider_created_at ?? now(),
                    'provider_expires_at' => $invoice->expiresAt,
                    'provider_presentation' => $effectiveState === self::STATE_CREATED && $payable !== null
                        ? $payable->persistenceSnapshot()
                        : null,
                    'provider_create_state' => $effectiveState,
                    'provider_create_last_error' => $effectiveState === self::STATE_CREATED
                        ? null
                        : ($reason ?? $effectiveState),
                ];

                if ($merchantMatches && $referenceMatches) {
                    $updates['provider_reference'] = $invoice->trxReference;
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
        WijayaPayStatusResponse $status,
        string $state,
        ?string $reason,
        ?WijayaPayPayableInvoice $payable = null,
    ): void {
        $this->lifecycleCoordinator->withLockedOrderPayment(
            $payment,
            function (Order $order, Payment $lockedPayment) use ($status, $state, $reason, $payable): void {
                if (filled($lockedPayment->provider_reference)
                    && $lockedPayment->provider_reference !== $status->trxReference
                ) {
                    $state = self::STATE_INVALID_PROVIDER_RESULT;
                    $reason = 'provider_reference_changed';
                }

                $payload = $this->mergeSanitizedPayload(
                    is_array($lockedPayment->provider_payload) ? $lockedPayment->provider_payload : [],
                    $status->sanitizedPayload,
                );
                $updates = [
                    'provider_reference' => $lockedPayment->provider_reference ?: $status->trxReference,
                    'provider_payment_method' => WijayaPayCreateInvoice::PAYMENT_METHOD_QRIS,
                    'provider_total_payment' => $status->totalValue() ?? $lockedPayment->provider_total_payment,
                    'provider_fee' => $status->feeValue() ?? $lockedPayment->provider_fee,
                    'provider_payload' => $payload,
                    'provider_created_at' => $lockedPayment->provider_created_at ?? $status->updatedAt,
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
                    || $this->hasProviderProjection($lockedPayment)
                ) {
                    return false;
                }

                $lockedPayment->update(['provider_create_state' => self::STATE_CREATING]);

                return true;
            },
        );

        if ($reserved) {
            $payment->refresh();
        }

        return $reserved;
    }

    private function markCreationDispatched(Payment $payment): void
    {
        $this->lifecycleCoordinator->withLockedOrderPayment(
            $payment,
            function (Order $_order, Payment $lockedPayment): void {
                $lockedPayment->update(['provider_create_attempted_at' => now()]);
            },
        );

        $payment->refresh();
    }

    private function markCreationOutcomeUnknown(Payment $payment, Throwable|string $error): void
    {
        $errorCode = is_string($error) ? $error : $this->exceptionCode($error);

        $this->lifecycleCoordinator->withLockedOrderPayment(
            $payment,
            function (Order $_order, Payment $lockedPayment) use ($errorCode): void {
                if ($lockedPayment->provider_create_state === self::STATE_CREATED
                    && filled($lockedPayment->provider_reference)
                ) {
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

                $attributes = [
                    'provider_create_state' => self::STATE_CREATE_FAILED,
                    'provider_create_last_error' => $this->exceptionCode($exception),
                ];
                if ($exception instanceof WijayaPayCreatePreRequestException
                    || $exception instanceof WijayaPayReadinessException
                ) {
                    $attributes['provider_create_attempted_at'] = null;
                }
                $lockedPayment->update($attributes);
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
                    'provider_create_last_error' => $lockedPayment->provider_create_last_error ?: $reason,
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
                if (filled($lockedPayment->provider_reference)
                    || $this->hasProviderProjection($lockedPayment)
                    || ! in_array($lockedPayment->provider_create_state, [
                        self::STATE_CREATE_FAILED,
                        self::STATE_RECOVERY_UNCONFIRMED,
                        self::STATE_OUTCOME_UNKNOWN,
                        self::STATE_CREATING,
                    ], true)
                ) {
                    return;
                }

                $lockedPayment->update([
                    'provider_create_state' => null,
                    'provider_create_last_error' => null,
                ]);
            },
        );

        $payment->refresh();
    }

    private function buildCreateRequest(Payment $payment): WijayaPayCreateInvoice
    {
        try {
            $this->readiness->configurationReport()->assertReady();

            if (strtolower(trim((string) $payment->provider)) !== PaymentProvider::WIJAYAPAY
                || strtoupper(trim((string) $payment->method)) !== WijayaPayCreateInvoice::PAYMENT_METHOD_QRIS
                || strtoupper(trim((string) $payment->currency)) !== 'IDR'
            ) {
                throw new WijayaPayCreatePreRequestException('payment_identity');
            }

            $callbackUrl = trim((string) config('services.wijayapay.callback_url'));
            if ($callbackUrl === '') {
                throw new WijayaPayCreatePreRequestException('callback_url');
            }

            $customerName = $payment->order?->customer?->first_name;

            return new WijayaPayCreateInvoice(
                codeMerchant: (string) config('services.wijayapay.code_merchant'),
                apiKey: (string) config('services.wijayapay.api_key'),
                paymentMethod: WijayaPayCreateInvoice::PAYMENT_METHOD_QRIS,
                refId: (string) $payment->merchant_ref,
                amount: (int) $payment->amount,
                callbackUrl: $callbackUrl,
                customerName: is_string($customerName) ? $customerName : null,
            );
        } catch (WijayaPayCreatePreRequestException|WijayaPayReadinessException $exception) {
            throw $exception;
        } catch (InvalidArgumentException|TypeError $exception) {
            throw new WijayaPayCreatePreRequestException('request_invalid', $exception);
        }
    }

    private function classifyLocalState(Payment $payment): ?WijayaPayInvoiceOutcome
    {
        if (strtolower(trim((string) $payment->provider)) !== PaymentProvider::WIJAYAPAY
            || strtoupper(trim((string) $payment->method)) !== WijayaPayCreateInvoice::PAYMENT_METHOD_QRIS
            || strtoupper(trim((string) $payment->currency)) !== 'IDR'
        ) {
            return WijayaPayInvoiceOutcome::InvalidProviderResult;
        }

        if ($payment->status === PaymentStatus::Paid) {
            return WijayaPayInvoiceOutcome::Paid;
        }

        $order = $payment->order;
        if ($payment->status === PaymentStatus::Expired
            || $payment->expired_at?->isPast()
            || $payment->provider_expires_at?->isPast()
            || $order?->expires_at?->isPast()
            || $order?->order_status === OrderStatus::Expired
        ) {
            return WijayaPayInvoiceOutcome::Expired;
        }

        if ($payment->status !== PaymentStatus::Pending
            || $order?->order_status !== OrderStatus::PendingPayment
        ) {
            return WijayaPayInvoiceOutcome::InvalidProviderResult;
        }

        return null;
    }

    private function classifyPersisted(Payment $payment): WijayaPayInvoiceLifecycleResult
    {
        if ($payment->provider_create_state === self::STATE_CREATED) {
            try {
                return WijayaPayInvoiceLifecycleResult::payableResult(
                    WijayaPayPayableInvoice::fromPersistedPayment($payment),
                );
            } catch (WijayaPaySchemaException $exception) {
                return WijayaPayInvoiceLifecycleResult::state(
                    match ($exception->operation) {
                        'payment_identity', 'merchant_reference' => WijayaPayInvoiceOutcome::InvalidProviderResult,
                        'qris_presentation', 'qris_image_presentation', 'presentation_snapshot', 'expiry', 'effective_expiry' => WijayaPayInvoiceOutcome::ProviderPresentationPending,
                        default => WijayaPayInvoiceOutcome::ProviderTotalPending,
                    },
                );
            } catch (Throwable) {
                return WijayaPayInvoiceLifecycleResult::state(WijayaPayInvoiceOutcome::ProviderTotalPending);
            }
        }

        $outcome = match ($payment->provider_create_state) {
            self::STATE_OUTCOME_UNKNOWN,
            self::STATE_RECOVERY_UNCONFIRMED => WijayaPayInvoiceOutcome::RecoveryPending,
            self::STATE_CREATING,
            self::STATE_CREATE_FAILED,
            null => filled($payment->provider_reference)
                ? WijayaPayInvoiceOutcome::RecoveryPending
                : WijayaPayInvoiceOutcome::Creating,
            self::STATE_PROVIDER_TOTAL_PENDING => WijayaPayInvoiceOutcome::ProviderTotalPending,
            self::STATE_PROVIDER_PRESENTATION_PENDING => WijayaPayInvoiceOutcome::ProviderPresentationPending,
            self::STATE_PROVIDER_EXPIRED => WijayaPayInvoiceOutcome::Expired,
            self::STATE_PROVIDER_PAID => WijayaPayInvoiceOutcome::Paid,
            default => WijayaPayInvoiceOutcome::InvalidProviderResult,
        };

        return WijayaPayInvoiceLifecycleResult::state($outcome);
    }

    private function hasNonAdvancingState(Payment $payment): bool
    {
        return in_array($payment->provider_create_state, [
            self::STATE_PROVIDER_EXPIRED,
            self::STATE_PROVIDER_PAID,
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

    private function canRetryCreate(Payment $payment): bool
    {
        return blank($payment->provider_reference)
            && $this->createRetryAllowed($payment)
            && $payment->order !== null
            && $this->canCreate($payment->order, $payment);
    }

    private function createRetryAllowed(Payment $payment): bool
    {
        $intervalSeconds = max(0, (int) config('services.wijayapay.create_retry_min_interval_seconds', 60));
        $attemptedAt = $payment->provider_create_attempted_at;

        return $intervalSeconds === 0
            || $attemptedAt === null
            || $attemptedAt->addSeconds($intervalSeconds)->isPast();
    }

    private function statusIdentityMismatchReason(Payment $payment, WijayaPayStatusResponse $status): ?string
    {
        if ($status->refId !== (string) $payment->merchant_ref) {
            return 'merchant_reference_mismatch';
        }

        if (filled($payment->provider_reference)
            && $status->trxReference !== (string) $payment->provider_reference
        ) {
            return 'provider_reference_mismatch';
        }

        if ($status->totalValue() !== null
            && $payment->provider_total_payment !== null
            && $status->totalValue() !== (int) $payment->provider_total_payment
        ) {
            return 'provider_total_mismatch';
        }

        if ($status->feeValue() !== null
            && $payment->provider_fee !== null
            && $status->feeValue() !== (int) $payment->provider_fee
        ) {
            return 'provider_fee_mismatch';
        }

        $amount = $status->amountValue();
        if ($amount !== null) {
            $expected = self::expectedProviderAmounts($payment);
            if ($status->feeValue() !== null && $status->feeValue() >= 0) {
                $expected[] = (int) $payment->amount + $status->feeValue();
            }

            if (! in_array($amount, array_values(array_unique($expected)), true)) {
                return 'amount_mismatch';
            }
        }

        return null;
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
            throw new WijayaPaySchemaException('expiry');
        }

        $deadlines = array_map(
            static fn (CarbonInterface $deadline): CarbonImmutable => CarbonImmutable::instance($deadline),
            $deadlines,
        );
        usort($deadlines, static fn (CarbonImmutable $left, CarbonImmutable $right): int => $left <=> $right);

        return $deadlines[0];
    }

    /** @param array<mixed> $existing @param array<mixed> $observed @return array<mixed> */
    private function mergeSanitizedPayload(array $existing, array $observed): array
    {
        return WijayaPayPayloadSanitizer::sanitize(array_replace_recursive($existing, $observed));
    }

    private function exceptionCode(Throwable $exception): string
    {
        $code = match (true) {
            $exception instanceof WijayaPayProviderException && filled($exception->providerStatus) => $exception::class.'|application_status:'.$exception->providerStatus,
            $exception instanceof WijayaPayProviderException && $exception->httpStatus !== null => $exception::class.'|http_status:'.$exception->httpStatus,
            default => $exception::class,
        };

        if ($exception instanceof WijayaPayProviderException && filled($exception->providerMessage)) {
            $code .= '|provider_message:'.preg_replace('/\s+/', ' ', (string) $exception->providerMessage);
        }

        return substr($code, 0, 500);
    }

    private function paymentLockLeaseSeconds(): int
    {
        $timeout = config('services.wijayapay.http_timeout_seconds', 10.0);
        $timeout = is_numeric($timeout) && (float) $timeout > 0 ? (float) $timeout : 10.0;

        return max(60, (int) config('services.wijayapay.lock_lease_seconds', 90), (int) ceil($timeout) * 5);
    }
}
