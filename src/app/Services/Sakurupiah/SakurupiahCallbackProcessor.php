<?php

namespace App\Services\Sakurupiah;

use App\Enums\OrderStatus;
use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Enums\SakurupiahEnvironment;
use App\Jobs\VerifySakurupiahWebhookPayment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Services\MoneyReceipts\ExternalMoneyReceiptWriter;
use App\Services\OrderLifecycle;
use App\Support\PaymentProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verifies Sakurupiah callback signals before delegating lifecycle mutation.
 *
 * A callback never authorizes a Payment by itself. Successful and expired
 * signals are correlated to the persisted provider reference, re-read from
 * the provider under the shared Payment lock, and only then sent to
 * OrderLifecycle.
 */
final class SakurupiahCallbackProcessor
{
    private const VERIFICATION_QUEUE = 'default';

    public function __construct(
        private readonly OrderLifecycle $orders,
        private readonly SakurupiahProviderAdapter $provider,
        private readonly SakurupiahInvoiceLifecycleService $lifecycle,
        private readonly SakurupiahPaymentEventLogger $events,
        private readonly ExternalMoneyReceiptWriter $receipts,
        private readonly SakurupiahReconciliationQuery $reconciliationQuery,
    ) {}

    public function authenticatesCallback(
        string $rawBody,
        mixed $providedSignature,
        ?SakurupiahEnvironment $environment = null,
    ): bool {
        $environment ??= SakurupiahEnvironment::runtime();
        $apiKey = config($environment->configKey('api_key'));

        return SakurupiahSignature::verifyCallback($rawBody, $providedSignature, $apiKey);
    }

    /**
     * @param  SakurupiahCallback|array<mixed>  $callback
     */
    public function processCallback(SakurupiahCallback|array $callback): PaymentEvent
    {
        if (is_array($callback)) {
            $callback = SakurupiahCallback::fromPayload($callback);
        }

        $payment = $this->findPayment($callback->merchantRef);
        $event = $this->events->logCallback($callback, $payment);

        return $this->processEvent($event, $callback, $payment, deferVerification: true);
    }

    public function processQueuedEvent(PaymentEvent $event): PaymentEvent
    {
        if ($event->provider !== PaymentProvider::SAKURUPIAH
            || $event->event_type !== PaymentEventType::Callback) {
            return $event->fresh();
        }

        $payload = $event->raw_payload;
        if (! is_array($payload)) {
            return $this->markProcessed($event);
        }

        $callback = SakurupiahCallback::fromPayload($payload);
        $payment = $event->payment_id === null
            ? $this->findPayment($callback->merchantRef)
            : Payment::query()->find($event->payment_id);

        return $this->processEvent($event, $callback, $payment, deferVerification: false);
    }

    /**
     * Reconcile one persisted Sakurupiah Payment from an authoritative status
     * lookup under the same lock used by create/callback/refresh work.
     *
     * This path never issues a create request: a Payment without a confirmed
     * provider reference cannot be recovered here and stays verification
     * pending. Authoritative successful/expired observations are delegated to
     * OrderLifecycle exactly like verified callbacks.
     */
    public function reconcilePayment(Payment $payment, bool $scheduled = false): SakurupiahReconciliationOutcome
    {

        if ($payment->status === PaymentStatus::Paid || ! $this->isEligiblePayment($payment)) {
            return SakurupiahReconciliationOutcome::Pending;
        }

        if (blank($payment->provider_reference)) {
            $event = $payment->paymentEvents()->where('provider', PaymentProvider::SAKURUPIAH)
                ->where('event_type', PaymentEventType::Callback->value)
                ->where('provider_status', SakurupiahTransactionStatus::Successful->value)
                ->whereNull('processed_at')->oldest('received_at')->first();
            if ($event !== null) {
                try {
                    $this->processQueuedEvent($event);
                } catch (SakurupiahStatusUnavailableException) {
                    return SakurupiahReconciliationOutcome::VerificationPending;
                }

                return $payment->fresh()->status === PaymentStatus::Paid
                    ? SakurupiahReconciliationOutcome::Paid
                    : SakurupiahReconciliationOutcome::VerificationPending;
            }
        }

        $result = $this->lifecycle->tryWithPaymentLock(
            $payment,
            fn (): SakurupiahReconciliationOutcome => $this->reconcileLocked($payment, $scheduled),
        );

        return $result ?? SakurupiahReconciliationOutcome::Busy;
    }

