<?php

namespace Tests\Support;

use App\Models\Customer;
use App\Services\UsdtReceivers\UsdtDepositReceiverProvisioner;

/**
 * Deterministic custody provisioning double for tests. It only ever answers
 * with public addresses: fake provisioning is not custody evidence, and it
 * never generates or stores key material.
 */
final class FakeUsdtDepositReceiverProvisioner implements UsdtDepositReceiverProvisioner
{
    public int $provideCalls = 0;

    public int $availabilityChecks = 0;

    public function __construct(
        private bool $available = true,
        private ?string $address = '0x1111111111111111111111111111111111111111',
        private bool $freshAddressPerCall = false,
        private string $key = 'fake_custody',
    ) {}

    /** A ready adapter that always answers with one fixed address. */
    public static function fixed(string $address, string $key = 'fake_custody'): self
    {
        return new self(available: true, address: $address, key: $key);
    }

    /** A ready adapter that answers with a new address on every call. */
    public static function random(string $key = 'fake_custody'): self
    {
        return new self(available: true, address: null, freshAddressPerCall: true, key: $key);
    }

    /** A ready adapter whose address capacity is exhausted. */
    public static function exhausted(string $key = 'fake_custody'): self
    {
        return new self(available: true, address: null, freshAddressPerCall: false, key: $key);
    }

    /** An adapter that cannot prove readiness. */
    public static function unavailable(string $key = 'fake_custody'): self
    {
        return new self(available: false, address: null, freshAddressPerCall: false, key: $key);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function setAvailable(bool $available): void
    {
        $this->available = $available;
    }

    public function available(): bool
    {
        $this->availabilityChecks++;

        return $this->available;
    }

    public function provide(Customer $customer): ?string
    {
        $this->provideCalls++;

        if (! $this->available) {
            return null;
        }

        if ($this->freshAddressPerCall) {
            return '0x'.bin2hex(random_bytes(20));
        }

        return $this->address;
    }
}
