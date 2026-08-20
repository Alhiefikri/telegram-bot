<?php

namespace App\Services;

use RuntimeException;

final class ProductStockIntakeIdempotencyConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Stock intake operation key was already used for a different submission.');
    }
}
