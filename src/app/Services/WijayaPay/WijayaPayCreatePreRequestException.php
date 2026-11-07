<?php

namespace App\Services\WijayaPay;

use RuntimeException;
use Throwable;

final class WijayaPayCreatePreRequestException extends RuntimeException
{
    public function __construct(
        public readonly string $reason = 'pre_request',
        ?Throwable $previous = null,
    ) {
        parent::__construct('WijayaPay invoice creation did not reach the provider.', previous: $previous);
    }
}
