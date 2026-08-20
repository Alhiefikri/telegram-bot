<?php

namespace App\Exceptions;

use RuntimeException;

final class StructuredStockInputDisabled extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Structured stock input is disabled until rollout checks are complete.');
    }
}
