<?php

namespace App\Services\Bsc;

/**
 * The transfer-verification surface is deliberately separate from the
 * readiness surface. Existing readiness fakes only need contract metadata;
 * verifier callers must opt into receipt, block, and log access explicitly.
 */
interface BscVerificationRpcClientInterface extends BscRpcClientInterface
{
    /** @return array<string, mixed>|null */
    public function transactionReceipt(string $transactionHash): ?array;

    /** @return array<string, mixed>|null */
    public function blockByNumber(string|int $blockNumber): ?array;

    /** @return list<array<string, mixed>> */
    public function transferLogs(
        string $contract,
        string|int $fromBlock,
        string|int $toBlock,
        ?string $receiver = null,
    ): array;
}
