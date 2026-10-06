<?php

namespace Tests\Support;

use App\Services\Bsc\BscReadiness;
use App\Services\Bsc\BscRpcClient;
use App\Services\Bsc\BscRpcException;
use App\Services\Bsc\BscTransferLogRpcClientInterface;
use App\Services\Bsc\BscVerificationRpcClientInterface;
use Brick\Math\BigInteger;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Synthetic contract evidence only; never staging/custody qualification. */
final class BscUsdtRailRpcFixture implements BscTransferLogRpcClientInterface, BscVerificationRpcClientInterface
{
    public const ADDRESS = '0xabcdefabcdefabcdefabcdefabcdefabcdefabcd';

    public const TX = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public const PROBE_TX = '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public int $chain = 56;

    public int $decimals = 18;

    public int $head = 110;

    public int $finalized = 105;

    public int $timestamp;

    public ?array $receipt;

    public array $logs = [];

    public array $blockOverrides = [];

    public array $failMethods = [];

    public array $calls = [];

    public ?Closure $onCall = null;

    public ?Closure $onLogs = null;

    public function __construct()
    {
        $this->timestamp = now()->getTimestamp();
        $log = $this->log();
        $this->receipt = [
            'status' => '0x1', 'transactionHash' => self::TX,
            'blockNumber' => '0x64', 'blockHash' => self::blockHash(100), 'logs' => [$log],
        ];
    }

    public static function blockHash(int $number): string
    {
        return '0x'.hash('sha256', 'sandbox-block:'.$number);
    }

    public function log(int $number = 100, int $index = 0, string $amount = '1000000000000000000', string $receiver = self::ADDRESS): array
    {
        return [
            'address' => BscReadiness::APPROVED_USDT_CONTRACT,
            'topics' => [BscRpcClient::TRANSFER_TOPIC,
                '0x'.str_repeat('0', 24).str_repeat('9', 40),
                '0x'.str_repeat('0', 24).substr($receiver, 2)],
            'data' => '0x'.str_pad(BigInteger::of($amount)->toBase(16), 64, '0', STR_PAD_LEFT),
            'transactionHash' => self::TX, 'blockNumber' => '0x'.dechex($number),
            'blockHash' => self::blockHash($number), 'logIndex' => '0x'.dechex($index), 'removed' => false,
        ];
    }

    public function chainId(): int
    {
        $this->record('chainId');

        return $this->chain;
    }

    public function contractCode(string $contract): string
    {
        $this->record('contractCode');

        return '0x6001';
    }

    public function tokenDecimals(string $contract): int
    {
        $this->record('tokenDecimals');

        return $this->decimals;
    }

    public function isValidErc1271Signature(string $contract, string $messageHashHex, string $signature): bool
    {
        throw new LogicException('This watch-only fixture has no signing/ownership authority.');
    }

    public function transactionReceipt(string $transactionHash): ?array
    {
        $this->record('transactionReceipt');
        if ($transactionHash === self::PROBE_TX) {
            return ['status' => '0x1', 'transactionHash' => self::PROBE_TX,
                'blockNumber' => '0x'.dechex($this->finalized), 'blockHash' => self::blockHash($this->finalized), 'logs' => []];
        }

        return $this->receipt;
    }

    public function blockByNumber(string|int $blockNumber): ?array
    {
        $this->record('blockByNumber:'.$blockNumber);
        if (array_key_exists($blockNumber, $this->blockOverrides)) {
            return $this->blockOverrides[$blockNumber];
        }
        $number = match ($blockNumber) {
            'latest' => $this->head,
            'finalized' => $this->finalized,
            default => (int) $blockNumber,
        };

        return ['number' => '0x'.dechex($number), 'hash' => self::blockHash($number),
            'timestamp' => '0x'.dechex($this->timestamp), 'transactions' => [self::PROBE_TX]];
    }

    public function latestBlockNumber(): int
    {
        $this->record('latestBlockNumber');

        return $this->head;
    }

    public function transferLogs(string $tokenContract, string $receiverAddress, int $fromBlock, int $toBlock): array
    {
        $this->record('transferLogs');
        if ($this->onLogs !== null) {
            return ($this->onLogs)($fromBlock, $toBlock);
        }
        // Capability qualification uses a reserved probe receiver, not a
        // customer scan. Invoice lookups use eth_getTransactionReceipt.
        if ($receiverAddress !== self::ADDRESS) {
            return [];
        }

        return array_values(array_filter($this->logs, static fn (array $log): bool => hexdec($log['blockNumber']) >= $fromBlock && hexdec($log['blockNumber']) <= $toBlock));
    }

    private function record(string $method): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('RPC fixture observed network work inside a database transaction.');
        }
        $this->calls[] = $method;
        if ($this->onCall !== null) {
            ($this->onCall)($method);
        }
        if (in_array($method, $this->failMethods, true)) {
            throw new BscRpcException('Sandbox RPC unavailable.');
        }
    }
}
