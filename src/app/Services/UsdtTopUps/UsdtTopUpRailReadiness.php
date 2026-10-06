<?php

namespace App\Services\UsdtTopUps;

final readonly class UsdtTopUpRailReadiness
{
    public function __construct(
        public bool $technicalReady,
        public bool $fundingAuthorized,
        public string $reason,
    ) {}
}
