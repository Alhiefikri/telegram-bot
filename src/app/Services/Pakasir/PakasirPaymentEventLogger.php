<?php

namespace App\Services\Pakasir;

use App\Enums\PaymentEventType;
use App\Models\Payment;
use App\Models\PaymentEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class PakasirPaymentEventLogger
{
    public function log(PaymentEventType $type, array $rawPayload, ?Payment $payment, ?PakasirTransaction $transaction = null): PaymentEvent
    {
        $transaction ??= PakasirTransaction::fromPayload($rawPayload);
        $sanitizedPayload = PakasirPayloadSanitizer::sanitize($rawPayload);
        $receivedAt = now();
        $idempotencyKey = $this->idempotencyKey($type, $sanitizedPayload);

        $attributes = [
            'payment_id' => $payment?->id,
            'provider' => 'pakasir',
            'event_type' => $type,
            'merchant_ref' => $transaction?->orderId(),
            'provider_status' => $transaction?->status(),
            'raw_payload' => $sanitizedPayload,
            'received_at' => $receivedAt,
            'last_received_at' => $receivedAt,
            'received_count' => 1,
            'idempotency_key' => $idempotencyKey,
        ];

        if ($idempotencyKey === null) {
            return PaymentEvent::create($attributes);
        }

        return $this->coalesceCallbackEvent($type, $idempotencyKey, $attributes);
    }

    public function logProviderCreate(Payment $payment, PakasirQrisTransaction $transaction): PaymentEvent
    {
        $receivedAt = now();

        return PaymentEvent::create([
            'payment_id' => $payment->id,
            'provider' => 'pakasir',
            'event_type' => PaymentEventType::ProviderCreate,
            'merchant_ref' => $transaction->orderId(),
            'provider_status' => null,
            'raw_payload' => $transaction->sanitizedPayload(),
            'received_at' => $receivedAt,
            'last_received_at' => $receivedAt,
            'received_count' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function coalesceCallbackEvent(PaymentEventType $type, string $idempotencyKey, array $attributes): PaymentEvent
    {
        try {
            return DB::transaction(function () use ($type, $idempotencyKey, $attributes): PaymentEvent {
                $event = $this->findCoalescedEvent($type, $idempotencyKey, true);

                if ($event !== null) {
                    return $this->recordDuplicateDelivery($event, $attributes);
                }

                return PaymentEvent::create($attributes);
            });
        } catch (QueryException $exception) {
            return DB::transaction(function () use ($type, $idempotencyKey, $attributes, $exception): PaymentEvent {
                $event = $this->findCoalescedEvent($type, $idempotencyKey, true);

                if ($event === null) {
                    throw $exception;
                }

                return $this->recordDuplicateDelivery($event, $attributes);
            });
        }
    }

    private function findCoalescedEvent(PaymentEventType $type, string $idempotencyKey, bool $lock): ?PaymentEvent
    {
        $query = PaymentEvent::query()
            ->where('provider', 'pakasir')
            ->where('event_type', $type->value)
            ->where('idempotency_key', $idempotencyKey);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function recordDuplicateDelivery(PaymentEvent $event, array $attributes): PaymentEvent
    {
        $updates = [
            'received_count' => max(1, (int) $event->received_count) + 1,
            'last_received_at' => $attributes['received_at'],
        ];

        if ($event->payment_id === null && $attributes['payment_id'] !== null) {
            $updates['payment_id'] = $attributes['payment_id'];
        }

        $event->update($updates);

        return $event->fresh();
    }

    /**
     * @param  array<string, mixed>  $sanitizedPayload
     */
    private function idempotencyKey(PaymentEventType $type, array $sanitizedPayload): ?string
    {
        if ($type !== PaymentEventType::Callback) {
            return null;
        }

        $payload = json_encode($this->canonicalize($sanitizedPayload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', 'pakasir|'.$type->value.'|'.$payload);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        $canonical = [];
        $keys = array_keys($value);
        sort($keys, SORT_STRING);

        foreach ($keys as $key) {
            $canonical[$key] = $this->canonicalize($value[$key]);
        }

        return $canonical;
    }
}
