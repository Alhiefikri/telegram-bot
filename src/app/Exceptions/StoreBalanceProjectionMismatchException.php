<?php

namespace App\Exceptions;

use RuntimeException;

final class StoreBalanceProjectionMismatchException extends RuntimeException
{
    public function __construct(
        public readonly int $customerId,
        public readonly int $projectedBalance,
        public readonly int $ledgerBalance,
    ) {
        parent::__construct('Store Balance projection mismatch requires operator review.');
    }
}
