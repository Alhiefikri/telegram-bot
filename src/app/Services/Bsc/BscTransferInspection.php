<?php

namespace App\Services\Bsc;

final readonly class BscTransferInspection
{
    /**
     * @param  list<BscTransferEvidence>  $transfers
     * @param  list<BscTransferEvidence>  $exactTransfers
     */
    public function __construct(
        public string $receiptState,
        public array $transfers = [],
        public array $exactTransfers = [],
    ) {}

    public function successfulReceipt(): bool
    {
        return $this->receiptState === 'success';
    }
}
