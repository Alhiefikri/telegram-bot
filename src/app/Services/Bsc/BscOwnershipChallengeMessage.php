<?php

namespace App\Services\Bsc;

use Illuminate\Support\Carbon;

final class BscOwnershipChallengeMessage
{
    public const PURPOSE = 'add_bsc_receiving_address';

    public static function build(
        string $address,
        int $chainId,
        string $challengeId,
        string $nonce,
        Carbon $expiresAt,
    ): string {
        return implode("\n", [
            'LHIE STORE BSC Receiving Address Ownership Verification',
            'Address: '.strtolower($address),
            'Chain ID: '.$chainId,
            'Purpose: '.self::PURPOSE,
            'Challenge ID: '.$challengeId,
            'Nonce: '.$nonce,
            'Expires At: '.$expiresAt->copy()->utc()->toIso8601String(),
        ]);
    }
}
