<?php

namespace App\Services\Bsc;

final class BscExplorerUrl
{
    public function forTransaction(?string $candidate): ?string
    {
        $hash = $candidate === null ? null : BscTransactionHash::normalize($candidate);
        if ($hash === null) {
            return null;
        }

        $baseUrl = rtrim(trim((string) config('services.bsc.explorer_base_url')), '/');
        $parts = parse_url($baseUrl);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $allowedHosts = config('services.bsc.explorer_allowed_hosts', []);

        $allowedHosts = is_array($allowedHosts) ? array_values(array_filter(
            $allowedHosts,
            static fn (mixed $allowedHost): bool => is_string($allowedHost) && trim($allowedHost) !== '',
        )) : [];

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || $host === ''
            || ! in_array($host, array_map(
                static fn (string $allowedHost): string => strtolower(trim($allowedHost)),
                $allowedHosts,
            ), true)
            || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])) {
            return null;
        }

        return $baseUrl.'/tx/'.$hash;
    }
}
