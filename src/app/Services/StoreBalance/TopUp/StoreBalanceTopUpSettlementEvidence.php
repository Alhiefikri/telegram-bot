<?php

namespace App\Services\StoreBalance\TopUp;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Provider-verified settlement evidence for one Store Balance top-up.
 *
 * A rail may only build trusted evidence after its own authoritative
 * verification (provider signature equality, or an authoritative status
 * lookup with identity, amount, total, and fee matching). The money path
 * consumes this DTO instead of provider payloads so settlement stays
 * provider-agnostic.
 */
final readonly class StoreBalanceTopUpSettlementEvidence
{
    private const SUCCESS_STATUSES = ['SUCCESS', 'SUKSES', 'BERHASIL', 'PAID', 'CONFIRMED'];

    private const EXPIRED_STATUSES = ['EXPIRED', 'EXPIRE', 'KADALUARSA'];

    private string $provider;

    private string $providerStatus;

    private string $rawStatus;

    public function __construct(
        string $provider,
        private ?string $merchantReference,
        private ?string $providerReference,
        string $providerStatus,
        private int $amount,
        private int $totalAmount,
        private bool $trusted,
        private ?int $providerFee = null,
        private ?CarbonImmutable $paidAt = null,
        private ?CarbonImmutable $expiresAt = null,
    ) {
        $this->provider = strtolower(trim($provider));
        $this->rawStatus = trim($providerStatus);
        $this->providerStatus = strtoupper($this->rawStatus);

        if ($this->provider === '') {
            throw new InvalidArgumentException('Store Balance top-up evidence provider is empty.');
        }

        if ($amount < 1 || $totalAmount < 1) {
            throw new InvalidArgumentException('Store Balance top-up evidence amounts are invalid.');
        }
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function merchantReference(): ?string
    {
        return $this->merchantReference;
    }

    public function providerReference(): ?string
    {
        return $this->providerReference;
    }

    public function providerStatus(): string
    {
        return $this->providerStatus;
    }

    public function rawStatus(): string
    {
        return $this->rawStatus;
    }

    public function amount(): int
    {
        return $this->amount;
    }

    public function totalAmount(): int
    {
        return $this->totalAmount;
    }

    public function providerFee(): ?int
    {
        return $this->providerFee;
    }

    public function paidAt(): ?CarbonImmutable
    {
        return $this->paidAt;
    }

    public function expiresAt(): ?CarbonImmutable
    {
        return $this->expiresAt;
    }

    public function isTrusted(): bool
    {
        return $this->trusted;
    }

    public function isSuccess(): bool
    {
        return in_array($this->providerStatus, self::SUCCESS_STATUSES, true);
    }

    public function isExpired(): bool
    {
        return in_array($this->providerStatus, self::EXPIRED_STATUSES, true);
    }
}
