<?php

namespace App\Services\Bsc;

/** Inspection only: neither a global money-receipt claim nor ledger authority. */
final readonly class BscFinalReceiptInspection
{
    public function __construct(
        public string $status,
        public string $reason,
        public ?BscTransferEvidence $evidence = null,
    ) {}

    public function verified(): bool
    {
        return $this->status === 'verified';
    }
}
