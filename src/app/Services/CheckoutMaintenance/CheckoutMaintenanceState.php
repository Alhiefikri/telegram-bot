<?php

namespace App\Services\CheckoutMaintenance;

use Illuminate\Support\Carbon;

final readonly class CheckoutMaintenanceState
{
    public function __construct(
        public bool $active,
        public ?string $publicNote,
        public int $version,
        public ?Carbon $activatedAt,
    ) {}
}
