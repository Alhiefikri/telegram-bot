<?php

namespace App\Services\UsdtTreasury;

/**
 * Approved adapter contract for one qualified USDT custody/provider asset
 * source. An implementation is registered only after the H1 custody,
 * capacity, provider-permission and treasury qualification gates pass; a
 * fixture adapter is test evidence, never proof of actual backing. The
 * contract is read-only: it observes custody assets and never signs, moves,
 * deposits or credits anything.
 */
interface UsdtTreasuryAssetProvider
{
    /** Stable sanitized adapter key, for example `bsc_primary_custody`. */
    public function providerKey(): string;

    public function observe(): UsdtTreasuryAssetObservation;
}
