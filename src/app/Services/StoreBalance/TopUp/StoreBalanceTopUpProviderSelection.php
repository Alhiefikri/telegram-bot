<?php

namespace App\Services\StoreBalance\TopUp;

final readonly class StoreBalanceTopUpProviderSelection
{
    public function __construct(
        private string $provider,
        private ?int $settingId,
        private int $providerVersion,
    ) {}

    public function provider(): string
    {
        return $this->provider;
    }

    public function settingId(): ?int
    {
        return $this->settingId;
    }

    public function providerVersion(): int
    {
        return $this->providerVersion;
    }
}
