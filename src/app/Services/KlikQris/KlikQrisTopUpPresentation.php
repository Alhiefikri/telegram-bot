<?php

namespace App\Services\KlikQris;

/**
 * Customer-safe Store Balance top-up presentation.
 *
 * The lifecycle outcome and payable payload are the only provider-facing data
 * exposed to callers. Persistence-state details are reduced to semantic
 * flags by KlikQrisNativeQrisService before this DTO crosses the boundary.
 */
final readonly class KlikQrisTopUpPresentation
{
    private function __construct(
        private KlikQrisNativeQrisOutcome $outcome,
        private ?KlikQrisPayableBalanceTopUp $payable,
        private bool $paid,
        private bool $recovered,
        private bool $retryable,
        private bool $manualReview,
        private bool $checkable,
    ) {}

    public static function fromLifecycle(
        KlikQrisTopUpLifecycleResult $lifecycle,
        bool $paid = false,
        bool $recovered = false,
        bool $retryable = false,
        bool $manualReview = false,
        bool $checkable = false,
    ): self {
        return new self(
            $lifecycle->outcome(),
            $lifecycle->payable(),
            $paid,
            $recovered,
            $retryable,
            $manualReview,
            $checkable,
        );
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
        return ! $this->paid
            && ! $this->manualReview
            && $this->outcome === KlikQrisNativeQrisOutcome::Payable
            && $this->payable !== null;
    }

    public function isPaid(): bool
    {
        return $this->paid;
    }

    public function isRecovered(): bool
    {
        return $this->paid && $this->recovered;
    }

    public function canRetry(): bool
    {
        return $this->retryable;
    }

    public function isManualReview(): bool
    {
        return $this->manualReview;
    }

    public function canCheck(): bool
    {
        return $this->checkable;
    }
}
