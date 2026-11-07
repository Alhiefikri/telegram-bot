<?php

namespace App\Services\WijayaPay;

use RuntimeException;
use Throwable;

final class WijayaPayTransportException extends RuntimeException
{
    public function __construct(
        public readonly string $operation,
        public readonly bool $outcomeUnknown = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct('WijayaPay '.$operation.' transport failed.', previous: $previous);
    }
}
