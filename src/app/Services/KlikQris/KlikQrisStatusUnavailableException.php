<?php

namespace App\Services\KlikQris;

use RuntimeException;
use Throwable;

class KlikQrisStatusUnavailableException extends RuntimeException
{
    public function __construct(string $message = 'KlikQRIS status is temporarily unavailable.', ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
