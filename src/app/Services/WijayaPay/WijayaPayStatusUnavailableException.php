<?php

namespace App\Services\WijayaPay;

use RuntimeException;
use Throwable;

final class WijayaPayStatusUnavailableException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('WijayaPay status is not authoritatively available.', previous: $previous);
    }
}
