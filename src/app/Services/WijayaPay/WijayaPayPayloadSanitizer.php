<?php

namespace App\Services\WijayaPay;

/**
 * Keeps only provider facts that are safe for PaymentEvent, audit and
 * operational projections. Credentials, buyer identity, signatures and
 * arbitrary URLs never cross this boundary.
 */
final class WijayaPayPayloadSanitizer
{
    /** @var list<string> */
    private const SAFE_KEYS = [
        'success',
        'message',
        'data',
        'status',
        'status_pembayaran',
        'group',
        'code',
        'name',
        'image',
        'fee_amount',
        'fee_percent',
        'min_trx',
        'max_trx',
        'type_fee',
        'tutorial_pembayaran',
        'payment_name',
        'payment_method',
        'payment_methode',
        'total_bayar',
        'total_dibayar',
        'total_fee',
        'total_diterima',
        'amount_received',
        'ref_id',
        'trx_reference',
        'pay_code',
        'expired',
        'updated_at',
        'callback_url',
        'qr_image',
        'qr_string',
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
        if ($compact === ''
            || str_contains($compact, 'apikey')
            || str_contains($compact, 'codemerchant')
            || str_contains($compact, 'authorization')
        ) {
            return true;
        }

        foreach (['signature', 'secret', 'password', 'token', 'base64', 'qr', 'checkouturl'] as $needle) {
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
