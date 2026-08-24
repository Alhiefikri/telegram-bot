<?php

namespace App\Services\Bsc;

use InvalidArgumentException;

final class BscTransactionHash
{
    public static function normalize(string $candidate): ?string
    {
        $normalized = strtolower(trim($candidate));

        return preg_match('/^0x[0-9a-f]{64}$/', $normalized) === 1 ? $normalized : null;
    }

    public static function require(string $candidate): string
    {
        return self::normalize($candidate)
            ?? throw new InvalidArgumentException('BSC transaction hash must be 0x followed by 64 hexadecimal characters.');
    }
}
