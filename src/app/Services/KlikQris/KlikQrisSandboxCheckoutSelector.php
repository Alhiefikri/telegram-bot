<?php

namespace App\Services\KlikQris;

final readonly class KlikQrisSandboxCheckoutSelector
{
    public function __construct(private KlikQrisProviderReadiness $readiness) {}

    public function enabled(): bool
    {
        return $this->readiness->sandbox()->isReady();
    }
}
