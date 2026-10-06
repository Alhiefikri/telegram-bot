<?php

namespace App\Services\UsdtTreasury;

use LogicException;
use Throwable;

/**
 * The set of approved treasury asset providers. It is intentionally empty in
 * this release: H1 custody qualification (#539) is unresolved, so no adapter
 * is approved to attest real backing. Registering a provider is an explicit
 * production decision; this class only enforces that every provider reports
 * under its own declared key.
 */
final class UsdtTreasuryAssetRegistry
{
    /** @var list<UsdtTreasuryAssetProvider> */
    private array $providers;

    /**
     * @param  iterable<UsdtTreasuryAssetProvider>  $providers
     */
    public function __construct(iterable $providers = [])
    {
        $this->providers = [];
        $keys = [];

        foreach ($providers as $provider) {
            $key = $provider->providerKey();
            if (isset($keys[$key])) {
                throw new LogicException("Duplicate USDT treasury asset provider key [{$key}].");
            }

            $keys[$key] = true;
            $this->providers[] = $provider;
        }
    }

    /** @return list<UsdtTreasuryAssetObservation> */
    public function observeAll(): array
    {
        $observations = [];

        foreach ($this->providers as $provider) {
            $key = $provider->providerKey();
            if (preg_match(UsdtTreasuryAssetObservation::PROVIDER_PATTERN, $key) !== 1) {
                throw new LogicException('A USDT treasury asset provider key must be a lowercase sanitized token of at most 32 characters.');
            }

            try {
                $observation = $provider->observe();
            } catch (Throwable) {
                // A broken or unreachable adapter is unverifiable custody, not
                // zero backing: the reconciliation must keep running.
                $observations[] = UsdtTreasuryAssetObservation::unavailable($key, 'adapter_error');

                continue;
            }

            if ($observation->provider !== $key) {
                throw new LogicException('A USDT treasury asset provider must report under its own provider key.');
            }

            $observations[] = $observation;
        }

        return $observations;
    }
}