    private function reconcileLocked(Payment $payment, bool $scheduled): SakurupiahReconciliationOutcome
    {
        $this->refreshPaymentAttributes($payment);

        // The order may have expired or been cancelled after the scan selected it.
        if ($payment->status === PaymentStatus::Paid
            || ($scheduled && ! $this->reconciliationQuery->eligible()->whereKey($payment->getKey())->exists())) {
            return SakurupiahReconciliationOutcome::Pending;
        }

        $providerReference = trim((string) $payment->provider_reference);
        if ($providerReference === '') {
            // No provider reference exists yet. A definitively rejected create
            // is retried on the same merchant reference; an ambiguous outcome
            // only records the missing observation.
            $recovered = $this->lifecycle->recoverWhileLocked($payment);

            return $recovered->isPayable()
                ? SakurupiahReconciliationOutcome::Pending
                : SakurupiahReconciliationOutcome::VerificationPending;
        }

        try {
            $status = $this->fetchStatus($payment);
        } catch (SakurupiahStatusUnavailableException) {
            if ($this->lifecycle->classify($payment)->isPayable()) {
                // A lookup outage or pre-dispatch deferral says nothing about
                // an already validated QR. Keep that invoice usable.
                $this->events->logRecoveryObservation(
                    $payment,
                    SakurupiahInvoiceLifecycleService::STATE_RECOVERY_UNCONFIRMED,
                    'status_unavailable',
                );
            } else {
                $this->lifecycle->persistRecoveryUnconfirmed($payment, 'status_unavailable');
            }

            return SakurupiahReconciliationOutcome::VerificationPending;
        }

        $identityReason = $this->statusIdentityMismatchReason($payment, $status);
        if ($identityReason !== null) {
            $this->lifecycle->persistAuthoritativeStatusObservation(
                $payment,
                $status,
                SakurupiahInvoiceLifecycleService::STATE_INVALID_PROVIDER_RESULT,
                $identityReason,
            );

            return SakurupiahReconciliationOutcome::Invalid;
        }

        if ($status->isSuccessful()) {
            // Keep the authoritative success indication durable even if the
            // subsequent amount/history lookup fails or the order expires.
            $this->lifecycle->persistAuthoritativeStatusObservation(
                $payment,
                $status,
                SakurupiahInvoiceLifecycleService::STATE_PROVIDER_BERHASIL,
                'authoritative_amount_unverified',
            );
            try {
                $evidenceStatus = $this->statusWithAmountEvidence($payment, $status);
            } catch (SakurupiahStatusUnavailableException) {
                $this->lifecycle->persistRecoveryUnconfirmed($payment, 'history_unavailable');

                return SakurupiahReconciliationOutcome::VerificationPending;
            }
            if ($evidenceStatus === null) {
                $this->lifecycle->persistAuthoritativeStatusObservation(
                    $payment,
                    $status,
                    SakurupiahInvoiceLifecycleService::STATE_PROVIDER_BERHASIL,
                    'authoritative_amount_unverified',
                );

                return SakurupiahReconciliationOutcome::VerificationPending;
            }

            $this->lifecycle->persistAuthoritativeStatusObservation(
                $payment,
                $evidenceStatus,
                SakurupiahInvoiceLifecycleService::STATE_PROVIDER_BERHASIL,
            );
            $this->expireOverduePendingPayment($payment);

            // The global receipt registry owns the physical money identity: an
            // already claimed or held receipt never marks this payment paid.
            if ($this->receipts->claimProviderPayment($payment)->isHeld()) {
                return SakurupiahReconciliationOutcome::VerificationPending;
            }

            $this->orders->applyPaymentPaid($payment);

            return SakurupiahReconciliationOutcome::Paid;
        }

        if ($status->isExpired()) {
            if ($this->reconciliationQuery->hasSuccessIndication($payment)) {
                $this->events->logReconciliation($payment, $status);

                return SakurupiahReconciliationOutcome::VerificationPending;
            }
            $this->lifecycle->persistAuthoritativeStatusObservation(
                $payment,
                $status,
                SakurupiahInvoiceLifecycleService::STATE_PROVIDER_EXPIRED,
                'provider_expired',
            );
            if ($payment->status === PaymentStatus::Pending) {
                $this->orders->applyPaymentExpired($payment);
            }

            return SakurupiahReconciliationOutcome::Expired;
        }

        if (! $this->lifecycle->classify($payment)->isPayable()) {
            $this->lifecycle->recoverPendingObservationWhileLocked($payment, $status);
        } else {
            $this->events->logReconciliation($payment, $status);
        }

        return SakurupiahReconciliationOutcome::Pending;
    }

