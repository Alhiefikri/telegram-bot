<?php

namespace App\Services\Bsc;

use App\Models\Payment;

final readonly class BscVerificationSubmissionResult
{
    public function __construct(
        public BscVerificationSubmissionOutcome $outcome,
        public ?Payment $payment = null,
        public int $version = 0,
        public bool $dispatchRequired = false,
        public bool $dispatchFailed = false,
    ) {}

    public static function of(
        BscVerificationSubmissionOutcome $outcome,
        ?Payment $payment = null,
        int $version = 0,
        bool $dispatchRequired = false,
        bool $dispatchFailed = false,
    ): self {
        return new self($outcome, $payment, $version, $dispatchRequired, $dispatchFailed);
    }

    public function accepted(): bool
    {
        return $this->outcome === BscVerificationSubmissionOutcome::Accepted;
    }
}
