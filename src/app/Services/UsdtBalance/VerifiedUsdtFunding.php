<?php

namespace App\Services\UsdtBalance;

use App\Enums\UsdtBalanceEntryKind;

/**
 * A finalized, attributable funding receipt claimed for one Customer USDT
 * top-up. The ledger trusts the globally claimed receipt identity and records
 * the source; provider/RPC verification stays with the rail that minted it.
 */
final class VerifiedUsdtFunding extends UsdtBalanceSource
{
    public function __construct(
        int $customerId,
        UsdtMicroAmount $amount,
        public readonly string $topUpId,
        public readonly string $receiptId,
        public readonly string $evidenceVersion,
    ) {
        self::assertIdentifier('top-up id', $topUpId);
        self::assertIdentifier('receipt id', $receiptId);
        self::assertIdentifier('evidence version', $evidenceVersion);
        parent::__construct($customerId, $amount);
    }

    public function kind(): UsdtBalanceEntryKind
    {
        return UsdtBalanceEntryKind::FundingCredit;
    }

    public function sourceType(): string
    {
        return 'customer_usdt_top_up';
    }

    public function sourceId(): string
    {
        return $this->topUpId;
    }

    protected function variant(): string
    {
        return 'verified_funding';
    }

    protected function fingerprintFields(): array
    {
        return [
            'top_up_id' => $this->topUpId,
            'receipt_id' => $this->receiptId,
            'evidence_version' => $this->evidenceVersion,
        ];
    }
}
