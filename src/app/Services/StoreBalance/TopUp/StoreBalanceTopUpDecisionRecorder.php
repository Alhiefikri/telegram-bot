<?php

namespace App\Services\StoreBalance\TopUp;

use App\Models\StoreBalanceTopUp;
use App\Models\StoreBalanceTopUpEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Record one operator or provider observation for a top-up.
 *
 * Rows are provider-scoped and idempotent on the canonical payload, so a
 * replayed decision increments the observed count instead of appending a
 * second economic-relevant event.
 */
final class StoreBalanceTopUpDecisionRecorder
{
    /**
     * @param  array<mixed>  $payload
     */
    public function record(
        StoreBalanceTopUp $topUp,
        string $provider,
        string $eventType,
        string $providerStatus,
        array $payload,
        ?string $providerReference = null,
    ): StoreBalanceTopUpEvent {
        $provider = strtolower(trim($provider));
        $receivedAt = now();
        $canonical = $this->canonicalize($payload);
        $idempotencyKey = hash('sha256', $eventType.':'.json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($topUp, $provider, $eventType, $providerStatus, $canonical, $providerReference, $receivedAt, $idempotencyKey): StoreBalanceTopUpEvent {
                $event = StoreBalanceTopUpEvent::query()
                    ->where('provider', $provider)
                    ->where('event_type', $eventType)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($event === null) {
                    return StoreBalanceTopUpEvent::query()->create([
                        'store_balance_top_up_id' => $topUp->getKey(),
                        'provider' => $provider,
                        'event_type' => $eventType,
                        'merchant_ref' => (string) $topUp->merchant_ref,
                        'provider_reference' => $providerReference,
                        'provider_status' => $providerStatus,
                        'raw_payload' => $canonical,
                        'idempotency_key' => $idempotencyKey,
                        'received_at' => $receivedAt,
                        'last_received_at' => $receivedAt,
                        'received_count' => 1,
                    ]);
                }

                $event->forceFill([
                    'last_received_at' => $receivedAt,
                    'received_count' => ((int) $event->received_count) + 1,
                ])->save();

                return $event->fresh();
            }, 5);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConflict($exception)) {
                throw $exception;
            }

            StoreBalanceTopUpEvent::query()
                ->where('provider', $provider)
                ->where('event_type', $eventType)
                ->where('idempotency_key', $idempotencyKey)
                ->update([
                    'last_received_at' => $receivedAt,
                    'received_count' => DB::raw('received_count + 1'),
                ]);

            return StoreBalanceTopUpEvent::query()
                ->where('provider', $provider)
                ->where('event_type', $eventType)
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();
        }
    }

    /** @param array<mixed> $value */
    private function canonicalize(array $value): array
    {
        ksort($value);

        return $value;
    }

    private function isUniqueConflict(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $constraint = (string) ($exception->errorInfo[2] ?? $exception->getMessage());

        return in_array($sqlState, ['23000', '23505'], true)
            && str_contains($constraint, 'store_balance_top_up_events_idempotency_unique');
    }
}
