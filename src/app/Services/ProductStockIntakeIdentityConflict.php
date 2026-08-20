<?php

namespace App\Services;

use RuntimeException;

final class ProductStockIntakeIdentityConflict extends RuntimeException
{
    public function __construct(
        public readonly int $itemIndex,
        public readonly string $identityKind,
    ) {
        parent::__construct('Stock intake credential identity is already registered.');
    }
}
