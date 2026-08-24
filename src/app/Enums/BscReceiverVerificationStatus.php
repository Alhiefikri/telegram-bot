<?php

namespace App\Enums;

enum BscReceiverVerificationStatus: string
{
    case PendingVerification = 'pending_verification';
    case Verified = 'verified';
    case ManualVerified = 'manual_verified';
    case Rejected = 'rejected';

    public function isVerified(): bool
    {
        return in_array($this, [self::Verified, self::ManualVerified], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingVerification => 'Menunggu verifikasi wallet',
            self::Verified => 'Terverifikasi cryptographic',
            self::ManualVerified => 'Manual verified · high risk',
            self::Rejected => 'Ditolak',
        };
    }
}
