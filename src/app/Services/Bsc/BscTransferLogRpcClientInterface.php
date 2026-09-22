<?php

declare(strict_types=1);

namespace App\Services\Bsc;

interface BscTransferLogRpcClientInterface
{
    /** @return list<array<string, mixed>> */
    public function transferLogs(
        string $tokenContract,
        string $receiverAddress,
        int $fromBlock,
        int $toBlock,
    ): array;
}
