<?php

namespace App\Services\UsdtBalance;

use App\Enums\UsdtBalanceEntryKind;
use InvalidArgumentException;

/**
 * A verified refund entitlement credit linked to the original purchase debit.
 * The ledger caps cumulative refunds against that original amount and never
 * re-prices the order.
 */
final class UsdtRefund extends UsdtBalanceSource
{
    public function __construct(
        int $customerId,
        UsdtMicroAmount $amount,
        public readonly string $refundId,
        public readonly string $orderId,
        public readonly int $originalEntryId,
    ) {
        self::assertIdentifier('refund id', $refundId);
        self::assertIdentifier('order id', $orderId);
        if ($originalEntryId <= 0) {
            throw new InvalidArgumentException('USDT refund requires the original ledger entry id.');
        }
        parent::__construct($customerId, $amount);
    }

    public function kind(): UsdtBalanceEntryKind
    {
        return UsdtBalanceEntryKind::RefundCredit;
    }

    public function sourceType(): string
    {
        return 'order_refund';
    }

    public function sourceId(): string
    {
        return $this->refundId;
    }

    public function originalEntryId(): ?int
    {
        return $this->originalEntryId;
    }

    public function refundOrderId(): ?string
    {
        return $this->orderId;
    }

    protected function variant(): string
    {
        return 'verified_refund';
    }

    protected function fingerprintFields(): array
    {
        return [
            'refund_id' => $this->refundId,
            'order_id' => $this->orderId,
            'original_entry_id' => $this->originalEntryId,
        ];
    }
}
