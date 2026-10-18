<?php

namespace App\Services\CustomerApi;

final readonly class CustomerApiAvailabilityChange
{
    public function __construct(
        public CustomerApiAvailabilityState $oldState,
        public CustomerApiAvailabilityState $newState,
        public bool $changed,
    ) {}
}
