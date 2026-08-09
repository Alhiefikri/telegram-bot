<?php

namespace App\Services\KlikQris;

use App\Enums\PaymentEventType;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Support\PaymentProvider;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class KlikQrisPaymentEventLogger
{
    public function logProviderCreate(Payment $payment, KlikQrisTransaction $transaction): PaymentEvent
    {
        $receivedAt = now();

        return PaymentEvent::create([
            'payment_id' => $payment->id,
            'provider' => PaymentProvider::KLIKQRIS,
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
        $merchantRef = $callback->isSupportedStatus()
            ? $callback->orderId()
            : hash('sha256', $callback->orderId());

        return $this->logCanonical(
            PaymentEventType::Callback,
            $merchantRef,
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
        $canonicalPayload = $this->canonicalize($sanitizedPayload);
        $idempotencyKey = $this->idempotencyKey($canonicalPayload);

        try {
            return DB::transaction(function () use ($type, $merchantRef, $status, $canonicalPayload, $payment, $receivedAt, $idempotencyKey): PaymentEvent {
                $event = PaymentEvent::query()
                    ->where('provider', PaymentProvider::KLIKQRIS)
                    ->where('event_type', $type->value)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($event === null) {
                    return PaymentEvent::create([
                        'payment_id' => $payment?->id,
                        'provider' => PaymentProvider::KLIKQRIS,
                        'event_type' => $type,
                        'merchant_ref' => $merchantRef,
                        'provider_status' => $status,
                        'raw_payload' => $canonicalPayload,
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
        } catch (QueryException $exception) {
            if (! $this->isUniqueConflict($exception)) {
                throw $exception;
            }

            PaymentEvent::query()
                ->where('provider', PaymentProvider::KLIKQRIS)
                ->where('event_type', $type->value)
                ->where('idempotency_key', $idempotencyKey)
                ->update([
                    'payment_id' => DB::raw('COALESCE(payment_id, '.($payment === null ? 'NULL' : DB::getPdo()->quote((string) $payment->id)).')'),
                    'last_received_at' => $receivedAt,
                    'received_count' => DB::raw('received_count + 1'),
                ]);

            return PaymentEvent::query()
                ->where('provider', PaymentProvider::KLIKQRIS)
                ->where('event_type', $type->value)
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();
        }
    }

    /** @param array<mixed> $canonicalPayload */
    private function idempotencyKey(array $canonicalPayload): string
    {
        return hash('sha256', json_encode($canonicalPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value, string|int|null $key = null): mixed
    {
        if ($key === 'amount' || $key === 'total_amount') {
            return KlikQrisAmount::from($value)->rupiah();
        }

        if (! is_array($value)) {
            return $value;
        }

        $canonical = [];
        foreach ($value as $childKey => $item) {
            $canonical[$childKey] = $this->canonicalize($item, $childKey);
        }

        ksort($canonical);

        return $canonical;
    }

    private function isUniqueConflict(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $constraint = (string) ($exception->errorInfo[2] ?? $exception->getMessage());

        return in_array($sqlState, ['23000', '23505'], true)
            && str_contains($constraint, 'payment_events_provider_event_idempotency_unique');
    }
}
