<?php

namespace App\Services\KlikQris;

use App\Enums\KlikQrisEnvironment;
use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Enums\StoreBalanceTopUpStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StoreBalanceTopUp;
use App\Services\PaymentLifecycleCoordinator;
use App\Services\PaymentLifecycleRollout;
use App\Services\ProviderVerification\ProviderVerificationProfiles;
use App\Support\PaymentProvider;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Central KlikQRIS Native QRIS lifecycle.
 *
 * One Payment and Store Balance top-up lifecycle owns dynamic QR create,
 * recovery, persistence, expiry tightening, payability evaluation, and safe
 * outcome classification. Callers only ever receive safe lifecycle outcomes
 * and presentation DTOs; raw provider payloads and persistence-state strings
 * stay internal.
 */
class KlikQrisNativeQrisService
{
    private const PAYMENT_LOCK_WAIT_SECONDS = 10;

    /**
     * @var list<string>
     */
    private const TOP_UP_PRESENTATION_COLUMNS = [
        'id',
        'customer_id',
        'merchant_ref',
        'provider',
        'currency',
        'amount',
        'status',
        'provider_reference',
        'provider_payload',
        'provider_qris_url',
        'provider_fee',
        'provider_total_payment',
        'provider_payment_method',
        'provider_expires_at',
        'expires_at',
        'provider_create_state',
        'created_at',
    ];

    private const OWNER_ALERT_TERMINAL_STATE = 'terminal';