    private function processEvent(
        PaymentEvent $event,
        SakurupiahCallback $callback,
        ?Payment $payment,
        bool $deferVerification,
    ): PaymentEvent {
        if ($event->processed_at !== null) {
            return $event->fresh();
        }

        if ($payment === null
            || ! $this->isEligiblePayment($payment)
            || $callback->isPending()) {
            return $this->markProcessed($event);
        }

        if ($payment->status === PaymentStatus::Paid) {
            return $this->markProcessed($event);
        }

        if (trim((string) $payment->provider_reference) === '' && ! $callback->isSuccessful()) {
            if (! $deferVerification) {
                throw new SakurupiahStatusUnavailableException;
            }

            $this->deferVerification($event, true);

            return $event->fresh();
        }

        try {
            $result = $this->lifecycle->tryWithPaymentLock(
                $payment,
                fn (): array => $this->verifyAndApplyLocked($payment, $callback),
            );
        } catch (SakurupiahStatusUnavailableException $exception) {
            $this->events->logRecoveryObservation(
                $payment,
                SakurupiahInvoiceLifecycleService::STATE_RECOVERY_UNCONFIRMED,
                'status_unavailable',
            );

            if (! $deferVerification) {
                throw $exception;
            }

            $this->deferVerification($event, true);

            return $event->fresh();
        }

        if ($result === null) {
            if (! $deferVerification) {
                throw new SakurupiahStatusUnavailableException;
            }

            $this->deferVerification($event, true);

            return $event->fresh();
        }

        if ($result['defer']) {
            $this->deferVerification($event, $deferVerification);

            return $event->fresh();
        }

        if ($result['processed']) {
            return $this->markProcessed($event);
        }

        return $event->fresh();
    }

