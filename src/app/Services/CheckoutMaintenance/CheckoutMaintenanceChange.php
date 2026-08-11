<?php

namespace App\Services\CheckoutMaintenance;

final readonly class CheckoutMaintenanceChange
{
    public function __construct(
        public CheckoutMaintenanceState $oldState,
        public CheckoutMaintenanceState $newState,
        public bool $changed,
    ) {}

}
