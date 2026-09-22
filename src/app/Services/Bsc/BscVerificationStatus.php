<?php

namespace App\Services\Bsc;

use App\Models\PaymentBscDetail;

final readonly class BscVerificationStatus
{
    public function __construct(
        public BscVerificationOutcome $outcome,
        public string $status,
        public ?string $transactionHash = null,
        public ?int $confirmations = null,
        public int $attempts = 0,
        public int $version = 0,
        public int $requiredConfirmations = 3,
        public string $verificationMode = PaymentBscDetail::VERIFICATION_MODE_TRANSACTION_HASH,
    ) {}
}