    /**
     * @return array{processed: bool, defer: bool}
     */
    private function verifyAndApplyLocked(Payment $payment, SakurupiahCallback $callback): array
    {
        $payment = Payment::query()->find($payment->getKey());
        if ($payment === null
            || ! $this->isEligiblePayment($payment)
            || $payment->status === PaymentStatus::Paid
            || (string) $payment->merchant_ref !== $callback->merchantRef) {
            return ['processed' => true, 'defer' => false];
        }
        $providerReference = trim((string) $payment->provider_reference);

        if ($providerReference === '') {
            // A signed success signal supplies a lookup key, not payment proof.
            // Bind it only after the provider independently confirms identity.
            try {
                $status = $this->provider->transactionStatus($callback->trxId);
            } catch (Throwable $exception) {
                $this->logLookupFailure($payment, 'transactionStatus', $exception);
                throw new SakurupiahStatusUnavailableException(previous: $exception);
            }
            $payment->provider_reference = $callback->trxId;
            if (! $status->isSuccessful() || $this->statusIdentityMismatchReason($payment, $status) !== null) {
                return ['processed' => false, 'defer' => true];
            }
            $history = null;
            if ($status->merchantRef === null || $status->paymentCode === null) {
                $history = $this->fetchMatchingHistory($payment);
                if ($history === null || ! in_array($history->amountValue(), SakurupiahInvoiceLifecycleService::expectedProviderAmounts($payment), true)
                    || ($history->currency !== null && strtoupper(trim($history->currency)) !== strtoupper(trim((string) $payment->currency)))) {
                    return ['processed' => false, 'defer' => true];
                }
            } elseif ($status->merchantRef !== (string) $payment->merchant_ref) {
                return ['processed' => false, 'defer' => true];
            }
            $evidenceStatus = $this->statusWithAmountEvidence($payment, $status, $history);
            if ($evidenceStatus === null) {
                return ['processed' => false, 'defer' => true];
            }
            $this->lifecycle->persistAuthoritativeStatusObservation($payment, $evidenceStatus, SakurupiahInvoiceLifecycleService::STATE_PROVIDER_BERHASIL);
            $this->expireOverduePendingPayment($payment);
            if ($this->receipts->claimProviderPayment($payment)->isHeld()) {
                return ['processed' => false, 'defer' => false];
            }
            $this->orders->applyPaymentPaid($payment);

            return ['processed' => true, 'defer' => false];
        }

        if ($providerReference !== $callback->trxId) {
            return ['processed' => true, 'defer' => false];
        }

        $status = $this->fetchStatus($payment);
        $identityReason = $this->statusIdentityMismatchReason($payment, $status);
        if ($identityReason !== null) {
            $this->lifecycle->persistAuthoritativeStatusObservation(
                $payment,
                $status,
                SakurupiahInvoiceLifecycleService::STATE_INVALID_PROVIDER_RESULT,
                $identityReason,
            );

            return ['processed' => true, 'defer' => false];
        }

        if ($callback->isSuccessful()) {
            if (! $status->isSuccessful()) {
                $this->events->logReconciliation($payment, $status);

                return ['processed' => false, 'defer' => true];
            }

            $this->lifecycle->persistAuthoritativeStatusObservation(
                $payment,
                $status,
                SakurupiahInvoiceLifecycleService::STATE_PROVIDER_BERHASIL,
                'authoritative_amount_unverified',
            );
            $evidenceStatus = $this->statusWithAmountEvidence($payment, $status);
            if ($evidenceStatus === null) {
                $this->lifecycle->persistAuthoritativeStatusObservation(
                    $payment,
                    $status,
                    SakurupiahInvoiceLifecycleService::STATE_PROVIDER_BERHASIL,
                    'authoritative_amount_unverified',
                );

                return ['processed' => false, 'defer' => true];
            }

            $this->lifecycle->persistAuthoritativeStatusObservation(
                $payment,
                $evidenceStatus,
                SakurupiahInvoiceLifecycleService::STATE_PROVIDER_BERHASIL,
            );
            $this->expireOverduePendingPayment($payment);

            if ($this->receipts->claimProviderPayment($payment)->isHeld()) {
                return ['processed' => false, 'defer' => false];
            }

            $this->orders->applyPaymentPaid($payment);

            return ['processed' => true, 'defer' => false];
        }

        if ($callback->isExpired()) {
            if (! $status->isExpired()) {
                $this->events->logReconciliation($payment, $status);

                return ['processed' => false, 'defer' => true];
            }

            if ($this->reconciliationQuery->hasSuccessIndication($payment)) {
                $this->events->logReconciliation($payment, $status);

                return ['processed' => false, 'defer' => true];
            }

            if ($payment->status !== PaymentStatus::Pending) {
                $this->events->logReconciliation($payment, $status);

                return ['processed' => true, 'defer' => false];
            }
            $this->lifecycle->persistAuthoritativeStatusObservation(
                $payment,
                $status,
                SakurupiahInvoiceLifecycleService::STATE_PROVIDER_EXPIRED,
                'provider_expired',
            );
            $this->orders->applyPaymentExpired($payment);

            return ['processed' => true, 'defer' => false];
        }

        return ['processed' => true, 'defer' => false];
    }

