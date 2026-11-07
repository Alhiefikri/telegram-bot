<?php

namespace App\Services\WijayaPay;

use App\Enums\PaymentEventType;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Support\PaymentProvider;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class WijayaPayPaymentEventLogger
{
    public function logProviderCreate(Payment $payment, WijayaPayInvoice $invoice): PaymentEvent
    {
        return $this->logCanonical(
            type: PaymentEventType::ProviderCreate,
            payment: $payment,
            merchantRef: $invoice->refId,
            providerReference: $invoice->trxReference,
            providerStatus: $invoice->statusValue(),
            payload: $invoice->sanitizedPayload,
            operation: 'create',
        );
    }

    public function logCallback(WijayaPayCallback $callback, ?Payment $payment): PaymentEvent
    {
        return $this->logCanonical(
            type: PaymentEventType::Callback,
            payment: $payment,
            merchantRef: $callback->refId,
            providerReference: $callback->trxReference,
            providerStatus: $callback->statusValue(),
            payload: $callback->sanitizedPayload,
            operation: 'callback',
            paymentScoped: false,
        );
    }

    public function logReconciliation(Payment $payment, WijayaPayStatusResponse $status): PaymentEvent
    {
        return $this->logCanonical(
            type: PaymentEventType::Reconciliation,
            payment: $payment,
            merchantRef: $status->refId,
            providerReference: $status->trxReference,
            providerStatus: $status->statusValue(),
            payload: $status->sanitizedPayload,
            operation: 'recovery',
        );
    }

    public function logCreateObservation(
        Payment $payment,
        string $state,
        ?string $reason = null,
    ): PaymentEvent {
        return $this->logCanonical(
            type: PaymentEventType::ProviderCreate,
            payment: $payment,
            merchantRef: (string) $payment->merchant_ref,
            providerReference: $payment->provider_reference,
            providerStatus: $state,
            payload: array_filter([
                'status' => $state,
                'message' => $reason,
            ], static fn (mixed $value): bool => $value !== null),
            operation: 'create_observation',
        );
    }

    public function logRecoveryObservation(
        Payment $payment,
        string $state,
        ?string $reason = null,
    ): PaymentEvent {
        return $this->logCanonical(
            type: PaymentEventType::Reconciliation,
            payment: $payment,
            merchantRef: (string) $payment->merchant_ref,
            providerReference: $payment->provider_reference,
            providerStatus: $state,
            payload: array_filter([
                'status' => $state,
                'message' => $reason,
            ], static fn (mixed $value): bool => $value !== null),
            operation: 'recovery_observation',
        );
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function logCanonical(
        PaymentEventType $type,
        ?Payment $payment,
        ?string $merchantRef,
        ?string $providerReference,
        string $providerStatus,
        array $payload,
        string $operation,
        bool $paymentScoped = true,
    ): PaymentEvent {
        $receivedAt = now();
        $canonicalPayload = WijayaPayPayloadSanitizer::sanitize($payload);
        $idempotencyPayload = [
            'operation' => $operation,
            'merchant_ref' => $merchantRef,
            'provider_reference' => $providerReference,
            'provider_status' => $providerStatus,
            'payload' => $this->canonicalize($canonicalPayload),
        ];
        if ($paymentScoped) {
            $idempotencyPayload['payment_id'] = $payment?->getKey();
        }
        $idempotencyKey = hash('sha256', json_encode(
            $idempotencyPayload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        try {
            return DB::transaction(function () use (
                $type,
                $payment,
                $merchantRef,
                $providerReference,
                $providerStatus,
                $canonicalPayload,
                $receivedAt,
                $idempotencyKey,
            ): PaymentEvent {
                $event = PaymentEvent::query()
                    ->where('provider', PaymentProvider::WIJAYAPAY)
                    ->where('event_type', $type->value)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($event === null) {
                    return PaymentEvent::create([
                        'payment_id' => $payment?->getKey(),
                        'provider' => PaymentProvider::WIJAYAPAY,
                        'event_type' => $type,
                        'merchant_ref' => $merchantRef,
                        'provider_reference' => $providerReference,
                        'provider_status' => $providerStatus,
                        'raw_payload' => $canonicalPayload,
                        'idempotency_key' => $idempotencyKey,
                        'received_at' => $receivedAt,
                        'last_received_at' => $receivedAt,
                        'received_count' => 1,
                    ]);
                }

                $event->forceFill([
                    'payment_id' => $event->payment_id ?? $payment?->getKey(),
                    'provider_reference' => $event->provider_reference ?? $providerReference,
                    'last_received_at' => $receivedAt,
                    'received_count' => ((int) $event->received_count) + 1,
                ])->save();

                return $event->fresh();
            }, 5);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConflict($exception)) {
                throw $exception;
            }

            $updates = [
                'last_received_at' => $receivedAt,
                'received_count' => DB::raw('received_count + 1'),
            ];
            if ($payment !== null) {
                $updates['payment_id'] = $payment->getKey();
            }
            if ($providerReference !== null) {
                $updates['provider_reference'] = $providerReference;
            }

            PaymentEvent::query()
                ->where('provider', PaymentProvider::WIJAYAPAY)
                ->where('event_type', $type->value)
                ->where('idempotency_key', $idempotencyKey)
                ->update($updates);

            return PaymentEvent::query()
                ->where('provider', PaymentProvider::WIJAYAPAY)
                ->where('event_type', $type->value)
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();
        }
    }

    private function isUniqueConflict(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $constraint = (string) ($exception->errorInfo[2] ?? $exception->getMessage());

        return in_array($sqlState, ['23000', '23505'], true)
            && str_contains($constraint, 'payment_events_provider_event_idempotency_unique');
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
