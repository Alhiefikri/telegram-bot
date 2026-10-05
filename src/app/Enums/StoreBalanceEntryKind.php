<?php

namespace App\Enums;

enum StoreBalanceEntryKind: string
{
    case AdminAdjustmentCredit = 'admin_adjustment_credit';
    case TopUpCredit = 'top_up_credit';
    case PurchaseDebit = 'purchase_debit';
    case RefundCredit = 'refund_credit';
    case OwnerCompensationCredit = 'owner_compensation_credit';

    /**
     * Every ledger entry stores a positive amount; the kind owns the
     * direction of the economic effect on the available balance.
     */
    public function direction(): int
    {
        return match ($this) {
            self::AdminAdjustmentCredit, self::TopUpCredit, self::RefundCredit, self::OwnerCompensationCredit => 1,
            self::PurchaseDebit => -1,
        };
    }

    public function isCredit(): bool
    {
        return $this->direction() === 1;
    }
}
