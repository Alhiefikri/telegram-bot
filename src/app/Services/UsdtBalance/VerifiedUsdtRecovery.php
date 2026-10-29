<?php

namespace App\Services\UsdtBalance;

use App\Enums\UsdtBalanceEntryKind;
use App\Services\StoreBalance\ApprovedBalanceAdjustment;

final class VerifiedUsdtRecovery extends UsdtBalanceSource
{
    public function __construct(
        int $customerId,
        UsdtMicroAmount $amount,
        public readonly string $requestId,
        public readonly int $receiptId,
        public readonly ApprovedBalanceAdjustment $authority,
    ) {
        self::assertIdentifier('recovery request', $requestId);
        parent::__construct($customerId, $amount);
    }

    public function kind(): UsdtBalanceEntryKind
    {
        return UsdtBalanceEntryKind::FundingCredit;
    }

    public function sourceType(): string
    {
        return 'customer_usdt_recovery';
    }

    public function sourceId(): string
    {
        return $this->requestId;
    }

    protected function variant(): string
    {
        return 'verified_recovery';
    }

    protected function fingerprintFields(): array
    {
        return ['request_id' => $this->requestId, 'receipt_id' => $this->receiptId];
    }
}
