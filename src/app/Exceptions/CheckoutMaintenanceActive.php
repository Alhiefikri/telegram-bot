<?php

namespace App\Exceptions;

use App\Services\CheckoutMaintenance\CheckoutMaintenanceState;
use RuntimeException;

class CheckoutMaintenanceActive extends RuntimeException
{
    public function __construct(public readonly CheckoutMaintenanceState $state)
    {
        parent::__construct('Checkout maintenance is active.');
    }
}
