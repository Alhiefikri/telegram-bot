<?php

namespace App\Services\KlikQris;

use App\Models\StoreBalanceTopUp;
use App\Models\StoreBalanceTopUpEvent;
use App\Support\PaymentProvider;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class StoreBalanceTopUpEventLogger
{
    public function logProviderCreate(StoreBalanceTopUp $topUp, KlikQrisTransaction $transaction): StoreBalanceTopUpEvent
    {
        $receivedAt = now();
        $payload = $this->canonicalize($this->withoutQrisUrlKeys($transaction->sanitizedPayload()));
        $idempotencyKey = $this->idempotencyKey('provider_create', $payload);

        try {
            return DB::transaction(function () use ($topUp, $transaction, $payload, $idempotencyKey, $receivedAt): StoreBalanceTopUpEvent {
                $event = StoreBalanceTopUpEvent::query()
                    ->where('provider', PaymentProvider::KLIKQRIS)
                    ->where('event_type', 'provider_create')
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($event !== null) {
                    $event->forceFill(['last_received_at' => $receivedAt])->save();

                    return $event->fresh();
                }

                return StoreBalanceTopUpEvent::query()->create([
                    'store_balance_top_up_id' => $topUp->getKey(),
                    'provider' => PaymentProvider::KLIKQRIS,
                    'event_type' => 'provider_create',
                    'merchant_ref' => $transaction->orderId(),
                    'provider_reference' => $transaction->providerReference(),
                    'provider_status' => $transaction->status(),
                    'raw_payload' => $payload,
                    'idempotency_key' => $idempotencyKey,
                    'received_at' => $receivedAt,
                    'last_received_at' => $receivedAt,
                    'received_count' => 1,
                ]);
            }, 5);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConflict($exception)) {
                throw $exception;
            }

            return StoreBalanceTopUpEvent::query()
                ->where('provider', PaymentProvider::KLIKQRIS)
                ->where('event_type', 'provider_create')
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();
        }
    }

    public function logCallback(KlikQrisCallback $callback, StoreBalanceTopUp $topUp): StoreBalanceTopUpEvent
    {
        return $this->logCanonical(
            'callback',
            $callback->orderId(),
            $callback->status(),
            $callback->sanitizedPayload(),
            $topUp,
        );
    }

    public function logReconciliation(KlikQrisTransaction $transaction, StoreBalanceTopUp $topUp): StoreBalanceTopUpEvent
    {
        return $this->logCanonical(
            'reconciliation',
            $transaction->orderId(),
            $transaction->status(),
            $transaction->sanitizedPayload(),
            $topUp,
        );
    }

    /** @param array<mixed> $sanitizedPayload */
    private function logCanonical(
        string $eventType,
        string $merchantRef,
        string $status,
        array $sanitizedPayload,
        StoreBalanceTopUp $topUp,
    ): StoreBalanceTopUpEvent {
        $receivedAt = now();
        $canonicalPayload = $this->canonicalize($this->withoutQrisUrlKeys($sanitizedPayload));
        $idempotencyKey = $this->idempotencyKey($eventType, $canonicalPayload);

        try {
            return DB::transaction(function () use ($eventType, $merchantRef, $status, $canonicalPayload, $topUp, $receivedAt, $idempotencyKey): StoreBalanceTopUpEvent {
                $event = StoreBalanceTopUpEvent::query()
                    ->where('provider', PaymentProvider::KLIKQRIS)
                    ->where('event_type', $eventType)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($event === null) {
                    return StoreBalanceTopUpEvent::query()->create([
                        'store_balance_top_up_id' => $topUp->getKey(),
                        'provider' => PaymentProvider::KLIKQRIS,
                        'event_type' => $eventType,
                        'merchant_ref' => $merchantRef,
                        'provider_reference' => null,
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
            }, 5);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConflict($exception)) {
                throw $exception;
            }

            StoreBalanceTopUpEvent::query()
                ->where('provider', PaymentProvider::KLIKQRIS)
                ->where('event_type', $eventType)
                ->where('idempotency_key', $idempotencyKey)
                ->update([
                    'store_balance_top_up_id' => $topUp->getKey(),
                    'last_received_at' => $receivedAt,
                    'received_count' => DB::raw('received_count + 1'),
                ]);

            return StoreBalanceTopUpEvent::query()
                ->where('provider', PaymentProvider::KLIKQRIS)
                ->where('event_type', $eventType)
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();
        }
    }

    /** @param array<mixed> $payload */
    private function withoutQrisUrlKeys(array $payload): array
    {
        $safe = [];
        foreach ($payload as $key => $value) {
            $normalized = is_string($key)
                ? (preg_replace('/[^a-z0-9]/', '', strtolower($key)) ?? strtolower($key))
                : '';
            if ($normalized !== ''
                && str_ends_with($normalized, 'url')
                && (str_contains($normalized, 'qris') || str_contains($normalized, 'qr'))) {
                continue;
            }

            $safe[$key] = is_array($value) ? $this->withoutQrisUrlKeys($value) : $value;
        }

        return $safe;
    }

    /** @param array<mixed> $payload */
    private function idempotencyKey(string $eventType, array $payload): string
    {
        return hash('sha256', $eventType.':'.json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
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
            && str_contains($constraint, 'store_balance_top_up_events_idempotency_unique');
    }
}
