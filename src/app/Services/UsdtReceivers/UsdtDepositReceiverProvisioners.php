<?php

namespace App\Services\UsdtReceivers;

use LogicException;

/**
 * The set of approved custody provisioning adapters. It is intentionally
 * empty in this release: H1 custody/capacity qualification (#539) is
 * unresolved, so no adapter may provision exclusive Customer receivers.
 * Registering a provider is an explicit production decision.
 */
final class UsdtDepositReceiverProvisioners
{
    public const KEY_PATTERN = '/\A[a-z0-9][a-z0-9_\-]{0,63}\z/D';

    /** @var list<UsdtDepositReceiverProvisioner> */
    private array $provisioners;

    /**
     * @param  iterable<UsdtDepositReceiverProvisioner>  $provisioners
     */
    public function __construct(iterable $provisioners = [])
    {
        $this->provisioners = [];
        $keys = [];

        foreach ($provisioners as $provisioner) {
            $key = $provisioner->key();
            if (preg_match(self::KEY_PATTERN, $key) !== 1) {
                throw new LogicException('A USDT deposit receiver provisioner key must be a lowercase sanitized token of at most 64 characters.');
            }

            if (isset($keys[$key])) {
                throw new LogicException("Duplicate USDT deposit receiver provisioner key [{$key}].");
            }

            $keys[$key] = true;
            $this->provisioners[] = $provisioner;
        }
    }

    /**
     * The first ready provisioner, or null when no approved adapter can
     * supply an exclusive address right now.
     */
    public function available(): ?UsdtDepositReceiverProvisioner
    {
        foreach ($this->provisioners as $provisioner) {
            if ($provisioner->available()) {
                return $provisioner;
            }
        }

        return null;
    }

    /** @return list<UsdtDepositReceiverProvisioner> */
    public function all(): array
    {
        return $this->provisioners;
    }

    public function isEmpty(): bool
    {
        return $this->provisioners === [];
    }
}
