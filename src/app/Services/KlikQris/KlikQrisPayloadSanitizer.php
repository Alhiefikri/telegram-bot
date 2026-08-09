<?php

namespace App\Services\KlikQris;

final class KlikQrisPayloadSanitizer
{
    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    public static function sanitize(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key, $value)) {
                continue;
            }

            $sanitized[$key] = is_array($value) ? self::sanitize($value) : $value;
        }

        return $sanitized;
    }

    private static function isSensitiveKey(string $key, mixed $value): bool
    {
        $normalized = strtolower($key);
        $compact = preg_replace('/[^a-z0-9]/', '', $normalized) ?? $normalized;

        if (in_array($compact, ['signature', 'qrisimage', 'qrimage', 'qrisbase64', 'qrbase64'], true)
            || str_contains($compact, 'signature')
            || (str_contains($compact, 'base64') && (str_contains($compact, 'qris') || str_contains($compact, 'qr') || str_contains($compact, 'image')))) {
            return true;
        }

        if (str_contains($compact, 'debug')
            || str_contains($compact, 'rawpayload')
            || str_contains($compact, 'rawresponse')) {
            return true;
        }

        if (str_contains($compact, 'qrisurl')) {
            if (! is_string($value)) {
                return true;
            }

            $parts = parse_url($value);

            return ! is_array($parts)
                || array_key_exists('query', $parts)
                || array_key_exists('fragment', $parts);
        }

        if (in_array($compact, ['merchant', 'merchantid', 'idmerchant'], true)) {
            return true;
        }

        if (str_contains($compact, 'merchant') && ! str_contains($compact, 'display') && ! str_contains($compact, 'name')) {
            return true;
        }

        foreach (['apikey', 'authorization', 'token', 'secret'] as $needle) {
            if (str_contains($compact, $needle)) {
                return true;
            }
        }

        return false;
    }
}
