<?php

namespace App\Services\WijayaPay;

use App\Enums\OrderStatus;
use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Jobs\VerifyWijayaPayWebhookPayment;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Services\MoneyReceipts\ExternalMoneyReceiptWriter;
use App\Services\OrderLifecycle;
use App\Support\PaymentProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verifies WijayaPay callback signals before delegating lifecycle mutation.
 *
 * The published callback signature is md5(code_merchant + api_key + ref_id):
 * it authenticates the reference claim but does not cover amount or status.
 * Every successful/expired transition is therefore re-read from the
 * authoritative get-status endpoint before OrderLifecycle is invoked.
 */
final class WijayaPayCallbackProcessor
{
    private const VERIFICATION_QUEUE = 'default';

    public function __construct(
        private readonly OrderLifecycle $orders,
        private readonly WijayaPayProviderAdapter $provider,
        private readonly WijayaPayInvoiceLifecycleService $lifecycle,
        private readonly WijayaPayPaymentEventLogger $events,
        private readonly ExternalMoneyReceiptWriter $receipts,
        private readonly WijayaPayReconciliationQuery $reconciliationQuery,
    ) {}

    public function authenticatesCallback(mixed $providedSignature, string $refId): bool
    {
        return WijayaPaySignature::verifyCallback(
            config('services.wijayapay.code_merchant'),
            config('services.wijayapay.api_key'),
            $refId,
            $providedSignature,
        );
    }

    /**
     * @param  WijayaPayCallback|array<mixed>  $callback
     */
    public function processCallback(WijayaPayCallback|array $callback): PaymentEvent
    {
        if (is_array($callback)) {
            $callback = WijayaPayCallback::fromPayload($callback);
        }

        $payment = $this->findPayment($callback->refId);
        $event = $this->events->logCallback($callback, $payment);

        return $this->processEvent($event, $callback, $payment, deferVerification: true);
    }

    public function processQueuedEvent(PaymentEvent $event): PaymentEvent
    {
        if ($event->provider !== PaymentProvider::WIJAYAPAY
            || $event->event_type !== PaymentEventType::Callback
        ) {
            return $event->fresh();
        }

        $payload = $event->raw_payload;
        if (! is_array($payload)) {
            return $this->markProcessed($event);
        }

        try {
            $callback = WijayaPayCallback::fromPayload($payload);
        } catch (WijayaPaySchemaException) {
            return $this->markProcessed($event);
        }

        $payment = $event->payment_id === null
            ? $this->findPayment($callback->refId)
            : Payment::query()->find($event->payment_id);

        return $this->processEvent($event, $callback, $payment, deferVerification: false);
    }

    /**
     * Reconcile one persisted WijayaPay Payment from an authoritative status
     * lookup under the same lock used by create/callback/refresh work.
     */
    public function reconcilePayment(Payment $payment, bool $scheduled = false): WijayaPayReconciliationOutcome
    {
        if ($payment->status === PaymentStatus::Paid || ! $this->isEligiblePayment($payment)) {
            return WijayaPayReconciliationOutcome::Pending;
        }

        if (blank($payment->provider_reference)) {
            $event = $payment->paymentEvents()
                ->where('provider', PaymentProvider::WIJAYAPAY)
                ->where('event_type', PaymentEventType::Callback->value)
                ->where('provider_status', WijayaPayStatus::Paid->value)
                ->whereNull('processed_at')
                ->oldest('received_at')
                ->first();
            if ($event !== null) {
                try {
                    $this->processQueuedEvent($event);
                } catch (WijayaPayStatusUnavailableException) {
                    return WijayaPayReconciliationOutcome::VerificationPending;
                }

                return $payment->fresh()->status === PaymentStatus::Paid
                    ? WijayaPayReconciliationOutcome::Paid
                    : WijayaPayReconciliationOutcome::VerificationPending;
            }
        }

        $result = $this->lifecycle->tryWithPaymentLock(
            $payment,
            fn (): WijayaPayReconciliationOutcome => $this->reconcileLocked($payment, $scheduled),
        );

        return $result ?? WijayaPayReconciliationOutcome::Busy;
    }