    private function expireOverduePendingPayment(Payment $payment): void
    {
        if ($payment->status !== PaymentStatus::Pending) {
            return;
        }

        $order = $payment->relationLoaded('order') ? $payment->order : $payment->load('order')->order;
        if ($order === null
            || $order->order_status !== OrderStatus::PendingPayment
            || ! $this->deadlinePassed($payment, $order)) {
            return;
        }

        $this->orders->applyPaymentExpired($payment);
        $this->refreshPaymentAttributes($payment);
    }

    private function deadlinePassed(Payment $payment, Order $order): bool
    {
        return $payment->expired_at?->isPast() === true
            || $payment->provider_expires_at?->isPast() === true
            || $order->expires_at?->isPast() === true;
    }

    private function fetchStatus(Payment $payment): SakurupiahStatus
    {
        try {
            return $this->provider->transactionStatus((string) $payment->provider_reference);
        } catch (Throwable $exception) {
            $this->logLookupFailure($payment, 'transactionStatus', $exception);

            throw new SakurupiahStatusUnavailableException(previous: $exception);
        }
    }

    private function statusWithAmountEvidence(Payment $payment, SakurupiahStatus $status, ?SakurupiahHistoryItem $history = null): ?SakurupiahStatus
    {
        $expectedAmounts = SakurupiahInvoiceLifecycleService::expectedProviderAmounts($payment);

        if ($status->amountValue() !== null) {
            return in_array($status->amountValue(), $expectedAmounts, true) ? $status : null;
        }

        $history ??= $this->fetchMatchingHistory($payment);
        if ($history === null
            || ! in_array($history->amountValue(), $expectedAmounts, true)
            || ($history->currency !== null
                && strtoupper(trim($history->currency)) !== strtoupper(trim((string) $payment->currency)))) {
            return null;
        }

        return new SakurupiahStatus(
            trxId: $status->trxId,
            status: $status->status,
            merchantRef: $status->merchantRef ?? $history->merchantRef,
            paymentCode: $status->paymentCode ?? $history->paymentCode,
            amount: $status->amount ?? $history->amount,
            occurredAt: $status->occurredAt ?? $history->occurredAt,
            expiresAt: $status->expiresAt ?? $history->expiresAt,
            sanitizedPayload: array_replace_recursive($status->sanitizedPayload(), $history->sanitizedPayload()),
            total: $status->total,
            fee: $status->fee,
            qr: $status->qrPayload(),
            checkoutUrl: $status->checkoutUrl(),
            merchantFee: $status->merchantFee(),
            currency: $status->currency ?? $history->currency,
        );
    }

    private function fetchMatchingHistory(Payment $payment): ?SakurupiahHistoryItem
    {
        $providerReference = trim((string) $payment->provider_reference);
        $merchantRef = (string) $payment->merchant_ref;
        $channel = SakurupiahChannelPolicy::forPayment($payment);

        try {
            $history = $this->provider->transactionHistory(new SakurupiahHistoryFilter(
                mechant: 1,
                trxId: $providerReference,
                merchantRef: $merchantRef,
            ));
        } catch (Throwable $exception) {
            $this->logLookupFailure($payment, 'transactionHistory', $exception);

            throw new SakurupiahStatusUnavailableException(previous: $exception);
        }

        $matches = array_values(array_filter(
            $history,
            static fn (SakurupiahHistoryItem $item): bool => $item->trxId === $providerReference
                && $item->merchantRef === $merchantRef
                && strcasecmp($item->paymentCode, $channel) === 0
                && $item->isSuccessful(),
        ));

        if (count($matches) !== 1) {
            return null;
        }

        return $matches[0];
    }

