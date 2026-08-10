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

    /**
     * Record one observation of the current safe outcome state for a Payment.
     * A new row is created for each transition, preserving the alert history;
     * repeated observations update only the current transition row.
     *
     * @return array{observations: int, alreadyAlerted: bool, transitioned: bool}
     */
    public function observeOwnerAlert(Payment $payment, string $state): array
    {
        return DB::transaction(function () use ($payment, $state): array {
            $event = $this->latestOwnerAlertEvent($payment, lock: true);
            $previousState = data_get($event?->raw_payload, 'state');
            $transitioned = $event === null || $previousState !== $state;

            if ($transitioned) {
                $event = PaymentEvent::create([
                    'payment_id' => $payment->id,
                    'provider' => PaymentProvider::KLIKQRIS,
                    'event_type' => PaymentEventType::OwnerAlert,
                    // The state is the module's own safe classification, not a
                    // provider payload; no secrets, QR media, or customer data.
                    'raw_payload' => ['state' => $state],
                    'idempotency_key' => $this->ownerAlertIdempotencyKey($payment, $event?->getKey(), $state),
                    'received_at' => now(),
                    'last_received_at' => now(),
                    'received_count' => 1,
                ]);

                return [
                    'observations' => 1,
                    'alreadyAlerted' => false,
                    'transitioned' => true,
                ];
            }

            $observations = ((int) $event->received_count) + 1;
            $alreadyAlerted = $event->processed_at !== null;
            $event->forceFill([
                'last_received_at' => now(),
                'received_count' => $observations,
            ])->save();

            return [
                'observations' => $observations,
                'alreadyAlerted' => $alreadyAlerted,
                'transitioned' => false,
            ];
        }, 5);
    }

    /**
     * Check whether the current transition still needs delivery.
     */
    public function ownerAlertNeedsDelivery(Payment $payment, string $state, int $observations): bool
    {
        return DB::transaction(function () use ($payment, $state, $observations): bool {
            $event = $this->latestOwnerAlertEvent($payment, lock: true);
            if ($event === null
                || data_get($event->raw_payload, 'state') !== $state
                || (int) $event->received_count !== $observations
                || $event->processed_at !== null) {
                return false;
            }

            return true;
        }, 5);
    }

    /**
     * Mark a transition delivered after Telegram has acknowledged the message.
     */
    public function markOwnerAlertDelivered(Payment $payment, string $state, int $observations): bool
    {
        return DB::transaction(function () use ($payment, $state, $observations): bool {
            $event = $this->latestOwnerAlertEvent($payment, lock: true);
            if ($event === null
                || data_get($event->raw_payload, 'state') !== $state
                || (int) $event->received_count !== $observations
                || $event->processed_at !== null) {
                return false;
            }

            $event->forceFill(['processed_at' => now()])->save();

            return true;
        }, 5);
    }

    private function ownerAlertIdempotencyKey(Payment $payment, int|string|null $previousEventId, string $state): string
    {
        return hash('sha256', implode(':', [
            'owner_alert',
            $payment->getKey(),
            $previousEventId ?? 'initial',
            $state,
        ]));
    }

    private function latestOwnerAlertEvent(Payment $payment, bool $lock = false): ?PaymentEvent
    {
        $query = PaymentEvent::query()
            ->where('payment_id', $payment->getKey())
            ->where('provider', PaymentProvider::KLIKQRIS)
            ->where('event_type', PaymentEventType::OwnerAlert->value)
            ->latest('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
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