    private function reconcileLocked(Payment $payment, bool $scheduled): WijayaPayReconciliationOutcome
    {
        $payment->refresh();

        if ($payment->status === PaymentStatus::Paid
            || ($scheduled && ! $this->reconciliationQuery->eligible()->whereKey($payment->getKey())->exists())
        ) {
            return WijayaPayReconciliationOutcome::Pending;
        }

        if (blank($payment->merchant_ref)) {
            return WijayaPayReconciliationOutcome::VerificationPending;
        }

        try {
            $status = $this->fetchStatus($payment);
        } catch (WijayaPayStatusUnavailableException $exception) {
            $previous = $exception->getPrevious();
            if (blank($payment->provider_reference)
                && $previous instanceof WijayaPayProviderException
                && $previous->isNotFound()
            ) {
                // An explicit not-found answer proves that no provider
                // transaction exists for this reference yet. Recover the
                // ambiguous create on the same reference inside its bounded
                // window instead of waiting for a manual retry.
                $recovered = $this->lifecycle->recoverAfterNotFoundWhileLocked($payment);

                return $recovered->outcome() === WijayaPayInvoiceOutcome::RecoveryPending
                    ? WijayaPayReconciliationOutcome::VerificationPending
                    : WijayaPayReconciliationOutcome::Pending;
            }

            if (! $this->lifecycle->classify($payment)->isPayable()) {
                $this->lifecycle->persistRecoveryUnconfirmed($payment, 'status_unavailable');
            }

            return WijayaPayReconciliationOutcome::VerificationPending;
        }

        $identityReason = $this->statusIdentityMismatchReason($payment, $status);
        if ($identityReason !== null) {
            $this->lifecycle->persistAuthoritativeStatusObservation(
                $payment,
                $status,
                WijayaPayInvoiceLifecycleService::STATE_INVALID_PROVIDER_RESULT,
                $identityReason,
            );

            return WijayaPayReconciliationOutcome::Invalid;
        }

        if ($status->isPaid()) {
            $this->lifecycle->persistAuthoritativeStatusObservation(
                $payment,
                $status,
                WijayaPayInvoiceLifecycleService::STATE_PROVIDER_PAID,
                'authoritative_amount_unverified',
            );

            $amount = $status->amountValue();
            if ($amount === null) {
                return WijayaPayReconciliationOutcome::VerificationPending;
            }
            if (! $this->statusAmountMatchesPayment($payment, $status)) {
                $this->lifecycle->persistAuthoritativeStatusObservation(
                    $payment,
                    $status,
                    WijayaPayInvoiceLifecycleService::STATE_INVALID_PROVIDER_RESULT,
                    'amount_mismatch',
                );

                return WijayaPayReconciliationOutcome::Invalid;
            }

            $this->expireOverduePendingPayment($payment);

            if ($this->receipts->claimProviderPayment($payment)->isHeld()) {
                return WijayaPayReconciliationOutcome::VerificationPending;
            }

            $this->orders->applyPaymentPaid($payment);

            return WijayaPayReconciliationOutcome::Paid;
        }

        if ($status->isExpired()) {
            if ($this->reconciliationQuery->hasSuccessIndication($payment)) {
                $this->events->logReconciliation($payment, $status);

                return WijayaPayReconciliationOutcome::VerificationPending;
            }

            $this->lifecycle->persistAuthoritativeStatusObservation(
                $payment,
                $status,
                WijayaPayInvoiceLifecycleService::STATE_PROVIDER_EXPIRED,
                'provider_expired',
            );
            if ($payment->status === PaymentStatus::Pending) {
                $this->orders->applyPaymentExpired($payment);
            }

            return WijayaPayReconciliationOutcome::Expired;
        }

        if (! $this->lifecycle->classify($payment)->isPayable()) {
            $this->lifecycle->recoverPendingObservationWhileLocked($payment, $status);
        } else {
            $this->events->logReconciliation($payment, $status);
        }

        return WijayaPayReconciliationOutcome::Pending;
    }

