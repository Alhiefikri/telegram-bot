<?php

namespace App\Exceptions;

use App\Enums\ExternalMoneyReceiptClaimOutcome;
use RuntimeException;

final class ExternalMoneyReceiptClaimRejected extends RuntimeException
{
    public function __construct(
        public readonly ExternalMoneyReceiptClaimOutcome $outcome,
        string $message,
    ) {
        parent::__construct($message);
    }
}
