<?php

namespace App\Services\Credentials;

use LogicException;

/**
 * Ordered credentials belonging to one OrderItem/Product.
 */
final readonly class CredentialDeliveryProductGroup
{
    /**
     * @param  list<CredentialDeliveryAccount>  $accounts
     * @param  ?array{title: string, url: string}  $usageGuide
     */
    public function __construct(
        private string $productName,
        private array $accounts,
        private ?string $defaultTutorial,
        private ?array $usageGuide,
    ) {}

    public function productName(): string
    {
        return $this->productName;
    }

    /** @return list<CredentialDeliveryAccount> */
    public function accounts(): array
    {
        return $this->accounts;
    }

    public function accountCount(): int
    {
        return count($this->accounts);
    }

    public function defaultTutorial(): ?string
    {
        return $this->defaultTutorial;
    }

    public function tutorial(): ?string
    {
        return $this->defaultTutorial();
    }

    /** @return ?array{title: string, url: string} */
    public function usageGuide(): ?array
    {
        return $this->usageGuide;
    }

    public function __serialize(): array
    {
        throw new LogicException('Credential delivery values cannot be serialized.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Credential delivery values cannot be unserialized.');
    }

    /** @return array{product_name: string, account_count: int, has_tutorial: bool, has_usage_guide: bool} */
    public function __debugInfo(): array
    {
        return [
            'product_name' => $this->productName,
            'account_count' => $this->accountCount(),
            'has_tutorial' => $this->defaultTutorial !== null,
            'has_usage_guide' => $this->usageGuide !== null,
        ];
    }
}