    private function processEvent(
        PaymentEvent $event,
        WijayaPayCallback $callback,
        ?Payment $payment,
        bool $deferVerification,
    ): PaymentEvent {
        if ($event->processed_at !== null) {
            return $event->fresh();
        }

        if ($payment === null
            || ! $this->isEligiblePayment($payment)
            || $callback->isPending()
        ) {
            return $this->markProcessed($event);
        }

        if ($payment->status === PaymentStatus::Paid) {
            return $this->markProcessed($event);
        }

        try {
            $result = $this->lifecycle->tryWithPaymentLock(
                $payment,
                fn (): array => $this->verifyAndApplyLocked($payment, $callback),
            );
        } catch (WijayaPayStatusUnavailableException $exception) {
            $this->events->logRecoveryObservation(
                $payment,
                WijayaPayInvoiceLifecycleService::STATE_RECOVERY_UNCONFIRMED,
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
                throw new WijayaPayStatusUnavailableException;
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
    private function verifyAndApplyLocked(Payment $payment, WijayaPayCallback $callback): array
    {
        $payment = Payment::query()->find($payment->getKey());
        if ($payment === null
            || ! $this->isEligiblePayment($payment)
            || $payment->status === PaymentStatus::Paid
            || (string) $payment->merchant_ref !== $callback->refId
        ) {
            return ['processed' => true, 'defer' => false];
        }

        if (filled($payment->provider_reference)
            && $payment->provider_reference !== $callback->trxReference
        ) {
            return ['processed' => true, 'defer' => false];
        }

        $status = $this->fetchStatus($payment);
        $identityReason = $this->statusIdentityMismatchReason($payment, $status);
        if ($identityReason !== null) {
            $this->lifecycle->persistAuthoritativeStatusObservation(
                $payment,
                $status,
                WijayaPayInvoiceLifecycleService::STATE_INVALID_PROVIDER_RESULT,
                $identityReason,
            );

            return ['processed' => true, 'defer' => false];
        }

        if ($callback->isPaid()) {
            if (! $status->isPaid()) {
                $this->events->logReconciliation($payment, $status);

                return ['processed' => false, 'defer' => true];
            }

            $amount = $status->amountValue();
            if ($amount === null
                || ! $this->statusAmountMatchesPayment($payment, $status)
            ) {
                $this->lifecycle->persistAuthoritativeStatusObservation(
                    $payment,
                    $status,
                    $amount === null
                        ? WijayaPayInvoiceLifecycleService::STATE_PROVIDER_PAID
                        : WijayaPayInvoiceLifecycleService::STATE_INVALID_PROVIDER_RESULT,
                    $amount === null ? 'authoritative_amount_unverified' : 'amount_mismatch',
                );

                return ['processed' => $amount !== null, 'defer' => $amount === null];
            }

            $this->lifecycle->persistAuthoritativeStatusObservation(
                $payment,
                $status,
                WijayaPayInvoiceLifecycleService::STATE_PROVIDER_PAID,
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
                WijayaPayInvoiceLifecycleService::STATE_PROVIDER_EXPIRED,
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
            || ! $this->deadlinePassed($payment, $order)
        ) {
            return;
        }

        $this->orders->applyPaymentExpired($payment);
        $payment->refresh();
    }

    private function deadlinePassed(Payment $payment, $order): bool
    {
        return $payment->expired_at?->isPast() === true
            || $payment->provider_expires_at?->isPast() === true
            || $order->expires_at?->isPast() === true;
    }

    private function fetchStatus(Payment $payment): WijayaPayStatusResponse
    {
        try {
            return $this->provider->transactionStatus((string) $payment->merchant_ref);
        } catch (Throwable $exception) {
            $this->logLookupFailure($payment, 'transactionStatus', $exception);

            throw new WijayaPayStatusUnavailableException(previous: $exception);
        }
    }

    private function logLookupFailure(Payment $payment, string $operation, Throwable $exception): void
    {
        Log::warning('WijayaPay verification lookup failed.', [
            'payment_id' => $payment->getKey(),
            'operation' => $operation,
            'exception_class' => $exception::class,
            'reason' => $exception instanceof WijayaPayProviderException ? $exception->reason : 'unexpected',
            'http_status' => $exception instanceof WijayaPayProviderException ? $exception->httpStatus : null,
            'provider_status' => $exception instanceof WijayaPayProviderException ? $exception->providerStatus : null,
        ]);
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
        if ($amount !== null
            && ! $this->statusAmountMatchesPayment($payment, $status)
        ) {
            return 'amount_mismatch';
        }

        return null;
    }

    private function statusAmountMatchesPayment(Payment $payment, WijayaPayStatusResponse $status): bool
    {
        $evidence = $status->totalValue() ?? $status->totalDibayar;
        if ($evidence === null) {
            return false;
        }

        $expected = WijayaPayInvoiceLifecycleService::expectedProviderAmounts($payment);
        $fee = $status->feeValue();
        if ($fee !== null && $fee >= 0) {
            $expected[] = (int) $payment->amount + $fee;
        }

        return in_array($evidence, array_values(array_unique($expected)), true);
    }

    private function isEligiblePayment(Payment $payment): bool
    {
        return strtolower(trim((string) $payment->provider)) === PaymentProvider::WIJAYAPAY
            && in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Expired, PaymentStatus::Failed], true)
            && strcasecmp(trim((string) $payment->method), WijayaPayCreateInvoice::PAYMENT_METHOD_QRIS) === 0
            && strtoupper(trim((string) $payment->currency)) === 'IDR';
    }

    private function findPayment(string $merchantRef): ?Payment
    {
        return Payment::query()
            ->where('provider', PaymentProvider::WIJAYAPAY)
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
        $queue = trim((string) config('services.wijayapay.verification_queue', self::VERIFICATION_QUEUE));
        if ($queue !== self::VERIFICATION_QUEUE) {
            return;
        }

        VerifyWijayaPayWebhookPayment::dispatch((int) $event->getKey())
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
