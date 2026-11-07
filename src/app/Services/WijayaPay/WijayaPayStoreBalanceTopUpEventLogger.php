<?php

namespace App\Services\WijayaPay;

use App\Models\StoreBalanceTopUp;
use App\Models\StoreBalanceTopUpEvent;
use App\Support\PaymentProvider;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Durable, sanitized callback evidence for a WijayaPay Store Balance top-up.
 * It records the signed provider claim before any authoritative verification;
 * processed_at is set only after the rail has answered that claim.
 */
final class WijayaPayStoreBalanceTopUpEventLogger
{
    public function logCallback(WijayaPayCallback $callback, StoreBalanceTopUp $topUp): StoreBalanceTopUpEvent
    {
        return $this->logCanonical(
            eventType: 'callback',
            merchantRef: (string) $topUp->merchant_ref,
            status: $callback->statusValue(),
            payload: $callback->sanitizedPayload,
            topUp: $topUp,
            providerReference: $callback->trxReference,
        );
    }

    public function markProcessed(StoreBalanceTopUpEvent $event): StoreBalanceTopUpEvent
    {
        return DB::transaction(function () use ($event): StoreBalanceTopUpEvent {
            $locked = StoreBalanceTopUpEvent::query()
                ->whereKey($event->getKey())
                ->lockForUpdate()
                ->first();
            if ($locked === null) {
                return $event;
            }

            if ($locked->processed_at === null) {
                $locked->forceFill(['processed_at' => now()])->save();
            }

            return $locked->fresh();
        }, attempts: 5);
    }

    /** @param array<mixed> $payload */
    private function logCanonical(
        string $eventType,
        string $merchantRef,
        string $status,
        array $payload,
        StoreBalanceTopUp $topUp,
        ?string $providerReference = null,
    ): StoreBalanceTopUpEvent {
        $receivedAt = now();
        $canonicalPayload = WijayaPayPayloadSanitizer::sanitize($payload);
        $idempotencyKey = hash('sha256', $eventType.':'.json_encode(
            $this->canonicalize($canonicalPayload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        try {
            return DB::transaction(function () use (
                $eventType,
                $merchantRef,
                $status,
                $canonicalPayload,
                $topUp,
                $providerReference,
                $receivedAt,
                $idempotencyKey,
            ): StoreBalanceTopUpEvent {
                $event = StoreBalanceTopUpEvent::query()
                    ->where('provider', PaymentProvider::WIJAYAPAY)
                    ->where('event_type', $eventType)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($event === null) {
                    return StoreBalanceTopUpEvent::query()->create([
                        'store_balance_top_up_id' => $topUp->getKey(),
                        'provider' => PaymentProvider::WIJAYAPAY,
                        'event_type' => $eventType,
                        'merchant_ref' => $merchantRef,
                        'provider_reference' => $providerReference,
                        'provider_status' => $status,
                        'raw_payload' => $canonicalPayload,
                        'idempotency_key' => $idempotencyKey,
                        'received_at' => $receivedAt,
                        'last_received_at' => $receivedAt,
                        'received_count' => 1,
                    ]);
                }

                $event->forceFill([
                    'store_balance_top_up_id' => $event->store_balance_top_up_id ?: $topUp->getKey(),
                    'last_received_at' => $receivedAt,
                    'received_count' => ((int) $event->received_count) + 1,
                ])->save();

                return $event->fresh();
            }, attempts: 5);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConflict($exception)) {
                throw $exception;
            }

            StoreBalanceTopUpEvent::query()
                ->where('provider', PaymentProvider::WIJAYAPAY)
                ->where('event_type', $eventType)
                ->where('idempotency_key', $idempotencyKey)
                ->update([
                    'last_received_at' => $receivedAt,
                    'received_count' => DB::raw('received_count + 1'),
                ]);

            return StoreBalanceTopUpEvent::query()
                ->where('provider', PaymentProvider::WIJAYAPAY)
                ->where('event_type', $eventType)
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();
        }
    }

    private function isUniqueConflict(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $constraint = (string) ($exception->errorInfo[2] ?? $exception->getMessage());

        return in_array($sqlState, ['23000', '23505'], true)
            && str_contains($constraint, 'store_balance_top_up_events_idempotency_unique');
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
