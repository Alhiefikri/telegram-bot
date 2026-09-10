<?php

namespace App\Services\StoreBalance;

use App\Enums\StoreBalanceEntryKind;
use App\Models\StoreBalanceEntry;

final class StoreBalanceMutationResult
{
    private function __construct(
        public readonly bool $applied,
        public readonly int $entryId,
        public readonly StoreBalanceEntryKind $kind,
        public readonly int $amount,
        public readonly int $balanceAfter,
    ) {}

    public static function applied(StoreBalanceEntry $entry): self
    {
        return self::fromEntry(true, $entry);
    }

    /**
     * Reapplying the same immutable source identity or idempotency claim
     * returns the existing economic result without moving money again.
     */
    public static function replayed(StoreBalanceEntry $entry): self
    {
        return self::fromEntry(false, $entry);
    }

    private static function fromEntry(bool $applied, StoreBalanceEntry $entry): self
    {
        return new self(
            $applied,
            (int) $entry->id,
            $entry->kind,
            (int) $entry->amount,
            (int) $entry->balance_after,
        );
    }
}
