<?php

namespace App\Services\UsdtBalance;

use App\Enums\UsdtBalanceEntryKind;
use InvalidArgumentException;

/**
 * A full linked debit of one credit entry, authorized by an approved reversal
 * reference. The ledger allows exactly one reversal per original entry and
 * never edits the original posting.
 */
final class ApprovedUsdtReversal extends UsdtBalanceSource
{
    public function __construct(
        int $customerId,
        UsdtMicroAmount $amount,
        public readonly int $originalEntryId,
        public readonly string $authorizationReference,
    ) {
        self::assertIdentifier('authorization reference', $authorizationReference);
        if ($originalEntryId <= 0) {
            throw new InvalidArgumentException('USDT reversal requires the original ledger entry id.');
        }
        parent::__construct($customerId, $amount);
    }

    public function kind(): UsdtBalanceEntryKind
    {
        return UsdtBalanceEntryKind::ReversalDebit;
    }

    public function sourceType(): string
    {
        return 'approved_reversal';
    }

    public function sourceId(): string
    {
        return (string) $this->originalEntryId;
    }

    public function originalEntryId(): ?int
    {
        return $this->originalEntryId;
    }

    protected function variant(): string
    {
        return 'approved_reversal';
    }

    protected function fingerprintFields(): array
    {
        return [
            'original_entry_id' => $this->originalEntryId,
            'authorization_reference' => $this->authorizationReference,
        ];
    }
}
