<?php

namespace App\Services\Pakasir;

use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Services\OrderLifecycle;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Throwable;

class PakasirCallbackProcessor
{
    public function __construct(
        private readonly OrderLifecycle $orders,
        private readonly PakasirProviderAdapter $client,
        private readonly PakasirPaymentEventLogger $events,
        private readonly PakasirProviderReadiness $readiness,
    ) {}

    public function processCallback(array $payload): PaymentEvent
    {
        $transaction = PakasirTransaction::fromCallbackPayload($payload);
        $payment = $this->findPayment($transaction);
        $event = $this->events->log(PaymentEventType::Callback, $payload, $payment, $transaction);

        if ($event->processed_at !== null && $payment?->fresh()->status === PaymentStatus::Paid) {
            return $event->fresh();
        }

        $this->validatePayload($transaction, $payment);
        $verifiedTransaction = $this->verifyWithPakasir($payment);
        $this->syncProviderExpiry($payment, $verifiedTransaction);

        if ($payment->fresh()->status === PaymentStatus::Paid) {
            $event->update(['processed_at' => now()]);

            return $event->fresh();
        }

        $this->applyPaid($event);

        return $event->fresh();
    }

    public function processReconciliationForPayment(Payment $payment, array $payload): PaymentEvent
    {
        $this->ensureConfigured();

        $transaction = PakasirTransaction::fromPayload($payload);
        $event = $this->events->log(PaymentEventType::Reconciliation, $payload, $payment, $transaction);

        if (! $this->isReconciliationCandidate($payment)
            || ! $transaction
            || ! $this->transactionMatchesPayment($transaction, $payment)) {
            return $event;
        }

        $this->syncProviderExpiry($payment, $transaction);

        if (! $transaction->isCompleted()) {
            return $event;
        }

        $this->applyPaid($event);

        return $event->fresh();
    }

    private function validatePayload(?PakasirTransaction $transaction, ?Payment $payment): void
    {
        if (! $payment) {
            throw new UnprocessableEntityHttpException('Unknown Pakasir payment.');
        }

        if (! $this->isVerifiablePayment($payment)) {
            throw new UnprocessableEntityHttpException('Pakasir payment cannot be verified.');
        }

        if (! $transaction || $transaction->amount() !== $payment->amount) {
            throw new UnprocessableEntityHttpException('Pakasir amount mismatch.');
        }

        $readiness = $this->readiness->verification();
        if (! $readiness->isReady()) {
            throw new UnprocessableEntityHttpException('Pakasir verification is not configured.');
        }

        $project = (string) config('services.pakasir.slug');
        if ($transaction->project() !== $project) {
            throw new UnprocessableEntityHttpException('Pakasir project mismatch.');
        }

        if (! $transaction->isCompleted()) {
            throw new UnprocessableEntityHttpException('Pakasir status is not completed.');
        }
    }

    private function verifyWithPakasir(Payment $payment): PakasirTransaction
    {
        try {
            $response = $this->client->transactionDetail($payment);
        } catch (Throwable) {
            throw new UnprocessableEntityHttpException('Pakasir verification failed.');
        }

        $transaction = PakasirTransaction::fromPayload($response);

        if (! $transaction
            || ! $this->transactionMatchesPayment($transaction, $payment)
            || ! $transaction->isCompleted()) {
            throw new UnprocessableEntityHttpException('Pakasir verification mismatch.');
        }

        return $transaction;
    }

    private function applyPaid(PaymentEvent $event): void
    {
        $payment = Payment::query()->whereKey($event->payment_id)->firstOrFail();
        $this->orders->applyPaymentPaid($payment);

        $event->update(['processed_at' => now()]);
    }

    private function findPayment(PakasirTransaction $transaction): ?Payment
    {
        return Payment::query()
            ->where('provider', 'pakasir')
            ->where('merchant_ref', $transaction->orderId())
            ->first();
    }

    private function syncProviderExpiry(Payment $payment, PakasirTransaction $transaction): void
    {
        $providerExpiresAt = $transaction->expiresAt()?->setTimezone((string) config('app.timezone'));
        if ($providerExpiresAt === null) {
            return;
        }

        DB::transaction(function () use ($payment, $providerExpiresAt): void {
            $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->first();
            $lockedPayment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $paymentUpdates = ['provider_expires_at' => $providerExpiresAt];

            if ($lockedPayment->expired_at === null || $providerExpiresAt->lt($lockedPayment->expired_at)) {
                $paymentUpdates['expired_at'] = $providerExpiresAt;
            }

            $lockedPayment->update($paymentUpdates);

            if ($order && ($order->expires_at === null || $providerExpiresAt->lt($order->expires_at))) {
                $order->update(['expires_at' => $providerExpiresAt]);
            }

            $payment->refresh();
        });
    }

    private function transactionMatchesPayment(PakasirTransaction $transaction, Payment $payment): bool
    {
        $expectedMethod = is_string($payment->provider_payment_method) && trim($payment->provider_payment_method) !== ''
            ? strtolower($payment->provider_payment_method)
            : null;

        return $transaction->project() === config('services.pakasir.slug')
            && $transaction->amount() === $payment->amount
            && $transaction->orderId() === $payment->merchant_ref
            && ($expectedMethod === null || $transaction->paymentMethod() === $expectedMethod);
    }

    private function isVerifiablePayment(Payment $payment): bool
    {
        return $payment->provider === 'pakasir'
            && in_array($payment->status, [
                PaymentStatus::Pending,
                PaymentStatus::Paid,
                PaymentStatus::Expired,
                PaymentStatus::Failed,
            ], true);
    }

    private function isReconciliationCandidate(Payment $payment): bool
    {
        return $payment->provider === 'pakasir'
            && in_array($payment->status, [
                PaymentStatus::Pending,
                PaymentStatus::Expired,
                PaymentStatus::Failed,
            ], true);
    }

    private function ensureConfigured(): void
    {
        $this->readiness->verification()->assertReady('Pakasir verification is not configured.');
    }
}
