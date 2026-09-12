<?php

namespace App\Services\StoreBalance\TopUp;

use App\Support\PaymentProvider;

final class StoreBalanceTopUpLifecycleResolver
{
    /**
     * @param  iterable<StoreBalanceTopUpLifecycle>  $rails
     */
    public function __construct(private readonly iterable $rails) {}

    public function resolve(string $provider): ?StoreBalanceTopUpLifecycle
    {
        $provider = strtolower(trim($provider));

        foreach ($this->rails as $rail) {
            if ($rail->provider() === $provider) {
                return $rail;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function supportedProviders(): array
    {
        $providers = [];
        foreach ($this->rails as $rail) {
            $providers[] = $rail->provider();
        }

        sort($providers);

        return array_values(array_intersect($providers, PaymentProvider::allowed()));
    }
}
