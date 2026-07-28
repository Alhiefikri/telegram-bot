<?php

namespace App\Services\KlikQris;

use RuntimeException;
use Throwable;

class KlikQrisCreatePreRequestException extends RuntimeException
{
    public function __construct(string $message = 'KlikQRIS create request was not sent.', ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
