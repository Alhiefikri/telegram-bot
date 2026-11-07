<?php

namespace App\Services\WijayaPay;

use InvalidArgumentException;

/**
 * Shared URL boundary for provider-issued presentation values.
 *
 * Only HTTPS URLs on the exact configured host allowlist are usable.
 * Userinfo, custom ports and fragments are rejected so a provider value can
 * never become an open redirect or a credential-bearing URL.
 */
final class WijayaPayProviderUrl
{
    public static function isAllowed(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('port', $parts)
            || array_key_exists('fragment', $parts)
        ) {
            return false;
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        $configured = config('services.wijayapay.allowed_hosts', []);
        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        $allowedHosts = array_values(array_filter(array_map(
            static fn (mixed $candidate): ?string => is_string($candidate)
                ? strtolower(rtrim(trim($candidate), '.'))
                : null,
            (array) $configured,
        ), static fn (mixed $candidate): bool => is_string($candidate) && $candidate !== ''));

        return in_array($host, $allowedHosts, true);
    }

    public static function assertAllowed(string $url): void
    {
        if (! self::isAllowed($url)) {
            throw new InvalidArgumentException('WijayaPay presentation URL is not allowlisted.');
        }
    }
}
