<?php

namespace App\Services\UsdtBalance;

use App\Enums\UsdtBalanceEntryKind;
use App\Models\CustomerUsdtBalanceEntry;

final class UsdtBalanceMutationResult
{
    private function __construct(
        public readonly bool $applied,
        public readonly int $entryId,
        public readonly UsdtBalanceEntryKind $kind,
        public readonly int $amountMicro,
        public readonly int $balanceAfterMicro,
    ) {}

    public static function applied(CustomerUsdtBalanceEntry $entry): self
    {
        return self::fromEntry(true, $entry);
    }

    /**
     * Reapplying the same immutable source identity, idempotency claim or
     * request fingerprint returns the existing economic result without
     * moving money again.
     */
    public static function replayed(CustomerUsdtBalanceEntry $entry): self
    {
        return self::fromEntry(false, $entry);
    }

    private static function fromEntry(bool $applied, CustomerUsdtBalanceEntry $entry): self
    {
        return new self(
            $applied,
            (int) $entry->id,
            $entry->kind,
            (int) $entry->amount_micro,
            (int) $entry->balance_after_micro,
        );
    }
}
