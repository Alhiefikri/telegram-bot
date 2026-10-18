<?php

namespace App\Services\CustomerApi;

use Carbon\CarbonImmutable;

final readonly class CustomerApiAvailabilityState
{
    public function __construct(
        public bool $enabled,
        public int $version,
        public ?CarbonImmutable $changedAt,
    ) {}
}
