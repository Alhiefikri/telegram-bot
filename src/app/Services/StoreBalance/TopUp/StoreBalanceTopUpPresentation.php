<?php

namespace App\Services\StoreBalance\TopUp;

use App\Enums\StoreBalanceTopUpOutcome;

/**
 * Customer-safe Store Balance top-up presentation.
 *
 * The rail owns its persistence vocabulary and reduces it to these semantic
 * flags; provider payloads and provider state strings never cross this seam.
 */
final readonly class StoreBalanceTopUpPresentation
{
    private function __construct(
        private StoreBalanceTopUpOutcome $outcome,
        private ?StoreBalanceTopUpPayable $payable,
        private bool $paid,
        private bool $recovered,
        private bool $retryable,
        private bool $manualReview,
        private bool $checkable,
        private bool $rejected = false,
    ) {}

    public static function fromLifecycle(
        StoreBalanceTopUpLifecycleResult $lifecycle,
        bool $paid = false,
        bool $recovered = false,
        bool $retryable = false,
        bool $manualReview = false,
        bool $checkable = false,
        bool $rejected = false,
    ): self {
        return new self(
            $lifecycle->outcome(),
            $lifecycle->payable(),
            $paid,
            $recovered,
            $retryable,
            $manualReview,
            $checkable,
            $rejected,
        );
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
        return ! $this->paid
            && ! $this->manualReview
            && $this->outcome === StoreBalanceTopUpOutcome::Payable
            && $this->payable !== null;
    }

    public function isAwaitingConfirmation(): bool
    {
        return ! $this->paid
            && ! $this->manualReview
            && $this->outcome === StoreBalanceTopUpOutcome::AwaitingConfirmation
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

    public function isRejected(): bool
    {
        return $this->rejected;
    }
}
