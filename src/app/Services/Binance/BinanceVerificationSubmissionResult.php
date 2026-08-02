<?php

namespace App\Services\Binance;

use App\Models\Payment;

final readonly class BinanceVerificationSubmissionResult
{
    public function __construct(
        public BinanceVerificationSubmissionOutcome $outcome,
        public ?Payment $payment = null,
        public int $version = 0,
        public bool $dispatchRequired = false,
        public bool $dispatchFailed = false,
    ) {}

    public static function of(
        BinanceVerificationSubmissionOutcome $outcome,
        ?Payment $payment = null,
        int $version = 0,
        bool $dispatchRequired = false,
        bool $dispatchFailed = false,
    ): self {
        return new self($outcome, $payment, $version, $dispatchRequired, $dispatchFailed);
    }

    public function accepted(): bool
    {
        return $this->outcome === BinanceVerificationSubmissionOutcome::Accepted;
    }
}
