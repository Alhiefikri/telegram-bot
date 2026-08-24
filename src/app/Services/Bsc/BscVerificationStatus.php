<?php

namespace App\Services\Bsc;

final readonly class BscVerificationStatus
{
    public function __construct(
        public BscVerificationOutcome $outcome,
        public string $status,
        public ?string $transactionHash = null,
        public ?int $confirmations = null,
        public int $attempts = 0,
        public int $version = 0,
    ) {}
}
