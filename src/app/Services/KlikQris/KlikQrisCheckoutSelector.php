<?php

namespace App\Services\KlikQris;

use App\Enums\KlikQrisEnvironment;

final readonly class KlikQrisCheckoutSelector
{
    public function __construct(private KlikQrisProviderReadiness $readiness) {}

    public function enabled(?KlikQrisEnvironment $environment = null): bool
    {
        return $this->readiness
            ->forEnvironment($environment ?? KlikQrisEnvironment::runtime())
            ->isReady();
    }

    public function assertReady(string $message, ?KlikQrisEnvironment $environment = null): void
    {
        $this->readiness
            ->forEnvironment($environment ?? KlikQrisEnvironment::runtime())
            ->assertReady($message);
    }

    public function active(): bool
    {
        return $this->enabled(KlikQrisEnvironment::runtime());
    }
}
