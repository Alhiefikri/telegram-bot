<?php

namespace App\Exceptions;

use RuntimeException;

final class UsdtBalanceProjectionMismatchException extends RuntimeException
{
    public function __construct(
        public readonly int $customerId,
        public readonly int $projectedMicro,
        public readonly int $ledgerMicro,
    ) {
        parent::__construct('Customer USDT Balance projection mismatch requires operator review.');
    }
}
