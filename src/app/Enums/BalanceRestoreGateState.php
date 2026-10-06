<?php

namespace App\Enums;

/**
 * Post-restore gate state. While a gate is open, new Customer USDT funding
 * targets and new USDT spending are refused; reconciliation, settlement,
 * refund and reversal of money that already entered the system continue.
 */
enum BalanceRestoreGateState: string
{
    case Open = 'open';
    case Cleared = 'cleared';

    public function isOpen(): bool
    {
        return $this === self::Open;
    }
}
