<?php

namespace App\Services\KlikQris;

final readonly class KlikQrisTopUpLifecycleResult
{
    private function __construct(
        private KlikQrisNativeQrisOutcome $outcome,
        private ?KlikQrisPayableBalanceTopUp $payable,
    ) {}

    public static function state(KlikQrisNativeQrisOutcome $outcome): self
    {
        return new self($outcome, null);
    }

    public static function payableResult(KlikQrisPayableBalanceTopUp $payable): self
    {
        return new self(KlikQrisNativeQrisOutcome::Payable, $payable);
    }

    public function outcome(): KlikQrisNativeQrisOutcome
    {
        return $this->outcome;
    }

    public function payable(): ?KlikQrisPayableBalanceTopUp
    {
        return $this->payable;
    }

    public function isPayable(): bool
    {
        return $this->payable !== null;
    }
}
