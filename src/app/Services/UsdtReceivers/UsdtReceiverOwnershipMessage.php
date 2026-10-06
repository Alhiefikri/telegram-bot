<?php

namespace App\Services\UsdtReceivers;

use Illuminate\Support\Carbon;

/**
 * Domain-separated ownership challenge message for binding an exclusive
 * Customer USDT deposit receiver. The purpose string keeps this proof from
 * being replayed as a checkout receiver registration proof.
 */
final class UsdtReceiverOwnershipMessage
{
    public const PURPOSE = 'bind_usdt_deposit_receiver';

    public static function build(
        string $address,
        int $chainId,
        string $challengeId,
        string $nonce,
        Carbon $expiresAt,
    ): string {
        return implode("\n", [
            'LHIE STORE USDT Deposit Receiver Ownership Verification',
            'Address: '.strtolower($address),
            'Chain ID: '.$chainId,
            'Purpose: '.self::PURPOSE,
            'Challenge ID: '.$challengeId,
            'Nonce: '.$nonce,
            'Expires At: '.$expiresAt->copy()->utc()->toIso8601String(),
        ]);
    }
}
