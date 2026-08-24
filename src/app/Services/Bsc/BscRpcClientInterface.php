<?php

namespace App\Services\Bsc;

interface BscRpcClientInterface
{
    public function chainId(): int;

    public function contractCode(string $contract): string;

    public function tokenDecimals(string $contract): int;

    public function isValidErc1271Signature(string $contract, string $messageHashHex, string $signature): bool;
}
