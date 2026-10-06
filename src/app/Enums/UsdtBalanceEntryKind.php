<?php

namespace App\Enums;

enum UsdtBalanceEntryKind: string
{
    case FundingCredit = 'funding_credit';
    case PurchaseDebit = 'purchase_debit';
    case RefundCredit = 'refund_credit';
    case ReversalDebit = 'reversal_debit';

    public function direction(): int
    {
        return match ($this) {
            self::FundingCredit, self::RefundCredit => 1,
            self::PurchaseDebit, self::ReversalDebit => -1,
        };
    }

    public function isCredit(): bool
    {
        return $this->direction() === 1;
    }

    public static function debitValues(): array
    {
        return array_values(array_map(
            static fn (self $kind): string => $kind->value,
            array_filter(self::cases(), static fn (self $kind): bool => $kind->direction() < 0),
        ));
    }
}
