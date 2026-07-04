<?php

namespace App\Services\Pakasir;

use App\Enums\OrderStatus;
use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Jobs\SendTelegramReceipt;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Services\OrderStateMachine;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Throwable;

class PakasirCallbackProcessor
{
    public function __construct(
        private readonly OrderStateMachine $orders,
        private readonly PakasirProviderAdapter $client,
        private readonly PakasirPaymentEventLogger $events,
    ) {}

    public function processCallback(array $payload): PaymentEvent
    {
        $transaction = PakasirTransaction::fromCallbackPayload($payload);
        $payment = $this->findPayment($transaction);
        $event = $this->events->log(PaymentEventType::Callback, $payload, $payment, $transaction);

        $this->validatePayload($transaction, $payment);
        $this->verifyWithPakasir($payment);
        $this->applyPaid($event);

        return $event->fresh();
    }

    public function processReconciliationForPayment(Payment $payment, array $payload): PaymentEvent
    {
        $this->ensureConfigured();

        $transaction = PakasirTransaction::fromPayload($payload);
        $event = $this->events->log(PaymentEventType::Reconciliation, $payload, $payment, $transaction);

        if ($payment->provider !== 'pakasir'
            || $payment->status !== PaymentStatus::Pending
            || ! $transaction
            || ! $this->transactionMatchesPayment($transaction, $payment)
            || ! $transaction->isCompleted()) {
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

        if ($payment->status !== PaymentStatus::Pending || $payment->provider !== 'pakasir') {
            throw new UnprocessableEntityHttpException('Pakasir payment is not pending.');
        }

        if (! $transaction || $transaction->amount() !== $payment->amount) {
            throw new UnprocessableEntityHttpException('Pakasir amount mismatch.');
        }

        $project = config('services.pakasir.slug');
        if (! is_string($project) || trim($project) === '') {
            throw new UnprocessableEntityHttpException('Pakasir project is not configured.');
        }

        if ($transaction->project() !== $project) {
            throw new UnprocessableEntityHttpException('Pakasir project mismatch.');
        }

        if (! $transaction->isCompleted()) {
            throw new UnprocessableEntityHttpException('Pakasir status is not completed.');
        }

        if (! filled(config('services.pakasir.api_key'))) {
            throw new UnprocessableEntityHttpException('Pakasir verification is not configured.');
        }
    }

    private function verifyWithPakasir(Payment $payment): void
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
    }

    private function applyPaid(PaymentEvent $event): void
    {
        $payment = Payment::query()->whereKey($event->payment_id)->firstOrFail();
        $order = $this->orders->markPaymentPaid($payment);

        $payment->refresh();
        if ($payment->status === PaymentStatus::Paid && $order->order_status === OrderStatus::Processing) {
            SendTelegramReceipt::dispatch($payment->order_id, false, now()->toISOString())->onQueue('notifications')->afterCommit();
        }

        $event->update(['processed_at' => now()]);
    }

    private function findPayment(PakasirTransaction $transaction): ?Payment
    {
        return Payment::query()
            ->where('provider', 'pakasir')
            ->where('merchant_ref', $transaction->orderId())
            ->first();
    }

    private function transactionMatchesPayment(PakasirTransaction $transaction, Payment $payment): bool
    {
        return $transaction->project() === config('services.pakasir.slug')
            && $transaction->amount() === $payment->amount
            && $transaction->orderId() === $payment->merchant_ref;
    }

    private function ensureConfigured(): void
    {
        foreach (['slug' => 'project', 'api_key' => 'API key'] as $key => $label) {
            $value = config('services.pakasir.'.$key);
            if (! is_string($value) || trim($value) === '') {
                throw new RuntimeException('Pakasir '.$label.' is not configured.');
            }
        }
    }
}
