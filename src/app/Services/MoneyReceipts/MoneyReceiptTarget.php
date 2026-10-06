<?php

namespace App\Services\MoneyReceipts;

use App\Enums\ExternalMoneyReceiptTargetType;
use InvalidArgumentException;

/**
 * The one economic target that consumes a verified external receipt.
 */
final readonly class MoneyReceiptTarget
{
    public function __construct(
        public ExternalMoneyReceiptTargetType $type,
        public string $targetId,
    ) {
        if ($targetId === '' || strlen($targetId) > 64 || preg_match('/[^\x21-\x7E]|\|/', $targetId) === 1) {
            throw new InvalidArgumentException('Money receipt target id must be a non-empty ASCII value of at most 64 characters.');
        }
    }

    public static function orderPayment(string $orderId): self
    {
        return new self(ExternalMoneyReceiptTargetType::OrderPayment, $orderId);
    }

    public static function storeBalanceTopUp(string $topUpId): self
    {
        return new self(ExternalMoneyReceiptTargetType::StoreBalanceTopUp, $topUpId);
    }

    public static function balanceAdjustment(string $requestId): self
    {
        return new self(ExternalMoneyReceiptTargetType::BalanceAdjustment, $requestId);
    }

    public static function customerUsdtTopUp(string $topUpId): self
    {
        return new self(ExternalMoneyReceiptTargetType::CustomerUsdtTopUp, $topUpId);
    }
}
