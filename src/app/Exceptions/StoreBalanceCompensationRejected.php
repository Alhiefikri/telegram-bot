<?php

namespace App\Exceptions;

use RuntimeException;

final class StoreBalanceCompensationRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
