<?php

namespace App\Services\Bsc;

final readonly class BscVerificationResult
{
    public function __construct(
        public BscVerificationOutcome $outcome,
        public ?BscTransferEvidence $evidence = null,
        public bool $retryable = true,
    ) {}

    public static function of(
        BscVerificationOutcome $outcome,
        ?BscTransferEvidence $evidence = null,
        bool $retryable = true,
    ): self {
        return new self($outcome, $evidence, $retryable);
    }

    public function confirmed(): bool
    {
        return $this->outcome === BscVerificationOutcome::Confirmed;
    }

    public function pending(): bool
    {
        return in_array($this->outcome, [
            BscVerificationOutcome::AwaitingTransfer,
            BscVerificationOutcome::TxSubmitted,
            BscVerificationOutcome::Confirming,
            BscVerificationOutcome::NotFound,
            BscVerificationOutcome::ProviderError,
        ], true);
    }

    public function needsManualReview(): bool
    {
        return in_array($this->outcome, [
            BscVerificationOutcome::Mismatch,
            BscVerificationOutcome::TransactionAlreadyUsed,
            BscVerificationOutcome::ManualReview,
        ], true);
    }
}
