<?php

namespace App\Services\MoneyReceipts;

use InvalidArgumentException;

/**
 * An alternate verified identity for the same physical receipt, such as the
 * same transfer observed through a custody deposit adapter and a chain reader.
 * Aliases are unique across the registry and never re-point to another receipt.
 */
final readonly class MoneyReceiptAlias
{
    public function __construct(
        public string $type,
        public MoneyReceiptIdentity $identity,
    ) {
        if (preg_match('/^[a-z0-9_]{2,32}$/', $type) !== 1) {
            throw new InvalidArgumentException('Money receipt alias type must be a lowercase namespace of 2 to 32 characters.');
        }
    }
}
