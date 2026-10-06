<?php

namespace App\Exceptions;

use RuntimeException;

final class UsdtBalanceFrozenException extends RuntimeException
{
    public function __construct(
        public readonly int $customerId,
        public readonly string $reason,
    ) {
        parent::__construct("Customer USDT Balance account is frozen: {$reason}");
    }
}