    private function logLookupFailure(Payment $payment, string $operation, Throwable $exception): void
    {
        Log::warning('Sakurupiah verification lookup failed.', [
            'payment_id' => $payment->getKey(),
            'operation' => $operation,
            'exception_class' => $exception::class,
            'reason' => $exception instanceof SakurupiahProviderException ? $exception->reason : 'unexpected',
            'http_status' => $exception instanceof SakurupiahProviderException ? $exception->httpStatus : null,
            'provider_status' => $exception instanceof SakurupiahProviderException ? $exception->providerStatus : null,
        ]);
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
            && strcasecmp($status->paymentCode, SakurupiahChannelPolicy::forPayment($payment)) !== 0) {
            return 'provider_payment_method_mismatch';
        }
        if ($status->currency !== null
            && strtoupper(trim($status->currency)) !== strtoupper(trim((string) $payment->currency))) {
            return 'provider_currency_mismatch';
        }

        if ($status->totalValue() !== null && $status->totalValue() < (int) $payment->amount) {
            return 'provider_total_below_amount';
        }

        if ($status->totalValue() !== null
            && $payment->provider_total_payment !== null
            && $status->totalValue() !== (int) $payment->provider_total_payment) {
            return 'provider_total_mismatch';
        }

        if ($status->feeValue() !== null
            && $payment->provider_fee !== null
            && $status->feeValue() !== (int) $payment->provider_fee) {
            return 'provider_fee_mismatch';
        }

        return null;
    }

    private function isEligiblePayment(Payment $payment): bool
    {
        return strtolower(trim((string) $payment->provider)) === PaymentProvider::SAKURUPIAH
            && in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Expired, PaymentStatus::Failed], true)
            && strcasecmp(trim((string) $payment->method), SakurupiahCreateInvoice::PAYMENT_METHOD_QRIS) === 0
            && strtoupper(trim((string) $payment->currency)) === 'IDR';
    }

    private function refreshPaymentAttributes(Payment $payment): void
    {
        $fresh = $payment->newQueryWithoutScopes()
            ->select(SakurupiahReconciliationQuery::PAYMENT_COLUMNS)
            ->useWritePdo()
            ->whereKey($payment->getKey())
            ->firstOrFail();

        $payment->setRawAttributes(
            array_replace($payment->getAttributes(), $fresh->getAttributes()),
        );
        $payment->syncOriginal();
    }

    private function findPayment(string $merchantRef): ?Payment
    {
        return Payment::query()
            ->where('provider', PaymentProvider::SAKURUPIAH)
            ->where('merchant_ref', $merchantRef)
            ->first();
    }

    private function markProcessed(PaymentEvent $event): PaymentEvent
    {
        return DB::transaction(function () use ($event): PaymentEvent {
            $locked = PaymentEvent::query()->whereKey($event->getKey())->lockForUpdate()->first();
            if ($locked === null) {
                return $event;
            }

            if ($locked->processed_at === null) {
                $locked->forceFill(['processed_at' => now()])->save();
            }

            return $locked->fresh();
        }, 5);
    }

    private function deferVerification(PaymentEvent $event, bool $deferVerification): void
    {
        if (! $deferVerification || ! $this->verificationQueueIsServed()) {
            return;
        }

        $connection = (string) config('queue.default');
        $queue = trim((string) config('services.sakurupiah.verification_queue', self::VERIFICATION_QUEUE));
        if ($queue !== self::VERIFICATION_QUEUE) {
            return;
        }

        VerifySakurupiahWebhookPayment::dispatch((int) $event->getKey())
            ->onConnection($connection)
            ->onQueue($queue);
    }

    private function verificationQueueIsServed(): bool
    {
        $connection = trim((string) config('queue.default'));
        $driver = trim((string) config('queue.connections.'.$connection.'.driver'));

        return $connection !== '' && $driver !== '' && ! in_array($driver, ['sync', 'null'], true);
    }
}
