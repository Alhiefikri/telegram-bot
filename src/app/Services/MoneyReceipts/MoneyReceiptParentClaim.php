<?php

namespace App\Services\MoneyReceipts;

use App\Services\Bsc\BscTransactionHash;
use InvalidArgumentException;

/**
 * The BSC parent transaction (chain_id, tx_hash) that owns every log receipt
 * claimed from one on-chain transaction. In this release all logs in a
 * transaction must belong to one economic target; a second target is held for
 * review instead of being consumed.
 */
final readonly class MoneyReceiptParentClaim
{
    private function __construct(
        public int $chainId,
        public string $txHash,
    ) {
        if ($chainId <= 0) {
            throw new InvalidArgumentException('Money receipt parent chain id must be positive.');
        }
    }

    public static function for(int $chainId, string $txHash): self
    {
        return new self($chainId, BscTransactionHash::require($txHash));
    }
}
