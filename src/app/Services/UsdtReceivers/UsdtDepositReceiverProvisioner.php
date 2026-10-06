<?php

namespace App\Services\UsdtReceivers;

use App\Models\Customer;

/**
 * Approved external custody adapter that can provide one exclusive public
 * BSC address per Customer. Implementations return public addresses only:
 * they never create, store, log or derive private key, seed or signing
 * material. Until an adapter passes the H1 custody/capacity gates the
 * provisioner set stays empty and the rail fails closed.
 */
interface UsdtDepositReceiverProvisioner
{
    /**
     * Stable custody namespace of this adapter (lowercase sanitized token).
     */
    public function key(): string;

    /**
     * Readiness/capacity gate. A provider that cannot prove it can supply an
     * exclusive address must report false so no invoice can be created.
     */
    public function available(): bool;

    /**
     * Returns one normalized public address exclusively controlled for this
     * Customer, or null when address capacity is exhausted. Provisioning runs
     * outside money transactions and must reuse the same address only for the
     * same Customer/Custody namespace pair.
     */
    public function provide(Customer $customer): ?string;
}
