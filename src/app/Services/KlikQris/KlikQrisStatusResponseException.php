<?php

namespace App\Services\KlikQris;

use RuntimeException;
use Throwable;

final class KlikQrisStatusResponseException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
