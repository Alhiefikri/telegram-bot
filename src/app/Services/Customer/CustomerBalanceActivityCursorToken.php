<?php

namespace App\Services\Customer;

use App\Services\StoreBalance\StoreBalanceActivityCursor;
use App\Services\UsdtBalance\CustomerUsdtActivityCursor;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

final class CustomerBalanceActivityCursorToken
{
    private const VERSION = 1;

    public function storeBalanceToken(int $customerId, StoreBalanceActivityCursor $cursor): string
    {
        return $this->encode($customerId, 'IDR', $cursor->occurredAtMicros, $cursor->rank(), $cursor->id);
    }

    public function decodeStoreBalanceToken(string $token, int $customerId): ?StoreBalanceActivityCursor
    {
        $payload = $this->decode($token, $customerId, 'IDR');

        return $payload === null
            ? null
            : StoreBalanceActivityCursor::fromRank($payload['rank'], $payload['occurred_at'], $payload['id']);
    }

    public function usdtToken(int $customerId, CustomerUsdtActivityCursor $cursor): string
    {
        return $this->encode($customerId, 'USDT', $cursor->occurredAtMicros, $cursor->rank(), $cursor->id);
    }

    public function decodeUsdtToken(string $token, int $customerId): ?CustomerUsdtActivityCursor
    {
        $payload = $this->decode($token, $customerId, 'USDT');

        return $payload === null
            ? null
            : CustomerUsdtActivityCursor::fromRank($payload['rank'], $payload['occurred_at'], $payload['id']);
    }

    private function encode(int $customerId, string $currency, int $occurredAt, int $rank, int $id): string
    {
        return Crypt::encryptString(json_encode([
            'version' => self::VERSION,
            'customer_id' => $customerId,
            'currency' => $currency,
            'occurred_at' => $occurredAt,
            'rank' => $rank,
            'id' => $id,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{occurred_at: int, rank: int, id: int}|null */
    private function decode(string $token, int $customerId, string $currency): ?array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }

        if (! is_array($payload)
            || ($payload['version'] ?? null) !== self::VERSION
            || ($payload['customer_id'] ?? null) !== $customerId
            || ($payload['currency'] ?? null) !== $currency
            || ! is_int($payload['occurred_at'] ?? null)
            || ! is_int($payload['rank'] ?? null)
            || ! is_int($payload['id'] ?? null)
            || $payload['occurred_at'] < 1
            || $payload['rank'] < 1
            || $payload['id'] < 1) {
            return null;
        }

        return [
            'occurred_at' => $payload['occurred_at'],
            'rank' => $payload['rank'],
            'id' => $payload['id'],
        ];
    }
}
