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
            if (is_string($key) && self::isSensitiveKey($key)) {
                continue;
            }

            $sanitized[$key] = is_array($value) ? self::sanitize($value) : $value;
        }

        return $sanitized;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        if (in_array($normalized, ['signature', 'qris_image'], true)) {
            return true;
        }

        foreach (['api_key', 'authorization', 'token', 'secret'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }
}
