<?php

namespace App\Services\Tripay;

use App\Enums\OrderStatus;
use App\Enums\PaymentEventType;
use App\Jobs\SendTelegramReceipt;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Services\OrderStateMachine;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class TripayCallbackProcessor
{
    public function __construct(private readonly OrderStateMachine $orders, private readonly TripayClient $client) {}

    public function processCallback(string $rawBody, ?string $signature, ?string $event): PaymentEvent
    {
        $payload = json_decode($rawBody, true) ?: [];
        $payment = $this->findPayment($payload);
        $paymentEvent = $this->logEvent(PaymentEventType::Callback, $payload, $signature, $payment);

        try {
            $expectedSignature = $this->client->callbackSignature($rawBody);
        } catch (RuntimeException) {
            throw new AccessDeniedHttpException('Tripay callback verification is not configured.');
        }

        if ($event !== 'payment_status' || ! $signature || ! hash_equals($expectedSignature, $signature)) {
            throw new AccessDeniedHttpException('Invalid Tripay callback signature.');
        }

        if ($this->hasConflictingIdentifiers($payload, $payment)) {
            throw new UnprocessableEntityHttpException('Tripay identifiers belong to different payments.');
        }

        $this->applyPayload($paymentEvent, $payload);

        return $paymentEvent->fresh();
    }

    public function processReconciliationForPayment(Payment $payment, array $payload): PaymentEvent
    {
        $data = Arr::get($payload, 'data');
        $paymentEvent = $this->logEvent(PaymentEventType::Reconciliation, is_array($data) ? $data : $payload, null, $payment);

        if (Arr::get($payload, 'success') !== true || ! is_array($data)) {
            return $paymentEvent;
        }

        if (Arr::get($data, 'reference') !== $payment->provider_reference
            || Arr::get($data, 'merchant_ref') !== $payment->merchant_ref
            || (int) Arr::get($data, 'total_amount', Arr::get($data, 'amount', 0)) !== $payment->amount) {
            return $paymentEvent;
        }

        $this->applyPayload($paymentEvent, $data);

        return $paymentEvent->fresh();
    }

    private function applyPayload(PaymentEvent $paymentEvent, array $payload): void
    {
        DB::transaction(function () use ($paymentEvent, $payload): void {
            $payment = Payment::findOrFail($paymentEvent->payment_id);
            $amount = (int) Arr::get($payload, 'total_amount', Arr::get($payload, 'amount', 0));
            $status = strtoupper((string) Arr::get($payload, 'status'));

            if ($payment->merchant_ref !== Arr::get($payload, 'merchant_ref') || $payment->amount !== $amount) {
                throw new UnprocessableEntityHttpException('Tripay payload does not match payment.');
            }

            $reference = Arr::get($payload, 'reference');
            if ($reference) {
                if (Payment::where('provider', 'tripay')->where('provider_reference', $reference)->whereKeyNot($payment->id)->exists()) {
                    throw new UnprocessableEntityHttpException('Tripay provider reference belongs to another payment.');
                }
            }

            $method = Arr::get($payload, 'payment_method_code', Arr::get($payload, 'payment_method'));
            if ($method && $payment->method !== $method) {
                throw new UnprocessableEntityHttpException('Tripay payment method does not match payment.');
            }

            if (! in_array($status, ['PAID', 'EXPIRED', 'FAILED', 'REFUND'], true)) {
                throw new UnprocessableEntityHttpException('Unsupported Tripay payment status.');
            }

            $orderStatusBefore = $payment->order?->order_status;

            match ($status) {
                'PAID' => $this->orders->markPaymentPaid($payment),
                'EXPIRED' => $this->orders->markPaymentExpired($payment),
                'FAILED' => $this->orders->cancelPendingOrder($payment->order),
                'REFUND' => $this->orders->markPaymentRefunded($payment),
                default => null,
            };

            if ($reference) {
                Payment::whereKey($payment->id)->update(['provider_reference' => $reference]);
            }

            $payment->refresh();
            $payment->unsetRelation('order');
            if ($status === 'PAID' && $orderStatusBefore !== $payment->order->order_status && $payment->order->order_status === OrderStatus::Processing) {
                SendTelegramReceipt::dispatch($payment->order_id)->afterCommit();
            }

            $paymentEvent->update(['processed_at' => now()]);
        }, 5);
    }

    private function logEvent(PaymentEventType $type, array $payload, ?string $signature, ?Payment $payment): PaymentEvent
    {
        return PaymentEvent::create([
            'payment_id' => $payment?->id,
            'provider' => 'tripay',
            'event_type' => $type,
            'merchant_ref' => Arr::get($payload, 'merchant_ref'),
            'provider_reference' => Arr::get($payload, 'reference'),
            'provider_status' => Arr::get($payload, 'status'),
            'raw_payload' => $payload,
            'signature' => $signature,
            'received_at' => now(),
        ]);
    }

    private function findPayment(array $payload): ?Payment
    {
        $merchantRef = Arr::get($payload, 'merchant_ref');
        $reference = Arr::get($payload, 'reference');

        if (! $merchantRef && ! $reference) {
            return null;
        }

        if ($merchantRef && $reference) {
            $payment = Payment::query()
                ->where('provider', 'tripay')
                ->where('merchant_ref', $merchantRef)
                ->first();

            if (! $payment || Payment::where('provider', 'tripay')->where('provider_reference', $reference)->whereKeyNot($payment->id)->exists()) {
                return null;
            }

            return $payment;
        }

        return Payment::query()
            ->where('provider', 'tripay')
            ->where(function ($query) use ($merchantRef, $reference): void {
                $query->when($merchantRef, fn ($query, $ref) => $query->where('merchant_ref', $ref))
                    ->when($reference, fn ($query, $ref) => $query->orWhere('provider_reference', $ref));
            })
            ->first();
    }

    private function hasConflictingIdentifiers(array $payload, ?Payment $payment): bool
    {
        $merchantRef = Arr::get($payload, 'merchant_ref');
        $reference = Arr::get($payload, 'reference');

        if (! $merchantRef || ! $reference || $payment) {
            return false;
        }

        return Payment::where('provider', 'tripay')
            ->where(function ($query) use ($merchantRef, $reference): void {
                $query->where('merchant_ref', $merchantRef)
                    ->orWhere('provider_reference', $reference);
            })
            ->exists();
    }
}
