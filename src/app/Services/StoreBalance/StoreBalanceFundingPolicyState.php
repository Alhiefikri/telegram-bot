<?php

namespace App\Services\StoreBalance;

final readonly class StoreBalanceFundingPolicyState
{
    /** @param list<int> $presetAmounts */
    public function __construct(
        public int $minimumAmount,
        public array $presetAmounts,
        public int $version,
    ) {}

    public function matches(StoreBalanceFundingPolicyDefinition $definition): bool
    {
        return $this->minimumAmount === $definition->minimumAmount
            && $this->presetAmounts === $definition->presetAmounts;
    }

    /** @return array{minimum_amount: int, preset_amounts: list<int>} */
    public function auditValues(): array
    {
        return [
            'minimum_amount' => $this->minimumAmount,
            'preset_amounts' => $this->presetAmounts,
        ];
    }
}
