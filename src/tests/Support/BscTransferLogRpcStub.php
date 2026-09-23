<?php

namespace Tests\Support;

trait BscTransferLogRpcStub
{
    public function latestBlockNumber(): int
    {
        if (method_exists($this, 'recordCall')) {
            $this->recordCall();
        }

        return 100;
    }

    /**
     * An empty but valid result models a healthy RPC with no matching logs in
     * the requested bounded range.
     *
     * @return list<array<string, mixed>>
     */
    public function transferLogs(
        string $tokenContract,
        string $receiverAddress,
        int $fromBlock,
        int $toBlock,
    ): array {
        if (method_exists($this, 'recordCall')) {
            $this->recordCall();
        }

        return [];
    }
}
