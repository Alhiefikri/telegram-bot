<?php

namespace App\Services\Bsc;

use Illuminate\Support\Carbon;

final readonly class BscTransferEvidence
{
    public function __construct(
        public string $txHash,
        public string $tokenContract,
        public string $receiverAddress,
        public ?string $fromAddress,
        public string $amountUnits,
        public int $blockNumber,
        public ?string $blockHash,
        public ?Carbon $blockTimestamp = null,
        public ?int $confirmations = null,
        public string $receiptStatus = 'success',
        public int $logIndex = 0,
    ) {}

    /** @return array<string, mixed> */
    public function sanitizedPayload(): array
    {
        return [
            'tx_hash' => $this->txHash,
            'token_contract' => $this->tokenContract,
            'receiver_address' => $this->receiverAddress,
            'from_address' => $this->fromAddress,
            'amount_units' => $this->amountUnits,
            'block_number' => $this->blockNumber,
            'block_hash' => $this->blockHash,
            'block_timestamp' => $this->blockTimestamp?->copy()->utc()->toIso8601String(),
            'confirmations' => $this->confirmations,
            'receipt_status' => $this->receiptStatus,
            'log_index' => $this->logIndex,
        ];
    }

    public function withChainFacts(Carbon $blockTimestamp, int $confirmations): self
    {
        return new self(
            txHash: $this->txHash,
            tokenContract: $this->tokenContract,
            receiverAddress: $this->receiverAddress,
            fromAddress: $this->fromAddress,
            amountUnits: $this->amountUnits,
            blockNumber: $this->blockNumber,
            blockHash: $this->blockHash,
            blockTimestamp: $blockTimestamp,
            confirmations: $confirmations,
            receiptStatus: $this->receiptStatus,
            logIndex: $this->logIndex,
        );
    }

    public function withBlockHash(?string $blockHash): self
    {
        return new self(
            txHash: $this->txHash,
            tokenContract: $this->tokenContract,
            receiverAddress: $this->receiverAddress,
            fromAddress: $this->fromAddress,
            amountUnits: $this->amountUnits,
            blockNumber: $this->blockNumber,
            blockHash: $blockHash,
            blockTimestamp: $this->blockTimestamp,
            confirmations: $this->confirmations,
            receiptStatus: $this->receiptStatus,
            logIndex: $this->logIndex,
        );
    }
}
