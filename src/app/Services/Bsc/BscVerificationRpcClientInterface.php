<?php

namespace App\Services\Bsc;

/**
 * The transaction-verification surface is deliberately separate from the
 * readiness surface. Existing readiness fakes only need contract metadata;
 * verifier callers must opt into receipt and block access explicitly.
 */
interface BscVerificationRpcClientInterface extends BscRpcClientInterface
{
    /** @return array<string, mixed>|null */
    public function transactionReceipt(string $transactionHash): ?array;

    /** @return array<string, mixed>|null */
    public function blockByNumber(string|int $blockNumber): ?array;
}
