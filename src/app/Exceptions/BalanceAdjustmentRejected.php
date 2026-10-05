<?php

namespace App\Exceptions;

use RuntimeException;

final class BalanceAdjustmentRejected extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Balance adjustment rejected: '.$reason);
    }
}
