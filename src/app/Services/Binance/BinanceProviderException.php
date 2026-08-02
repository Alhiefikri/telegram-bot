<?php

namespace App\Services\Binance;

use RuntimeException;

final class BinanceProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly bool $retryable = true,
    ) {
        parent::__construct($message);
    }
}
