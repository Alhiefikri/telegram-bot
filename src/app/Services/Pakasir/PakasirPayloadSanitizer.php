<?php

namespace App\Services\Pakasir;

final class PakasirPayloadSanitizer
{
    /**
     * @var list<string>
     */
    private const SENSITIVE_KEY_FRAGMENTS = [
        'paymentnumber',
        'apikey',
        'secret',
        'token',
        'password',
        'authorization',
        'signature',
    ];

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function sanitize(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            if (self::isSensitiveKey((string) $key)) {
                continue;
            }

            $sanitized[$key] = is_array($value) ? self::sanitize($value) : $value;
        }

        return $sanitized;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function containsSensitiveKeys(array $payload): bool
    {
        foreach ($payload as $key => $value) {
            if (self::isSensitiveKey((string) $key)) {
                return true;
            }

            if (is_array($value) && self::containsSensitiveKeys($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function containsStringValue(array $payload, string $needle): bool
    {
        if ($needle === '') {
            return false;
        }

        foreach ($payload as $value) {
            if (is_array($value)) {
                if (self::containsStringValue($value, $needle)) {
                    return true;
                }

                continue;
            }

            if (is_scalar($value) && str_contains((string) $value, $needle)) {
                return true;
            }
        }

        return false;
    }

    public static function isSensitiveKey(string $key): bool
    {
        $normalized = self::normalizeKey($key);

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeKey(string $key): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $key) ?? $key);
    }
}
