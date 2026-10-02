<?php

namespace App\Services\Suppliers;

use RuntimeException;

final class SupplierUnavailable extends RuntimeException
{
    public function __construct(public readonly string $errorCode = 'unavailable')
    {
        // No provider body, URL, key, or original exception chain is retained.
        parent::__construct('Supplier unavailable: '.$errorCode.'.');
    }
}
