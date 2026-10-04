<?php

namespace App\Services\Bsc;

enum BscCapabilityStatus: string
{
    case Verified = 'verified';
    case Unsupported = 'unsupported';
    case Unknown = 'unknown';

    public function isVerified(): bool
    {
        return $this === self::Verified;
    }
}
