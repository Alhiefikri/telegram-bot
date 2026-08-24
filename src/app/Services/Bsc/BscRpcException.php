<?php

namespace App\Services\Bsc;

use RuntimeException;

class BscRpcException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = true,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
