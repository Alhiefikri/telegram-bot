<?php

namespace App\Services\KlikQris;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Services\OrderLifecycle;
use App\Support\PaymentProvider;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use UnexpectedValueException;

class KlikQrisCallbackProcessor
{
    public function __construct(
        private readonly OrderLifecycle $orders,
        private readonly KlikQrisProviderAdapter $client,
        private readonly KlikQrisPaymentEventLogger $events,
        private readonly KlikQrisNativeQrisService $nativeQris,
    ) {}

    /** @param array<mixed> $payload */
    public function authenticatesCallback(array $payload): bool
    {
        try {
            $callback = KlikQrisCallback::fromPayload($payload);
        } catch (UnexpectedValueException) {
            return false;
        }

        $payment = $this->findPayment($callback->orderId());

        return $payment !== null
            && filled($payment->klikqris_transaction_signature)
            && hash_equals((string) $payment->klikqris_transaction_signature, $callback->signature());
    }

    /** @param array<mixed> $payload */
    public function processCallback(array $payload): PaymentEvent
    {
        $callback = KlikQrisCallback::fromPayload($payload);
        $payment = $this->findPayment($callback->orderId());
        $event = $this->events->logCallback($callback, $payment);

        if (! $callback->isSupportedStatus()) {
            if ($event->processed_at === null) {
                $event->update(['processed_at' => now()]);
            }

            return $event->fresh();
        }

        if (! $this->canVerifyCallback($callback, $payment)) {
            return $event;
        }

        try {
            $processedEvent = $this->nativeQris->tryWithPaymentLock($payment, function () use ($payment, $event): PaymentEvent {
                $transaction = $this->client->transactionStatus($payment);

                return $this->applyAuthoritativeTransaction($payment, $transaction, $event);
            });
        } catch (RuntimeException $exception) {
            if (! $exception->getPrevious() instanceof UnexpectedValueException) {
                throw $exception;
            }

            report($exception);

            return $event->fresh();
        }

        if ($processedEvent === null) {
            return $event->fresh();
        }

        return $processedEvent;
    }

    private function canVerifyCallback(KlikQrisCallback $callback, ?Payment $payment): bool
    {
        if ($payment === null || ! $callback->isPaidTrigger() || ! $this->isEligiblePayment($payment)) {
            return false;
        }

        if ((int) $payment->amount !== $callback->amount()->rupiah()
            || $callback->totalAmount() < (int) $payment->amount) {
            return false;
        }

        return $payment->provider_total_payment === null
            || (int) $payment->provider_total_payment === $callback->totalAmount();
    }

    public function processReconciliationForPayment(Payment $payment, KlikQrisTransaction $transaction): PaymentEvent
    {
        $transaction->assertMatches($payment);
        $signaturePersisted = $this->nativeQris->persistAuthoritativeTransactionSignature($payment, $transaction);
        $event = $this->events->logReconciliation($transaction, $payment);

        return $signaturePersisted ? $this->applyAuthoritativeTransaction($payment, $transaction, $event) : $event->fresh();
    }

    public function reconcilePayment(Payment $payment): PaymentEvent
    {
        return $this->processReconciliationForPayment(
            $payment,
            $this->client->transactionStatus($payment),
        );
    }

    private function applyAuthoritativeTransaction(Payment $payment, KlikQrisTransaction $transaction, PaymentEvent $event): PaymentEvent
    {
        try {
            $transaction->assertMatches($payment);
        } catch (UnexpectedValueException) {
            return $event->fresh();
        }

        if ($transaction->isSuccess() && $transaction->totalAmount() < (int) $payment->amount) {
            return $event->fresh();
        }

        if ($transaction->isSuccess()) {
            $payment = $this->persistRecoveredOutcomeUnknownSuccess($payment, $transaction);
        }

        if (! $this->isProcessablePayment($payment) || ! $transaction->isSuccess()) {
            return $event->fresh();
        }

        return DB::transaction(function () use ($payment, $event): PaymentEvent {
            $lockedEvent = PaymentEvent::query()
                ->whereKey($event->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedEvent->processed_at !== null) {
                return $lockedEvent->fresh();
            }

            if ($payment->fresh()->status === PaymentStatus::Paid) {
                $lockedEvent->update(['processed_at' => now()]);

                return $lockedEvent->fresh();
            }

            $this->orders->applyPaymentPaid($payment);

            $lockedEvent->update(['processed_at' => now()]);

            return $lockedEvent->fresh();
        }, 5);
    }

    private function persistRecoveredOutcomeUnknownSuccess(Payment $payment, KlikQrisTransaction $transaction): Payment
    {
        if (! $this->isRecoverableOutcomeUnknownPayment($payment, $transaction)) {
            return $payment;
        }

        $providerTotal = $transaction->totalAmount();
        $payment->update([
            'provider_payload' => $transaction->sanitizedPayload(),
            'provider_fee' => $providerTotal >= (int) $payment->amount ? $providerTotal - (int) $payment->amount : null,
            'provider_total_payment' => $providerTotal,
            'provider_payment_method' => 'qris',
            'provider_create_state' => 'provider_SUCCESS',
            'provider_create_last_error' => null,
        ]);

        return $payment->fresh();
    }

    private function isRecoverableOutcomeUnknownPayment(Payment $payment, KlikQrisTransaction $transaction): bool
    {
        return strtolower(trim((string) $payment->provider)) === PaymentProvider::KLIKQRIS
            && in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Expired, PaymentStatus::Failed], true)
            && in_array($payment->provider_create_state, ['outcome_unknown', 'provider_SUCCESS'], true)
            && $transaction->totalAmount() >= (int) $payment->amount;
    }

    private function isProcessablePayment(Payment $payment): bool
    {
        if ($this->isEligiblePayment($payment)) {
            return (int) $payment->provider_total_payment >= (int) $payment->amount;
        }

        return strtolower(trim((string) $payment->provider)) === PaymentProvider::KLIKQRIS
            && $payment->status === PaymentStatus::Paid
            && strtolower(trim((string) $payment->provider_payment_method)) === 'qris'
            && $payment->provider_total_payment !== null;
    }

    private function isEligiblePayment(Payment $payment): bool
    {
        return strtolower(trim((string) $payment->provider)) === PaymentProvider::KLIKQRIS
            && in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Expired, PaymentStatus::Failed], true)
            && strtolower(trim((string) $payment->provider_payment_method)) === 'qris'
            && $payment->provider_total_payment !== null;
    }

    private function findPayment(string $merchantRef): ?Payment
    {
        return Payment::query()
            ->where('provider', PaymentProvider::KLIKQRIS)
            ->where('merchant_ref', $merchantRef)
            ->first();
    }
}
