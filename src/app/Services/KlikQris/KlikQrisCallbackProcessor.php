<?php

namespace App\Services\KlikQris;

use App\Enums\PaymentStatus;
use App\Jobs\SendTelegramReceipt;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Services\OrderLifecycle;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use UnexpectedValueException;

class KlikQrisCallbackProcessor
{
    private const STATUS_LOCK_LEASE_SECONDS = 60;

    public function __construct(
        private readonly OrderLifecycle $orders,
        private readonly KlikQrisProviderAdapter $client,
        private readonly KlikQrisPaymentEventLogger $events,
    ) {}

    /** @param array<mixed> $payload */
    public function processCallback(array $payload): PaymentEvent
    {
        $callback = KlikQrisCallback::fromPayload($payload);
        $payment = $this->findPayment($callback->orderId());
        $event = $this->events->logCallback($callback, $payment);

        if (! $this->canVerifyCallback($callback, $payment)) {
            return $event;
        }

        $statusLock = Cache::lock('klikqris:callback-status:'.$payment->getKey(), self::STATUS_LOCK_LEASE_SECONDS);
        if (! $statusLock->get()) {
            return $event->fresh();
        }

        try {
            $transaction = $this->client->transactionStatus($payment);

            return $this->applyAuthoritativeTransaction($payment, $transaction, $event);
        } catch (RuntimeException $exception) {
            if (! $exception->getPrevious() instanceof UnexpectedValueException) {
                throw $exception;
            }

            report($exception);

            return $event->fresh();
        } finally {
            $statusLock->release();
        }
    }

    public function processReconciliationForPayment(Payment $payment, KlikQrisTransaction $transaction): PaymentEvent
    {
        $transaction->assertMatches($payment);

        $event = $this->events->logReconciliation($transaction, $payment);

        return $this->applyAuthoritativeTransaction($payment, $transaction, $event);
    }

    private function applyAuthoritativeTransaction(Payment $payment, KlikQrisTransaction $transaction, PaymentEvent $event): PaymentEvent
    {
        try {
            $transaction->assertMatches($payment);
        } catch (UnexpectedValueException) {
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

            $result = $this->orders->applyPaymentPaid($payment);
            if ($result->shouldDispatchReceipt()) {
                SendTelegramReceipt::dispatch($payment->order_id, false, now()->toISOString())->onQueue('notifications')->afterCommit();
            }

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
        return strtolower(trim((string) $payment->provider)) === 'klikqris'
            && in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Expired, PaymentStatus::Failed], true)
            && $payment->provider_create_state === 'outcome_unknown'
            && ($payment->provider_payment_method === null || $payment->provider_total_payment === null || $payment->provider_payload === null)
            && $transaction->totalAmount() >= (int) $payment->amount;
    }

    private function canVerifyCallback(KlikQrisCallback $callback, ?Payment $payment): bool
    {
        if ($payment === null || ! $callback->isPaidTrigger() || ! $this->isEligiblePayment($payment)) {
            return false;
        }

        return (int) $payment->amount === $callback->amount()->rupiah()
            && (int) $payment->provider_total_payment === $callback->totalAmount();
    }

    private function isProcessablePayment(Payment $payment): bool
    {
        if ($this->isEligiblePayment($payment)) {
            return true;
        }

        return strtolower(trim((string) $payment->provider)) === 'klikqris'
            && $payment->status === PaymentStatus::Paid
            && strtolower(trim((string) $payment->provider_payment_method)) === 'qris'
            && $payment->provider_total_payment !== null;
    }

    private function isEligiblePayment(Payment $payment): bool
    {
        return strtolower(trim((string) $payment->provider)) === 'klikqris'
            && in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Expired, PaymentStatus::Failed], true)
            && strtolower(trim((string) $payment->provider_payment_method)) === 'qris'
            && $payment->provider_total_payment !== null;
    }

    private function findPayment(string $merchantRef): ?Payment
    {
        return Payment::query()
            ->where('provider', 'klikqris')
            ->where('merchant_ref', $merchantRef)
            ->first();
    }
}
