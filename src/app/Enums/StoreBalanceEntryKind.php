<?php

namespace App\Enums;

enum StoreBalanceEntryKind: string
{
    case AdminAdjustmentCredit = 'admin_adjustment_credit';
    case AdminAdjustmentReversal = 'admin_adjustment_reversal';
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
            self::AdminAdjustmentReversal, self::PurchaseDebit => -1,
        };
    }

    public function isCredit(): bool
    {
        return $this->direction() === 1;
    }

    /**
     * Ledger kind of the credit posted by one adjustment request category.
     * A null result means the category is not a reversible manual credit.
     */
    public static function forAdjustmentCategory(string $category): ?self
    {
        return match ($category) {
            'owner_attested', 'funded_transfer', 'promotional_grant', 'correction' => self::AdminAdjustmentCredit,
            'top_up_recovery' => self::OwnerCompensationCredit,
            'refund_recovery' => self::RefundCredit,
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function debitValues(): array
    {
        return array_values(array_map(
            static fn (self $kind): string => $kind->value,
            array_filter(self::cases(), static fn (self $kind): bool => $kind->direction() < 0),
        ));
    }
}
