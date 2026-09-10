<?php

namespace App\Services\StoreBalance;

final readonly class StoreBalanceFundingAmountValidation
{
    public function __construct(
        public ?int $amount,
        public ?string $error,
    ) {}

    public function isValid(): bool
    {
        return $this->amount !== null && $this->error === null;
    }
}
