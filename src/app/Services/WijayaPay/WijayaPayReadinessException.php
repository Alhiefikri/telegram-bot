<?php

namespace App\Services\WijayaPay;

use RuntimeException;
use Throwable;

final class WijayaPayReadinessException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
