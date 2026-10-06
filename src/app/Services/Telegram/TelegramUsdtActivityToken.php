<?php

namespace App\Services\Telegram;

use App\Services\UsdtBalance\CustomerUsdtActivityCursor;
use LogicException;

/**
 * Server-signed, Customer-scoped keyset cursor for USDT balance history. The
 * payload is versioned apart from the IDR activity token so the two cursors can
 * never be interchanged.
 */
final class TelegramUsdtActivityToken
{
    private const VERSION = 2;

    private const SIGNATURE_BYTES = 12;

    private const CURSOR_BYTES = 26;

    public function cursorToken(int $customerId, CustomerUsdtActivityCursor $cursor): string
    {
        return $this->encode(
            pack(
                'CJJCJ',
                self::VERSION,
                $customerId,
                $cursor->occurredAtMicros,
                $cursor->rank(),
                $cursor->id,
            ),
        );
    }

    /**
     * @return array{customer_id: int, cursor: CustomerUsdtActivityCursor}|null
     */
    public function decodeCursorToken(string $token): ?array
    {
        $payload = $this->decode($token, self::CURSOR_BYTES);
        if ($payload === null) {
            return null;
        }

        $decoded = unpack('Cversion/Jcustomer/Jtimestamp/Crank/Jid', $payload);
        if (! is_array($decoded)
            || (int) ($decoded['version'] ?? 0) !== self::VERSION
            || (int) ($decoded['customer'] ?? 0) < 1
            || (int) ($decoded['timestamp'] ?? 0) < 1
            || (int) ($decoded['id'] ?? 0) < 1) {
            return null;
        }

        $cursor = CustomerUsdtActivityCursor::fromRank(
            (int) ($decoded['rank'] ?? 0),
            (int) $decoded['timestamp'],
            (int) $decoded['id'],
        );
        if ($cursor === null) {
            return null;
        }

        return [
            'customer_id' => (int) $decoded['customer'],
            'cursor' => $cursor,
        ];
    }

    private function encode(string $payload): string
    {
        $signed = $payload.$this->signature($payload);

        return rtrim(strtr(base64_encode($signed), '+/', '-_'), '=');
    }

    private function decode(string $token, int $payloadBytes): ?string
    {
        if ($token === '') {
            return null;
        }

        $encoded = strtr($token, '-_', '+/');
        $padding = (4 - (strlen($encoded) % 4)) % 4;
        $decoded = base64_decode($encoded.str_repeat('=', $padding), true);
        if ($decoded === false || strlen($decoded) !== $payloadBytes + self::SIGNATURE_BYTES) {
            return null;
        }

        $payload = substr($decoded, 0, $payloadBytes);
        $signature = substr($decoded, $payloadBytes);
        if (! hash_equals($this->signature($payload), $signature)) {
            return null;
        }

        return $payload;
    }

    private function signature(string $payload): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new LogicException('The application key is required for Telegram activity tokens.');
        }

        return substr(hash_hmac('sha256', $payload, $key, true), 0, self::SIGNATURE_BYTES);
    }
}
