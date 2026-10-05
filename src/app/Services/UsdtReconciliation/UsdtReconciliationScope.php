<?php

namespace App\Services\UsdtReconciliation;

use InvalidArgumentException;

/**
 * Bounded scope of one USDT reconciliation run: one optional Customer, one
 * page size and independent keyset cursors per facet. Nothing is scanned
 * unbounded and no cursor is a capability: it is a plain internal id that
 * only narrows the read.
 */
final readonly class UsdtReconciliationScope
{
    public const MAX_LIMIT = 1000;

    public function __construct(
        public int $limit,
        public ?int $customerId = null,
        public ?int $afterAccountId = null,
        public ?int $afterReceiptId = null,
        public ?int $afterCreditId = null,
    ) {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('USDT reconciliation limit must be between 1 and '.self::MAX_LIMIT.'.');
        }

        if ($customerId !== null && $customerId <= 0) {
            throw new InvalidArgumentException('USDT reconciliation customer id must be a positive integer.');
        }

        foreach (['account cursor' => $afterAccountId, 'receipt cursor' => $afterReceiptId, 'credit cursor' => $afterCreditId] as $label => $cursor) {
            if ($cursor !== null && $cursor < 0) {
                throw new InvalidArgumentException("USDT reconciliation {$label} must not be negative.");
            }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'mode' => $this->customerId === null ? 'full' : 'customer',
            'customer_id' => $this->customerId,
            'limit' => $this->limit,
            'after_account_id' => $this->afterAccountId,
            'after_receipt_id' => $this->afterReceiptId,
            'after_credit_id' => $this->afterCreditId,
        ];
    }
}
