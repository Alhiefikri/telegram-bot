<?php

namespace App\Services\KlikQris;

use App\Enums\PaymentEventType;
use App\Models\Payment;
use App\Models\PaymentEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class KlikQrisPaymentEventLogger
{
    public function logProviderCreate(Payment $payment, KlikQrisTransaction $transaction): PaymentEvent
    {
        $receivedAt = now();

        return PaymentEvent::create([
            'payment_id' => $payment->id,
            'provider' => 'klikqris',
            'event_type' => PaymentEventType::ProviderCreate,
            'merchant_ref' => $transaction->orderId(),
            'provider_status' => $transaction->status(),
            'raw_payload' => $transaction->sanitizedPayload(),
            'received_at' => $receivedAt,
            'last_received_at' => $receivedAt,
            'received_count' => 1,
        ]);
    }

    public function logCallback(KlikQrisCallback $callback, ?Payment $payment): PaymentEvent
    {
        return $this->logCanonical(
            PaymentEventType::Callback,
            $callback->orderId(),
            $callback->status(),
            $callback->sanitizedPayload(),
            $payment,
        );
    }

    public function logReconciliation(KlikQrisTransaction $transaction, Payment $payment): PaymentEvent
    {
        return $this->logCanonical(
            PaymentEventType::Reconciliation,
            $transaction->orderId(),
            $transaction->status(),
            $transaction->sanitizedPayload(),
            $payment,
        );
    }

    /** @param array<mixed> $sanitizedPayload */
    private function logCanonical(PaymentEventType $type, string $merchantRef, string $status, array $sanitizedPayload, ?Payment $payment): PaymentEvent
    {
        $receivedAt = now();
        $idempotencyKey = $this->idempotencyKey($sanitizedPayload);

        try {
            return DB::transaction(function () use ($type, $merchantRef, $status, $sanitizedPayload, $payment, $receivedAt, $idempotencyKey): PaymentEvent {
                $event = PaymentEvent::query()
                    ->where('provider', 'klikqris')
                    ->where('event_type', $type->value)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($event === null) {
                    return PaymentEvent::create([
                        'payment_id' => $payment?->id,
                        'provider' => 'klikqris',
                        'event_type' => $type,
                        'merchant_ref' => $merchantRef,
                        'provider_status' => $status,
                        'raw_payload' => $sanitizedPayload,
                        'idempotency_key' => $idempotencyKey,
                        'received_at' => $receivedAt,
                        'last_received_at' => $receivedAt,
                        'received_count' => 1,
                    ]);
                }

                $event->forceFill([
                    'payment_id' => $event->payment_id ?? $payment?->id,
                    'last_received_at' => $receivedAt,
                    'received_count' => ((int) $event->received_count) + 1,
                ])->save();

                return $event->fresh();
            }, 5);
        } catch (QueryException) {
            PaymentEvent::query()
                ->where('provider', 'klikqris')
                ->where('event_type', $type->value)
                ->where('idempotency_key', $idempotencyKey)
                ->update([
                    'last_received_at' => $receivedAt,
                    'received_count' => DB::raw('received_count + 1'),
                ]);

            return PaymentEvent::query()
                ->where('provider', 'klikqris')
                ->where('event_type', $type->value)
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();
        }
    }

    /** @param array<mixed> $payload */
    private function idempotencyKey(array $payload): string
    {
        $canonical = $this->canonicalize($payload);

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $canonical = [];
        foreach ($value as $key => $item) {
            $canonical[$key] = $this->canonicalize($item);
        }

        ksort($canonical);

        return $canonical;
    }
}
