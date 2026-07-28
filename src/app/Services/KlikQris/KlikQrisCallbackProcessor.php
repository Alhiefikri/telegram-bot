<?php

namespace App\Services\KlikQris;

use App\Enums\PaymentStatus;
use App\Jobs\SendTelegramReceipt;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Services\OrderLifecycle;
use Throwable;
use UnexpectedValueException;

class KlikQrisCallbackProcessor
{
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

        try {
            $transaction = $this->client->transactionStatus($payment);
        } catch (Throwable) {
            return $event->fresh();
        }

        return $this->applyAuthoritativeTransaction($payment, $transaction, $event);
    }

    public function processReconciliationForPayment(Payment $payment, KlikQrisTransaction $transaction): PaymentEvent
    {
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

        if (! $this->isEligiblePayment($payment) || ! $transaction->isSuccess()) {
            return $event->fresh();
        }

        if ($event->processed_at !== null && $payment->fresh()->status === PaymentStatus::Paid) {
            return $event->fresh();
        }

        $result = $this->orders->applyPaymentPaid($payment);
        if ($result->shouldDispatchReceipt()) {
            SendTelegramReceipt::dispatch($payment->order_id, false, now()->toISOString())->onQueue('notifications')->afterCommit();
        }

        $event->update(['processed_at' => now()]);

        return $event->fresh();
    }

    private function canVerifyCallback(KlikQrisCallback $callback, ?Payment $payment): bool
    {
        if ($payment === null || ! $callback->isPaidTrigger() || ! $this->isEligiblePayment($payment)) {
            return false;
        }

        return (int) $payment->amount === $callback->amount()->rupiah()
            && (int) $payment->provider_total_payment === $callback->totalAmount();
    }

    private function isEligiblePayment(Payment $payment): bool
    {
        return strtolower(trim((string) $payment->provider)) === 'klikqris'
            && $payment->status === PaymentStatus::Pending
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
