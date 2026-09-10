<?php

namespace App\Services\StoreBalance;

final readonly class StoreBalanceFundingIntentInput
{
    private function __construct(
        public mixed $rawAmount,
        public ?int $policyVersion,
        public ?int $presetIndex,
    ) {}

    public static function custom(string $rawAmount): self
    {
        return new self($rawAmount, null, null);
    }

    public static function preset(StoreBalanceFundingPresetSelection $selection): self
    {
        return new self($selection->amount, $selection->policyVersion, $selection->index);
    }

    public function isPreset(): bool
    {
        return $this->policyVersion !== null && $this->presetIndex !== null;
    }
}