    public function __construct(
        private readonly KlikQrisProviderAdapter $provider,
        private readonly KlikQrisPaymentEventLogger $events,
        private readonly PaymentLifecycleCoordinator $lifecycleCoordinator,
        private readonly PaymentLifecycleRollout $rollout,
        private readonly ?StoreBalanceTopUpEventLogger $topUpEvents = null,
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
                $this->createDiagnostic($payment->provider_create_initial_error),
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
     * Recover a provider-supplied QR image for a payable Store Balance top-up
     * when its trusted URL cannot be rendered. The image stays transient and
     * is never added to the persisted provider payload.
     */
    public function recoverTopUpPresentationImage(StoreBalanceTopUp $topUp): ?KlikQrisPayableBalanceTopUp
    {
        $result = $this->tryWithTopUpLock($topUp, function () use ($topUp): ?KlikQrisPayableBalanceTopUp {
            $topUp->refresh();
            if (! $topUp->isPayable()) {
                return null;
            }

            try {
                $transaction = $this->topUpProvider()->topUpTransactionStatus($topUp);
                $transaction->assertMatchesTopUp($topUp);
                if (! $transaction->isPending() || blank($transaction->qrisImageBase64())) {
                    return null;
                }

                $topUp->refresh();

                $payable = KlikQrisPayableBalanceTopUp::fromTopUpWithTransientImage(
                    $topUp,
                    $transaction->qrisImageBase64(),
                );

                return $payable->fallbackImageContents() === null ? null : $payable;
            } catch (Throwable) {
                return null;
            }
        });

        return $result instanceof KlikQrisPayableBalanceTopUp ? $result : null;
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
            if ($payment->klikqris_status_next_check_at?->isFuture()) {
                return null;
            }

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
        } catch (KlikQrisStatusResponseException $exception) {
            if ($exception->httpStatus === 404) {
                $this->deferMissingStatus($payment);
            }

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    private function deferMissingStatus(Payment $payment): void
    {
        // Discovery metadata must not refresh the terminal-history lookback.
        $this->lifecycleCoordinator->withLockedOrderPayment($payment, function (Order $order, Payment $lockedPayment): void {
            $failures = min(3, (int) $lockedPayment->klikqris_status_failure_count + 1);
            $delay = match ($failures) {
                1 => 300,
                2 => 1800,
                default => 86_400,
            };
            Payment::withoutTimestamps(fn () => $lockedPayment->forceFill([
                'klikqris_status_failure_count' => $failures,
                'klikqris_status_next_check_at' => now()->addSeconds($delay),
                'klikqris_status_last_error' => 'status_http_404',
            ])->save());
        });
        $payment->refresh();
    }

    public function recordAuthoritativeStatusObserved(Payment $payment, KlikQrisTransaction $transaction): void
    {
        $this->lifecycleCoordinator->withLockedOrderPayment($payment, function (Order $order, Payment $lockedPayment) use ($transaction): void {
            if ($lockedPayment->klikqris_status_last_error === null) {
                return;
            }

            $transaction->assertMatches($lockedPayment);
            Payment::withoutTimestamps(fn () => $lockedPayment->forceFill([
                'klikqris_status_failure_count' => 0,
                'klikqris_status_next_check_at' => null,
                'klikqris_status_last_error' => null,
            ])->save());
        });
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

    /**
     * Reduce persisted top-up state to the customer-safe outcome and
     * presentation flags. Provider persistence states never cross this seam.
     */
    public function topUpPresentation(
        StoreBalanceTopUp $topUp,
        ?KlikQrisTopUpLifecycleResult $lifecycle = null,
        bool $refresh = true,
    ): KlikQrisTopUpPresentation {
        if ($refresh) {
            $topUp->refresh();
        }

        $lifecycle ??= $this->classifyTopUp($topUp, refresh: false);
        $outcome = $lifecycle->outcome();
        $providerState = trim((string) $topUp->provider_create_state);

        return KlikQrisTopUpPresentation::fromLifecycle(
            $lifecycle,
            paid: $topUp->status === StoreBalanceTopUpStatus::Paid,
            recovered: $topUp->status === StoreBalanceTopUpStatus::Paid
                && $providerState === 'reconciled_SUCCESS',
            retryable: $topUp->status === StoreBalanceTopUpStatus::Failed
                && in_array($providerState, ['create_failed', 'provider_unavailable'], true),
            manualReview: $topUp->status !== StoreBalanceTopUpStatus::Compensated
                && ($topUp->status === StoreBalanceTopUpStatus::InvalidProviderResult
                    || ($topUp->status !== StoreBalanceTopUpStatus::Expired
                        && $providerState === 'reconciliation_review')),
            checkable: in_array($topUp->status, [
                StoreBalanceTopUpStatus::Pending,
                StoreBalanceTopUpStatus::RecoveryPending,
            ], true) && in_array($outcome, [
                KlikQrisNativeQrisOutcome::Creating,
                KlikQrisNativeQrisOutcome::RecoveryPending,
                KlikQrisNativeQrisOutcome::ProviderTotalPending,
            ], true),
        );
    }

    /**
     * @param  iterable<StoreBalanceTopUp>  $topUps
     * @return array<string, KlikQrisTopUpPresentation>
     */
    public function topUpPresentations(iterable $topUps): array
    {
        $ids = [];
        foreach ($topUps as $topUp) {
            $ids[(int) $topUp->getKey()] = true;
        }

        if ($ids === []) {
            return [];
        }

        $freshTopUps = StoreBalanceTopUp::query()
            ->select(self::TOP_UP_PRESENTATION_COLUMNS)
            ->whereKey(array_keys($ids))
            ->get()
            ->keyBy(static fn (StoreBalanceTopUp $topUp): string => (string) $topUp->getKey());
        $presentations = [];
        foreach (array_keys($ids) as $id) {
            $key = (string) $id;
            $freshTopUp = $freshTopUps->get($key);
            if ($freshTopUp !== null) {
                $presentations[$key] = $this->topUpPresentation($freshTopUp, refresh: false);
            }
        }

        return $presentations;
    }

    public function classifyTopUp(StoreBalanceTopUp $topUp, bool $refresh = true): KlikQrisTopUpLifecycleResult
    {
        if ($refresh) {
            $topUp->refresh();
        }

        if ($topUp->status === StoreBalanceTopUpStatus::Compensated) {
            return KlikQrisTopUpLifecycleResult::state(KlikQrisNativeQrisOutcome::InvalidProviderResult);
        }

        if ($topUp->status === StoreBalanceTopUpStatus::Expired
            || ($topUp->provider_expires_at !== null && ! $topUp->provider_expires_at->isFuture())
            || ($topUp->expires_at !== null && ! $topUp->expires_at->isFuture())) {
            return KlikQrisTopUpLifecycleResult::state(KlikQrisNativeQrisOutcome::Expired);
        }

        if ($topUp->status === StoreBalanceTopUpStatus::Paid
            || $topUp->status === StoreBalanceTopUpStatus::InvalidProviderResult) {
            return KlikQrisTopUpLifecycleResult::state(KlikQrisNativeQrisOutcome::InvalidProviderResult);
        }

        $providerState = trim((string) $topUp->provider_create_state);
        if ($topUp->status === StoreBalanceTopUpStatus::Payable) {
            if ($providerState !== 'created') {
                return KlikQrisTopUpLifecycleResult::state(
                    $providerState === '' || $providerState === 'create_failed'
                        ? KlikQrisNativeQrisOutcome::InvalidProviderResult
                        : $this->topUpOutcomeForProviderState($providerState),
                );
            }

            if (! $topUp->isPayable()) {
                return KlikQrisTopUpLifecycleResult::state(KlikQrisNativeQrisOutcome::InvalidProviderResult);
            }

            try {
                return KlikQrisTopUpLifecycleResult::payableResult(
                    KlikQrisPayableBalanceTopUp::fromTopUp($topUp, refresh: false),
                );
            } catch (RuntimeException) {
                return KlikQrisTopUpLifecycleResult::state(KlikQrisNativeQrisOutcome::InvalidProviderResult);
            }
        }

        return KlikQrisTopUpLifecycleResult::state(
            $this->topUpOutcomeForProviderState($providerState),
        );
    }

    private function topUpOutcomeForProviderState(string $providerState): KlikQrisNativeQrisOutcome
    {
        return match ($providerState) {
            'creating', 'outcome_unknown', 'recovery_unconfirmed' => KlikQrisNativeQrisOutcome::RecoveryPending,
            'provider_total_pending', 'provider_fee_missing' => KlikQrisNativeQrisOutcome::ProviderTotalPending,
            'provider_total_invalid', 'provider_qris_unusable', 'provider_not_payable',
            'provider_SUCCESS', 'provider_signature_missing', 'reconciliation_review' => KlikQrisNativeQrisOutcome::InvalidProviderResult,
            'provider_expired', 'provider_EXPIRED' => KlikQrisNativeQrisOutcome::Expired,
            '', 'create_failed', 'created' => KlikQrisNativeQrisOutcome::Creating,
            default => KlikQrisNativeQrisOutcome::InvalidProviderResult,
        };
    }

    public function lifecycleForTopUp(StoreBalanceTopUp $topUp): KlikQrisTopUpLifecycleResult
    {
        $topUp->refresh();
        $result = $this->classifyTopUp($topUp);
        if ($result->isPayable()
            || in_array($topUp->status, [
                StoreBalanceTopUpStatus::Expired,
                StoreBalanceTopUpStatus::InvalidProviderResult,
                StoreBalanceTopUpStatus::Paid,
                StoreBalanceTopUpStatus::Compensated,
            ], true)
            || $result->outcome() === KlikQrisNativeQrisOutcome::Expired) {
            return $result;
        }

        // A concurrent worker may have only marked the row "creating".
        // Wait for the single-flight lock so callers can observe its committed quote.

        if ($topUp->provider_create_state === 'create_failed') {
            $this->resetTopUpPreRequestFailure($topUp);
            $topUp->refresh();
        }

        try {
            return Cache::lock($this->topUpLockKey($topUp), $this->topUpLockLeaseSeconds())
                ->block(self::PAYMENT_LOCK_WAIT_SECONDS, fn (): KlikQrisTopUpLifecycleResult => $this->createTopUp($topUp));
        } catch (LockTimeoutException) {
            return KlikQrisTopUpLifecycleResult::state(KlikQrisNativeQrisOutcome::Creating);
        }
    }

    /**
     * Recover a top-up through the authoritative status endpoint. A create
     * request is never inferred from this method, so an unknown outcome keeps
     * one merchant reference and one idempotency identity.
     *
     * @param  Closure(StoreBalanceTopUp, KlikQrisTransaction): mixed  $onAuthoritativeStatus
     */
    public function reconcileTopUp(StoreBalanceTopUp $topUp, Closure $onAuthoritativeStatus): ?KlikQrisTopUpLifecycleResult
    {
        $lock = Cache::lock($this->topUpLockKey($topUp), $this->topUpLockLeaseSeconds());
        if (! $lock->get()) {
            return null;
        }

        try {
            $topUp->refresh();
            if (in_array($topUp->status, [
                StoreBalanceTopUpStatus::Paid,
                StoreBalanceTopUpStatus::Compensated,
            ], true)) {
                return $this->classifyTopUp($topUp);
            }

            if ($topUp->provider_create_state === null && ! is_array($topUp->provider_payload)) {
                return KlikQrisTopUpLifecycleResult::state(KlikQrisNativeQrisOutcome::Creating);
            }

            if ($topUp->provider_create_state === 'create_failed') {
                return KlikQrisTopUpLifecycleResult::state(KlikQrisNativeQrisOutcome::Creating);
            }

            $transaction = $this->topUpProvider()->topUpTransactionStatus($topUp);
            $transaction->assertMatchesTopUp($topUp);
            $signaturePersisted = $this->persistAuthoritativeTopUp($topUp, $transaction);
            $current = $topUp->fresh();
            if ($signaturePersisted || $this->observesLateSettlement($current, $transaction)) {
                $onAuthoritativeStatus($current, $transaction);
            }

            return $this->classifyTopUp($current);
        } catch (Throwable $exception) {
            $this->markTopUpRecoveryUnconfirmed($topUp, $exception);

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    public function persistAuthoritativeTopUp(StoreBalanceTopUp $topUp, KlikQrisTransaction $transaction): bool
    {
        return $this->persistTopUpTransaction($topUp, $transaction, false);
    }

    /**
     * A terminal-but-unresolved top-up can still be settled by a verified
     * provider success: persistence refuses to mutate terminal rows, so the
     * success observation itself authorizes the late settlement attempt.
     */
    private function observesLateSettlement(?StoreBalanceTopUp $topUp, KlikQrisTransaction $transaction): bool
    {
        return $topUp !== null
            && $transaction->isSuccess()
            && in_array($topUp->status, [
                StoreBalanceTopUpStatus::Expired,
                StoreBalanceTopUpStatus::InvalidProviderResult,
            ], true);
    }

    public function tryWithTopUpLock(StoreBalanceTopUp $topUp, Closure $callback): mixed
    {
        $lock = Cache::lock($this->topUpLockKey($topUp), $this->topUpLockLeaseSeconds());
        if (! $lock->get()) {
            return null;
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    public function topUpLockKey(StoreBalanceTopUp $topUp): string
    {
        return 'klikqris:balance-top-up:'.$topUp->getKey();
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
                'provider_create_initial_error' => null,
                'provider_create_last_error' => null,
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
        $diagnostic = $this->createDiagnostic($exception);

        $this->lifecycleCoordinator->withLockedOrderPayment($payment, function (Order $order, Payment $lockedPayment) use ($providerException, $diagnostic): void {
            if ($this->hasPayablePersistedQris($lockedPayment)) {
                return;
            }

            $lockedPayment->update([
                'provider_create_state' => 'outcome_unknown',
                'provider_create_last_error' => substr($providerException::class, 0, 500),
                'provider_create_initial_error' => $diagnostic,
            ]);
        });

        $payment->refresh();
    }

    private function markCreationDefinitivelyFailed(Payment $payment, Throwable $exception): void
    {
        $providerException = $exception->getPrevious() ?? $exception;
        $diagnostic = $this->createDiagnostic($exception);

        $this->lifecycleCoordinator->withLockedOrderPayment($payment, function (Order $order, Payment $lockedPayment) use ($providerException, $diagnostic): void {
            if ($this->hasPayablePersistedQris($lockedPayment)) {
                return;
            }

            $lockedPayment->update([
                'provider_create_state' => 'create_failed',
                'provider_create_last_error' => substr($providerException::class, 0, 500),
                'provider_create_initial_error' => $diagnostic,
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
                'provider_create_initial_error' => null,
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

    private function createDiagnostic(Throwable|string|null $value): ?string
    {
        if ($value instanceof KlikQrisCreatePreRequestException) {
            return 'create_request_not_sent';
        }

        if ($value instanceof KlikQrisCreateOutcomeUnknownException
            && $value->httpStatus !== null
            && $value->httpStatus >= 400
            && $value->httpStatus <= 599) {
            return 'create_http_'.$value->httpStatus;
        }

        if ($value instanceof Throwable) {
            $cause = $value->getPrevious() ?? $value;

            return match (true) {
                $cause instanceof ConnectionException => 'create_connection_error',
                $cause instanceof \UnexpectedValueException, $cause instanceof \JsonException => 'create_response_invalid',
                default => 'create_outcome_unknown',
            };
        }

        if ($value === null) {
            return null;
        }

        return in_array($value, [
            'create_request_not_sent', 'create_connection_error', 'create_response_invalid', 'create_outcome_unknown',
        ], true) || preg_match('/\Acreate_http_[45][0-9]{2}\z/', $value) === 1
            ? $value
            : 'create_outcome_unknown';
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

    /** @param array<mixed> $payload */
    private function withoutQrisUrlKeys(array $payload): array
    {
        foreach (array_keys($payload) as $key) {
            $normalized = is_string($key)
                ? (preg_replace('/[^a-z0-9]/', '', strtolower($key)) ?? strtolower($key))
                : '';
            if ($normalized !== ''
                && str_ends_with($normalized, 'url')
                && (str_contains($normalized, 'qris') || str_contains($normalized, 'qr'))) {
                unset($payload[$key]);

                continue;
            }

            if (is_array($payload[$key])) {
                $payload[$key] = $this->withoutQrisUrlKeys($payload[$key]);
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

    private function createTopUp(StoreBalanceTopUp $topUp): KlikQrisTopUpLifecycleResult
    {
        $topUp->refresh();
        $classified = $this->classifyTopUp($topUp);
        if ($classified->isPayable()
            || in_array($topUp->status, [
                StoreBalanceTopUpStatus::Expired,
                StoreBalanceTopUpStatus::InvalidProviderResult,
                StoreBalanceTopUpStatus::Paid,
                StoreBalanceTopUpStatus::Compensated,
            ], true)
            || $classified->outcome() === KlikQrisNativeQrisOutcome::Expired) {
            return $classified;
        }

        if ($topUp->isRecoveryPending()) {
            return KlikQrisTopUpLifecycleResult::state(KlikQrisNativeQrisOutcome::RecoveryPending);
        }

        if (! $this->markTopUpCreationAttempted($topUp)) {
            return $this->classifyTopUp($topUp);
        }

        try {
            $transaction = $this->topUpProvider()->createTopUpTransaction($topUp);
            $transaction->assertMatchesTopUp($topUp);
        } catch (KlikQrisCreatePreRequestException $exception) {
            $this->markTopUpCreationDefinitivelyFailed($topUp, $exception);

            throw $exception;
        } catch (Throwable $exception) {
            $this->markTopUpCreationOutcomeUnknown($topUp, $exception);

            return KlikQrisTopUpLifecycleResult::state(KlikQrisNativeQrisOutcome::RecoveryPending);
        }

        if (! $this->persistCreatedTopUp($topUp, $transaction)) {
            $this->markTopUpCreationOutcomeUnknown($topUp, new RuntimeException('Store Balance top-up is no longer active.'));

            return KlikQrisTopUpLifecycleResult::state(KlikQrisNativeQrisOutcome::Expired);
        }

        return $this->classifyTopUp($topUp);
    }

    private function persistCreatedTopUp(StoreBalanceTopUp $topUp, KlikQrisTransaction $transaction): bool
    {
        return $this->persistTopUpTransaction($topUp, $transaction, true);
    }

    private function persistTopUpTransaction(
        StoreBalanceTopUp $topUp,
        KlikQrisTransaction $transaction,
        bool $fromCreate,
    ): bool {
        return DB::transaction(function () use ($topUp, $transaction, $fromCreate): bool {
            $lockedTopUp = StoreBalanceTopUp::query()
                ->whereKey($topUp->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if (in_array($lockedTopUp->status, [
                StoreBalanceTopUpStatus::Expired,
                StoreBalanceTopUpStatus::InvalidProviderResult,
            ], true)) {
                return false;
            }

            if ($lockedTopUp->status === StoreBalanceTopUpStatus::Paid
                || $lockedTopUp->status === StoreBalanceTopUpStatus::Compensated) {
                return true;
            }

            $providerExpiresAt = $transaction->expiresAt()?->setTimezone((string) config('app.timezone'))
                ?? $lockedTopUp->provider_expires_at;
            $existingQrisUrl = filled($lockedTopUp->provider_qris_url)
                && KlikQrisTransaction::isAllowedQrisUrl(
                    (string) $lockedTopUp->provider_qris_url,
                    environment: $transaction->environment(),
                )
                ? (string) $lockedTopUp->provider_qris_url
                : null;
            $providerQrisUrl = filled($transaction->qrisUrl())
                && KlikQrisTransaction::isAllowedQrisUrl(
                    $transaction->qrisUrl(),
                    environment: $transaction->environment(),
                )
                ? $transaction->qrisUrl()
                : $existingQrisUrl;
            $providerTotal = $transaction->totalAmount();
            // KlikQRIS does not always echo a fee field. Orders already derive
            // it from the final total; top-ups must do the same, otherwise a
            // fee-less quote could never become payable.
            $providerFee = $transaction->providerFee()
                ?? ($providerTotal >= (int) $lockedTopUp->amount ? $providerTotal - (int) $lockedTopUp->amount : null)
                ?? $lockedTopUp->provider_fee;
            $topUpExpiresAt = $lockedTopUp->expires_at;
            if ($providerExpiresAt !== null
                && ($topUpExpiresAt === null || $providerExpiresAt->lt($topUpExpiresAt))) {
                $topUpExpiresAt = $providerExpiresAt;
            }
            $signaturePersisted = $this->persistTopUpTransactionSignature($lockedTopUp, $transaction);
            $isPayable = $transaction->isPending()
                && $providerFee !== null
                && $providerFee >= 0
                && $providerTotal >= (int) $lockedTopUp->amount
                && $providerQrisUrl !== null
                && $providerExpiresAt !== null
                && $providerExpiresAt->isFuture()
                && $topUpExpiresAt !== null
                && $topUpExpiresAt->isFuture()
                && $signaturePersisted;
            $state = $this->topUpProviderState(
                $transaction,
                $lockedTopUp,
                $providerQrisUrl,
                $providerFee,
                $providerExpiresAt,
                $signaturePersisted,
                $isPayable,
            );

            $status = match (true) {
                $transaction->isSuccess() => StoreBalanceTopUpStatus::Pending,
                $transaction->status() === 'EXPIRED'
                    || ($topUpExpiresAt !== null && ! $topUpExpiresAt->isFuture()) => StoreBalanceTopUpStatus::Expired,
                $isPayable => StoreBalanceTopUpStatus::Payable,
                default => StoreBalanceTopUpStatus::Pending,
            };

            $providerPayload = $this->withoutQrisUrlKeys($transaction->sanitizedPayload());
            $updates = [
                'provider_reference' => $transaction->providerReference() ?? $lockedTopUp->provider_reference,
                'provider_payload' => $providerPayload,
                'provider_qris_url' => $providerQrisUrl,
                'provider_fee' => $providerFee,
                'provider_total_payment' => $providerTotal,
                'provider_payment_method' => 'qris',
                'provider_expires_at' => $providerExpiresAt,
                'expires_at' => $topUpExpiresAt ?? $lockedTopUp->expires_at,
                'status' => $status,
                'provider_create_state' => $isPayable ? 'created' : $state,
                'provider_create_last_error' => $isPayable ? null : $state,
                'paid_at' => $transaction->isSuccess()
                    ? ($transaction->paidAt() ?? $lockedTopUp->paid_at ?? now())
                    : $lockedTopUp->paid_at,
            ];
            if ($fromCreate) {
                $updates['provider_created_at'] = now();
            }

            $lockedTopUp->forceFill($updates)->save();
            if ($fromCreate
                && $isPayable
                && ! $this->hasTopUpProviderCreateEvent($lockedTopUp)) {
                $this->topUpEventLogger()->logProviderCreate($lockedTopUp, $transaction);
            }

            return $signaturePersisted;
        }, 5);
    }

    private function topUpProviderState(
        KlikQrisTransaction $transaction,
        StoreBalanceTopUp $topUp,
        ?string $providerQrisUrl,
        ?int $providerFee,
        mixed $providerExpiresAt,
        bool $signaturePersisted,
        bool $isPayable,
    ): string {
        if ($isPayable) {
            return 'created';
        }

        if ($transaction->isSuccess()) {
            return 'provider_SUCCESS';
        }

        if ($transaction->status() === 'EXPIRED') {
            return 'provider_EXPIRED';
        }

        if ($topUp->expires_at !== null && ! $topUp->expires_at->isFuture()) {
            return 'provider_expired';
        }

        if (! $signaturePersisted) {
            return 'provider_signature_missing';
        }

        if ($providerFee === null) {
            return 'provider_fee_missing';
        }

        if ($transaction->totalAmount() < (int) $topUp->amount) {
            return 'provider_total_invalid';
        }

        if ($providerQrisUrl === null) {
            return 'provider_qris_unusable';
        }

        if ($providerExpiresAt === null || ! $providerExpiresAt->isFuture()) {
            return 'provider_expired';
        }

        return 'provider_not_payable';
    }

    private function persistTopUpTransactionSignature(StoreBalanceTopUp $topUp, KlikQrisTransaction $transaction): bool
    {
        $signature = $transaction->signature();
        $existing = $topUp->providerSignature();
        if (blank($signature)) {
            return $existing !== null || $transaction->environment() !== KlikQrisEnvironment::Production;
        }

        if ($existing === null) {
            $topUp->forceFill(['provider_signature' => $signature])->save();

            return true;
        }

        if (hash_equals($existing, $signature)) {
            return true;
        }

        report(new RuntimeException('KlikQRIS top-up transaction signature changed.'));

        return false;
    }

    private function markTopUpCreationAttempted(StoreBalanceTopUp $topUp): bool
    {
        $reserved = DB::transaction(function () use ($topUp): bool {
            $locked = StoreBalanceTopUp::query()
                ->whereKey($topUp->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ($locked->status === StoreBalanceTopUpStatus::Paid
                || $locked->status === StoreBalanceTopUpStatus::Compensated
                || $locked->status === StoreBalanceTopUpStatus::InvalidProviderResult
                || $locked->status === StoreBalanceTopUpStatus::Expired
                || $locked->provider_create_state !== null
                || is_array($locked->provider_payload)) {
                return false;
            }

            if ($locked->expires_at !== null && ! $locked->expires_at->isFuture()) {
                $locked->forceFill([
                    'status' => StoreBalanceTopUpStatus::Expired,
                    'provider_create_state' => 'provider_expired',
                    'provider_create_last_error' => 'provider_expired',
                ])->save();

                return false;
            }

            $locked->forceFill([
                'status' => StoreBalanceTopUpStatus::Pending,
                'provider_create_state' => 'creating',
                'provider_create_attempted_at' => now(),
                'provider_create_last_error' => null,
                'provider_create_initial_error' => null,
            ])->save();

            return true;
        }, 5);

        if ($reserved) {
            $topUp->refresh();
        }

        return $reserved;
    }

    private function markTopUpCreationOutcomeUnknown(StoreBalanceTopUp $topUp, Throwable $exception): void
    {
        $providerException = $exception->getPrevious() ?? $exception;
        $diagnostic = $this->createDiagnostic($exception);

        DB::transaction(function () use ($topUp, $providerException, $diagnostic): void {
            $locked = StoreBalanceTopUp::query()
                ->whereKey($topUp->getKey())
                ->lockForUpdate()
                ->first();
            if ($locked === null
                || $locked->status === StoreBalanceTopUpStatus::Paid
                || $locked->status === StoreBalanceTopUpStatus::Compensated
                || $locked->isPayable()) {
                return;
            }

            $locked->forceFill([
                'status' => StoreBalanceTopUpStatus::RecoveryPending,
                'provider_create_state' => 'outcome_unknown',
                'provider_create_last_error' => substr($providerException::class, 0, 500),
                'provider_create_initial_error' => $diagnostic,
            ])->save();
        }, 5);

        $topUp->refresh();
    }

    private function markTopUpCreationDefinitivelyFailed(StoreBalanceTopUp $topUp, Throwable $exception): void
    {
        $providerException = $exception->getPrevious() ?? $exception;
        $diagnostic = $this->createDiagnostic($exception);

        DB::transaction(function () use ($topUp, $providerException, $diagnostic): void {
            $locked = StoreBalanceTopUp::query()
                ->whereKey($topUp->getKey())
                ->lockForUpdate()
                ->first();
            if ($locked === null
                || $locked->status === StoreBalanceTopUpStatus::Paid
                || $locked->status === StoreBalanceTopUpStatus::Compensated
                || $locked->isPayable()) {
                return;
            }

            $locked->forceFill([
                'status' => StoreBalanceTopUpStatus::Failed,
                'provider_create_state' => 'create_failed',
                'provider_create_last_error' => substr($providerException::class, 0, 500),
                'provider_create_initial_error' => $diagnostic,
            ])->save();
        }, 5);

        $topUp->refresh();
    }

    private function resetTopUpPreRequestFailure(StoreBalanceTopUp $topUp): void
    {
        DB::transaction(function () use ($topUp): void {
            $locked = StoreBalanceTopUp::query()
                ->whereKey($topUp->getKey())
                ->lockForUpdate()
                ->first();
            if ($locked === null || $locked->provider_create_state !== 'create_failed') {
                return;
            }

            $locked->forceFill([
                'status' => StoreBalanceTopUpStatus::Pending,
                'provider_create_state' => null,
                'provider_create_last_error' => null,
                'provider_create_initial_error' => null,
            ])->save();
        }, 5);
    }

    private function markTopUpRecoveryUnconfirmed(StoreBalanceTopUp $topUp, Throwable $exception): void
    {
        DB::transaction(function () use ($topUp, $exception): void {
            $locked = StoreBalanceTopUp::query()
                ->whereKey($topUp->getKey())
                ->lockForUpdate()
                ->first();
            if ($locked === null
                || $locked->status === StoreBalanceTopUpStatus::Paid
                || $locked->status === StoreBalanceTopUpStatus::Compensated) {
                return;
            }

            $locked->forceFill([
                'status' => StoreBalanceTopUpStatus::RecoveryPending,
                'provider_create_state' => 'recovery_unconfirmed',
                'provider_create_last_error' => $this->recoveryDiagnostic($exception),
            ])->save();
        }, 5);

        $topUp->refresh();
    }

    private function topUpProvider(): KlikQrisTopUpProviderAdapter
    {
        if (! $this->provider instanceof KlikQrisTopUpProviderAdapter) {
            throw new KlikQrisCreatePreRequestException(
                'KlikQRIS top-up create request was not sent: top-up adapter is unavailable.',
            );
        }

        return $this->provider;
    }

    private function topUpLockLeaseSeconds(): int
    {
        return max(60, ProviderVerificationProfiles::requiredLeaseSeconds());
    }

    private function hasTopUpProviderCreateEvent(StoreBalanceTopUp $topUp): bool
    {
        return $topUp->events()
            ->where('provider', PaymentProvider::KLIKQRIS)
            ->where('event_type', 'provider_create')
            ->exists();
    }

    private function topUpEventLogger(): StoreBalanceTopUpEventLogger
    {
        return $this->topUpEvents ?? app(StoreBalanceTopUpEventLogger::class);
    }
}
