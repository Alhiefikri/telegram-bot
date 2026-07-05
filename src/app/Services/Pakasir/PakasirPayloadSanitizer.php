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

    /**
     * @param  list<string>  $sensitiveValues
     */
    public static function redactText(string $text, array $sensitiveValues = []): string
    {
        if ($text === '') {
            return $text;
        }

        $redacted = self::redactUrlsWithSensitiveQueries($text);
        $redacted = self::redactSensitiveAssignments($redacted);

        return self::redactSensitiveValues($redacted, $sensitiveValues);
    }

    private static function redactUrlsWithSensitiveQueries(string $text): string
    {
        return preg_replace_callback(
            '#https?://[^\s<>"\']+#i',
            fn (array $matches): string => self::redactUrlWithSensitiveQuery($matches[0]),
            $text,
        ) ?? $text;
    }

    private static function redactUrlWithSensitiveQuery(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['query']) || ! self::queryContainsSensitiveKey($parts['query'])) {
            return $url;
        }

        $authority = (string) ($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $authority .= ':'.$parts['port'];
        }

        $redacted = ((string) ($parts['scheme'] ?? 'https')).'://'.$authority.($parts['path'] ?? '').'?[redacted-query]';

        if (isset($parts['fragment'])) {
            $redacted .= '#'.$parts['fragment'];
        }

        return $redacted;
    }

    private static function queryContainsSensitiveKey(string $query): bool
    {
        foreach (explode('&', $query) as $parameter) {
            $key = rawurldecode(explode('=', $parameter, 2)[0]);

            if (self::isSensitiveKey($key)) {
                return true;
            }
        }

        return false;
    }

    private static function redactSensitiveAssignments(string $text): string
    {
        return preg_replace_callback(
            '/(^|[\s?&])([A-Za-z0-9_.-]+)\s*=\s*([^\s&]+)/',
            function (array $matches): string {
                if (! self::isSensitiveKey($matches[2])) {
                    return $matches[0];
                }

                return $matches[1].'[redacted-secret]';
            },
            $text,
        ) ?? $text;
    }

    /**
     * @param  list<string>  $sensitiveValues
     */
    private static function redactSensitiveValues(string $text, array $sensitiveValues): string
    {
        foreach ($sensitiveValues as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $secret = (string) $value;
            if ($secret === '') {
                continue;
            }

            foreach (array_unique([$secret, rawurlencode($secret), urlencode($secret)]) as $needle) {
                $text = str_replace($needle, '[redacted-secret]', $text);
            }
        }

        return $text;
    }
}
