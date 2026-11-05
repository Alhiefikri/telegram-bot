<?php

namespace App\Services\UsdtBalance;

use App\Enums\UsdtBalanceEntryKind;
use App\Services\StoreBalance\ApprovedBalanceAdjustment;

final class OwnerUsdtAdjustment extends UsdtBalanceSource
{
    public function __construct(
        int $customerId,
        UsdtMicroAmount $amount,
        public readonly string $requestId,
        public readonly string $requestFingerprint,
        public readonly ApprovedBalanceAdjustment $authority,
    ) {
        self::assertIdentifier('adjustment request', $requestId);
        self::assertIdentifier('adjustment fingerprint', $requestFingerprint);
        parent::__construct($customerId, $amount);
    }

    public function kind(): UsdtBalanceEntryKind
    {
        return UsdtBalanceEntryKind::OwnerAdjustmentCredit;
    }

    public function sourceType(): string
    {
        return 'balance_adjustment';
    }

    public function sourceId(): string
    {
        return $this->requestId;
    }

    protected function variant(): string
    {
        return 'owner_adjustment';
    }

    protected function fingerprintFields(): array
    {
        return ['request_id' => $this->requestId, 'request_fingerprint' => $this->requestFingerprint];
    }
}
