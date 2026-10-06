<?php

namespace App\Services\UsdtBalance;

use App\Enums\UsdtBalanceEntryKind;

/**
 * A full-payment purchase debit for one Order and its internal USDT payment.
 * The Order lifecycle owns authorization; this source only names the frozen
 * commercial identity that the ledger must not reprice.
 */
final class UsdtPurchase extends UsdtBalanceSource
{
    public function __construct(
        int $customerId,
        UsdtMicroAmount $amount,
        public readonly string $orderId,
        public readonly string $paymentId,
    ) {
        self::assertIdentifier('order id', $orderId);
        self::assertIdentifier('payment id', $paymentId);
        parent::__construct($customerId, $amount);
    }

    public function kind(): UsdtBalanceEntryKind
    {
        return UsdtBalanceEntryKind::PurchaseDebit;
    }

    public function sourceType(): string
    {
        return 'order';
    }

    public function sourceId(): string
    {
        return $this->orderId;
    }

    protected function variant(): string
    {
        return 'purchase';
    }

    protected function fingerprintFields(): array
    {
        return [
            'order_id' => $this->orderId,
            'payment_id' => $this->paymentId,
        ];
    }
}
