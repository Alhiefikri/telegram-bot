<?php

namespace App\Services\Bsc;

/**
 * Normalizes Customer-supplied BSC proof into one transaction hash. A full
 * BscScan link is accepted as a convenience; it is still only a lookup hint.
 * The transaction is never trusted until the authoritative receipt, transfer
 * log, block window and finality checks pass.
 */
final class BscTransactionInput
{
    private const HASH_PATTERN = '/\A0x[a-fA-F0-9]{64}\z/D';

    private const HOSTS = ['bscscan.com', 'www.bscscan.com'];

    public static function normalize(string $input): ?string
    {
        $input = trim($input);
        if ($input === '' || strlen($input) > 512) {
            return null;
        }

        if (preg_match(self::HASH_PATTERN, $input) === 1) {
            return strtolower($input);
        }

        $candidate = str_contains($input, '://') ? $input : 'https://'.$input;
        $parts = parse_url($candidate);
        if (! is_array($parts)
            || ! in_array(strtolower((string) ($parts['host'] ?? '')), self::HOSTS, true)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');
        if (preg_match('#\A/tx/(0x[a-fA-F0-9]{64})\z#D', $path, $matches) !== 1) {
            return null;
        }

        return strtolower($matches[1]);
    }
}
