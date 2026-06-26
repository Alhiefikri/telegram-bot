<?php

namespace App\Services\Pakasir;

use App\Enums\OrderStatus;
use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Jobs\SendTelegramReceipt;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Services\OrderStateMachine;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Throwable;

class PakasirCallbackProcessor
{
    public function __construct(private readonly OrderStateMachine $orders, private readonly PakasirClient $client) {}

    public function processCallback(array $payload): PaymentEvent
    {
        $payment = $this->findPayment($payload);
        $event = $this->logEvent($payload, $payment);

        $this->validatePayload($payload, $payment);
        $this->verifyWithPakasir($payment);
        $this->applyPaid($event);

        return $event->fresh();
    }

    public function processReconciliationForPayment(Payment $payment, array $payload): PaymentEvent
    {
        $this->ensureConfigured();

        $transaction = $this->transactionFromPayload($payload);
        $eventPayload = is_array($transaction) ? $transaction : $payload;
        $event = $this->logEvent(PaymentEventType::Reconciliation, $eventPayload, $payment);

        if ($payment->provider !== 'pakasir'
            || $payment->status !== PaymentStatus::Pending
            || ! is_array($transaction)
            || Arr::get($transaction, 'project') !== config('services.pakasir.slug')
            || (int) Arr::get($transaction, 'amount') !== $payment->amount
            || Arr::get($transaction, 'order_id') !== $payment->merchant_ref
            || strcasecmp((string) Arr::get($transaction, 'status'), 'completed') !== 0) {
            return $event;
        }

        $this->applyPaid($event);

        return $event->fresh();
    }

    private function validatePayload(array $payload, ?Payment $payment): void
    {
        if (! $payment) {
            throw new UnprocessableEntityHttpException('Unknown Pakasir payment.');
        }

        if ($payment->status !== PaymentStatus::Pending || $payment->provider !== 'pakasir') {
            throw new UnprocessableEntityHttpException('Pakasir payment is not pending.');
        }

        if ((int) Arr::get($payload, 'amount') !== $payment->amount) {
            throw new UnprocessableEntityHttpException('Pakasir amount mismatch.');
        }

        $project = config('services.pakasir.slug');
        if (! is_string($project) || trim($project) === '') {
            throw new UnprocessableEntityHttpException('Pakasir project is not configured.');
        }

        if (Arr::get($payload, 'project') !== $project) {
            throw new UnprocessableEntityHttpException('Pakasir project mismatch.');
        }

        if (strcasecmp((string) Arr::get($payload, 'status'), 'completed') !== 0) {
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

        $transaction = $this->transactionFromPayload($response);

        if (! is_array($transaction)
            || Arr::get($transaction, 'project') !== config('services.pakasir.slug')
            || (int) Arr::get($transaction, 'amount') !== $payment->amount
            || Arr::get($transaction, 'order_id') !== $payment->merchant_ref
            || strcasecmp((string) Arr::get($transaction, 'status'), 'completed') !== 0) {
            throw new UnprocessableEntityHttpException('Pakasir verification mismatch.');
        }
    }

    private function applyPaid(PaymentEvent $event): void
    {
        DB::transaction(function () use ($event): void {
            $payment = Payment::query()->whereKey($event->payment_id)->lockForUpdate()->firstOrFail();

            if ($payment->status !== PaymentStatus::Pending) {
                return;
            }

            $order = $this->orders->markPaymentPaid($payment);

            $payment->refresh();
            if ($payment->status === PaymentStatus::Paid && $order->order_status === OrderStatus::Processing) {
                SendTelegramReceipt::dispatch($payment->order_id)->afterCommit();
            }

            $event->update(['processed_at' => now()]);
        }, 5);
    }

    private function logEvent(PaymentEventType|array $type, array|Payment|null $payload = null, ?Payment $payment = null): PaymentEvent
    {
        if (is_array($type)) {
            $payment = $payload instanceof Payment ? $payload : $payment;
            $payload = $type;
            $type = PaymentEventType::Callback;
        }

        return PaymentEvent::create([
            'payment_id' => $payment?->id,
            'provider' => 'pakasir',
            'event_type' => $type,
            'merchant_ref' => Arr::get($payload, 'order_id'),
            'provider_status' => Arr::get($payload, 'status'),
            'raw_payload' => $payload,
            'received_at' => now(),
        ]);
    }

    private function findPayment(array $payload): ?Payment
    {
        return Payment::query()
            ->where('provider', 'pakasir')
            ->where('merchant_ref', Arr::get($payload, 'order_id'))
            ->first();
    }

    private function transactionFromPayload(array $payload): mixed
    {
        $transaction = Arr::get($payload, 'transaction', Arr::get($payload, 'data', $payload));
        if (is_array($transaction) && isset($transaction['transaction']) && is_array($transaction['transaction'])) {
            return $transaction['transaction'];
        }

        return $transaction;
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
