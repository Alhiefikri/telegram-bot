<?php

namespace App\Services\KlikQris;

use RuntimeException;
use Throwable;

class KlikQrisCreateOutcomeUnknownException extends RuntimeException
{
    public function __construct(string $message = 'KlikQRIS create outcome is unknown.', ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
