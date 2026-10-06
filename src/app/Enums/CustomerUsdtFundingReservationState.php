<?php

namespace App\Enums;

/**
 * Lifecycle of one durable Customer USDT funding reservation (the single
 * active funding target per Customer). `expired_unresolved` is deliberately
 * not a terminal state and keeps the slot held: expiry never proves that no
 * money exists, so the reservation stays reserved until a real settlement or
 * a typed release resolution resolves it.
 */
enum CustomerUsdtFundingReservationState: string
{
    case Reserved = 'reserved';
    case ExpiredUnresolved = 'expired_unresolved';
    case Settled = 'settled';
    case Released = 'released';

    public function isOpen(): bool
    {
        return $this === self::Reserved || $this === self::ExpiredUnresolved;
    }

    public function isResolved(): bool
    {
        return ! $this->isOpen();
    }

    public function label(): string
    {
        return match ($this) {
            self::Reserved => 'Aktif',
            self::ExpiredUnresolved => 'Kedaluwarsa, dana belum jelas',
            self::Settled => 'Sudah dikreditkan',
            self::Released => 'Dilepas',
        };
    }
}
