<?php

namespace App\Services\StoreBalance\TopUp;

use App\Enums\StoreBalanceTopUpOutcome;

final readonly class StoreBalanceTopUpLifecycleResult
{
    private function __construct(
        private StoreBalanceTopUpOutcome $outcome,
        private ?StoreBalanceTopUpPayable $payable,
    ) {}

    public static function state(StoreBalanceTopUpOutcome $outcome): self
    {
        return new self($outcome, null);
    }

    public static function payableResult(StoreBalanceTopUpPayable $payable): self
    {
        return new self(StoreBalanceTopUpOutcome::Payable, $payable);
    }

    public static function awaitingConfirmation(StoreBalanceTopUpPayable $payable): self
    {
        return new self(StoreBalanceTopUpOutcome::AwaitingConfirmation, $payable);
    }

    public function outcome(): StoreBalanceTopUpOutcome
    {
        return $this->outcome;
    }

    public function payable(): ?StoreBalanceTopUpPayable
    {
        return $this->payable;
    }

    public function isPayable(): bool
    {
        return $this->outcome === StoreBalanceTopUpOutcome::Payable && $this->payable !== null;
    }

    public function isAwaitingConfirmation(): bool
    {
        return $this->outcome === StoreBalanceTopUpOutcome::AwaitingConfirmation && $this->payable !== null;
    }
}
