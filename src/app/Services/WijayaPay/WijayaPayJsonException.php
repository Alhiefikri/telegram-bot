<?php

namespace App\Services\WijayaPay;

use RuntimeException;
use Throwable;

final class WijayaPayJsonException extends RuntimeException
{
    public function __construct(
        public readonly string $operation,
        public readonly bool $outcomeUnknown = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct('WijayaPay '.$operation.' response is not valid JSON.', previous: $previous);
    }
}
