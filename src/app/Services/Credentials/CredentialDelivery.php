<?php

namespace App\Services\Credentials;

use LogicException;

/**
 * In-memory projection for one paid Order. It is deliberately not a queue,
 * cache, or persistence DTO because its groups contain credential plaintext.
 */
final readonly class CredentialDelivery
{
    /**
     * @param  list<CredentialDeliveryProductGroup>  $groups
     * @param  list<array{title: string, url: string}>  $usageGuides
     */
    public function __construct(
        private array $groups = [],
        private array $usageGuides = [],
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    /** @return list<CredentialDeliveryProductGroup> */
    public function groups(): array
    {
        return $this->groups;
    }

    /** @return list<array{title: string, url: string}> */
    public function usageGuides(): array
    {
        return $this->usageGuides;
    }

    public function isEmpty(): bool
    {
        return $this->groups === [];
    }

    public function accountCount(): int
    {
        return array_sum(array_map(
            static fn (CredentialDeliveryProductGroup $group): int => $group->accountCount(),
            $this->groups,
        ));
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

    /** @return array{product_group_count: int, account_count: int, usage_guide_count: int} */
    public function __debugInfo(): array
    {
        return [
            'product_group_count' => count($this->groups),
            'account_count' => $this->accountCount(),
            'usage_guide_count' => count($this->usageGuides),
        ];
    }
}
