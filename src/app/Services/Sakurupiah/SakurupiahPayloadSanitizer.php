<?php

namespace App\Services\Sakurupiah;

final class SakurupiahPayloadSanitizer
{
    /**
     * These are provider facts useful for reconciliation/audit. Unknown keys
     * are dropped instead of being copied and classified after the fact.
     *
     * @var list<string>
     */
    private const SAFE_KEYS = [
        'status',
        'message',
        'data',
        'via',
        'payment_kode',
        'trx_id',
        'merchant_ref',
        'total',
        'amount',
        'fee',
        'amount_merchant',
        'merchant_fee',
        'date',
        'time',
        'tanggal',
        'waktu',
        'expired',
        'payment_status',
        'status_kode',
        'kode',
        'minimal',
        'maksimal',
        'biaya',
        'percent',
        'tipe',
        'addition',
        'tambahan_biaya',
        'jenis',
        'default_expired',
        'settlement',
        'guide',
        'title',
        'payment_guide',
        'currency',
    ];

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    public static function sanitize(array $payload): array
    {
        return self::sanitizeArray($payload);
    }

    /**
     * @param  array<mixed>  $payload
     */
    public static function containsSensitiveKeys(array $payload): bool
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key, $value)) {
                return true;
            }

            if (is_array($value) && self::containsSensitiveKeys($value)) {
                return true;
            }
        }

        return false;
    }

    public static function isSensitiveKey(string $key, mixed $value = null): bool
    {
        $compact = self::compact($key);
        if ($compact === '' || str_contains($compact, 'apikey') || str_contains($compact, 'authorization')) {
            return true;
        }

        foreach (['signature', 'secret', 'password', 'token', 'base64', 'qris', 'qr', 'paymentno', 'checkouturl'] as $needle) {
            if (str_contains($compact, $needle)) {
                return true;
            }
        }

        foreach (['name', 'nama', 'email', 'phone', 'customer', 'buyer', 'telegramid', 'recoverykey', 'address'] as $needle) {
            if ($compact === $needle || str_contains($compact, $needle)) {
                return true;
            }
        }

        if (str_contains($compact, 'url')) {
            return ! self::isSafeUrl($value);
        }

        return false;
    }

    /**
     * @param  array<mixed>  $payload
     */
    public static function containsStringValue(array $payload, string $needle): bool
    {
        foreach ($payload as $value) {
            if (is_array($value) && self::containsStringValue($value, $needle)) {
                return true;
            }

            if (is_string($value) && str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    private static function sanitizeArray(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            if (is_int($key)) {
                if (is_array($value)) {
                    $sanitized[$key] = self::sanitizeArray($value);
                }

                continue;
            }

            if (! self::isSafeKey($key) || self::isSensitiveKey($key, $value)) {
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = self::sanitizeArray($value);

                continue;
            }

            if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                if (self::isSafeValue($value)) {
                    $sanitized[$key] = $value;
                }
            }
        }

        return $sanitized;
    }

    private static function isSafeKey(string $key): bool
    {
        return in_array(strtolower($key), self::SAFE_KEYS, true);
    }

    private static function isSafeValue(mixed $value): bool
    {
        if (! is_string($value)) {
            return true;
        }

        if (preg_match('/\bBearer\s+\S+/i', $value) === 1
            || preg_match('/\b(?:api[_-]?key|authorization|callback[_-]?signature|signature|secret|password|token|qr(?:is)?(?:[_-]?base64)?)\s*[:=]\s*\S+/i', $value) === 1
        ) {
            return false;
        }

        return preg_match('/https?:\/\/[^\s?]+\?[^\s]*/i', $value) !== 1;
    }

    private static function isSafeUrl(mixed $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }

        $parts = parse_url($value);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && isset($parts['host'])
            && ! array_key_exists('user', $parts)
            && ! array_key_exists('pass', $parts)
            && ! array_key_exists('query', $parts)
            && ! array_key_exists('fragment', $parts)
            && ! array_key_exists('port', $parts);
    }

    private static function compact(string $key): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($key)) ?? strtolower($key);
    }
}
