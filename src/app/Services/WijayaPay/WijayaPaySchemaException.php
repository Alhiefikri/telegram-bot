<?php

namespace App\Services\WijayaPay;

use RuntimeException;
use Throwable;

final class WijayaPaySchemaException extends RuntimeException
{
    public function __construct(
        public readonly string $operation = 'payload',
        public readonly bool $outcomeUnknown = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct('WijayaPay '.$operation.' payload is invalid.', previous: $previous);
    }
}
