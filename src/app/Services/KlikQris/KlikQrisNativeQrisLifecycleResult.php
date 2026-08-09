<?php

namespace App\Services\KlikQris;

/**
 * The result of a KlikQRIS Native QRIS lifecycle call.
 *
 * Exactly one of the six outcomes is returned. A Payable outcome carries the
 * presentation-safe payload; every other outcome carries none.
 */
final readonly class KlikQrisNativeQrisLifecycleResult
{
    private function __construct(
        private KlikQrisNativeQrisOutcome $outcome,
        private ?KlikQrisPayableNativeQris $payable,
    ) {}

    public static function payableResult(KlikQrisPayableNativeQris $payable): self
    {
        return new self(KlikQrisNativeQrisOutcome::Payable, $payable);
    }

    public static function state(KlikQrisNativeQrisOutcome $outcome): self
    {
        return new self($outcome, null);
    }

    public function outcome(): KlikQrisNativeQrisOutcome
    {
        return $this->outcome;
    }

    public function payable(): ?KlikQrisPayableNativeQris
    {
        return $this->payable;
    }

    public function isPayable(): bool
    {
        return $this->outcome === KlikQrisNativeQrisOutcome::Payable;
    }
}
