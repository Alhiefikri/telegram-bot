<?php

namespace App\Services\Binance;

final readonly class BinanceVerificationResult
{
    public function __construct(
        public BinanceVerificationOutcome $outcome,
        public ?BinancePayTransaction $transaction = null,
        public bool $retryable = true,
    ) {}

    public static function of(BinanceVerificationOutcome $outcome, ?BinancePayTransaction $transaction = null, bool $retryable = true): self
    {
        return new self($outcome, $transaction, $retryable);
    }

    public function confirmed(): bool
    {
        return $this->outcome === BinanceVerificationOutcome::Confirmed;
    }

    public function needsAdminAttention(): bool
    {
        return in_array($this->outcome, [
            BinanceVerificationOutcome::Mismatch,
            BinanceVerificationOutcome::TransactionAlreadyUsed,
            BinanceVerificationOutcome::OrderNotPending,
        ], true);
    }
}
