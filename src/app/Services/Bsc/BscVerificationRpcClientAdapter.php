<?php

namespace App\Services\Bsc;

/**
 * Keeps the readiness-only BscRpcClientInterface source-compatible with
 * existing test doubles while the verifier gains receipt/log capabilities.
 */
final class BscVerificationRpcClientAdapter implements BscVerificationRpcClientInterface
{
    public function __construct(private readonly BscRpcClientInterface $client) {}

    public function chainId(): int
    {
        return $this->client->chainId();
    }

    public function contractCode(string $contract): string
    {
        return $this->client->contractCode($contract);
    }

    public function tokenDecimals(string $contract): int
    {
        return $this->client->tokenDecimals($contract);
    }

    public function isValidErc1271Signature(string $contract, string $messageHashHex, string $signature): bool
    {
        return $this->client->isValidErc1271Signature($contract, $messageHashHex, $signature);
    }

    /** @return array<string, mixed>|null */
    public function transactionReceipt(string $transactionHash): ?array
    {
        return $this->callOptional('transactionReceipt', [$transactionHash]);
    }

    /** @return array<string, mixed>|null */
    public function blockByNumber(string|int $blockNumber): ?array
    {
        return $this->callOptional('blockByNumber', [$blockNumber]);
    }

    /** @return list<array<string, mixed>> */
    public function transferLogs(string $contract, string|int $fromBlock, string|int $toBlock, ?string $receiver = null): array
    {
        return $this->callOptional('transferLogs', [$contract, $fromBlock, $toBlock, $receiver]);
    }

    private function callOptional(string $method, array $arguments): mixed
    {
        if (! method_exists($this->client, $method)) {
            throw new BscRpcException('BSC RPC client does not support '.$method.'.', retryable: false);
        }

        return $this->client->{$method}(...$arguments);
    }
}
