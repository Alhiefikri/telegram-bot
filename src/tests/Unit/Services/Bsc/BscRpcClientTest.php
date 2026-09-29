<?php

namespace Tests\Unit\Services\Bsc;

use App\Services\Bsc\BscRpcClient;
use App\Services\Bsc\BscRpcException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BscRpcClientTest extends TestCase
{
    public function test_it_reads_chain_contract_code_and_token_decimals(): void
    {
        config()->set('services.bsc.rpc_urls', ['https://bsc-rpc.test']);

        Http::fakeSequence()
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x38'])
            ->push(['jsonrpc' => '2.0', 'id' => 2, 'result' => '0x60016001'])
            ->push(['jsonrpc' => '2.0', 'id' => 3, 'result' => '0x'.str_pad('12', 64, '0', STR_PAD_LEFT)]);

        $client = new BscRpcClient;

        $this->assertSame(56, $client->chainId());
        $this->assertSame('0x60016001', $client->contractCode('0x55d398326f99059ff775485246999027b3197955'));
        $this->assertSame(18, $client->tokenDecimals('0x55d398326f99059ff775485246999027b3197955'));
    }

    public function test_it_uses_the_erc1271_magic_value_and_abi_encodes_the_signature(): void
    {
        config()->set('services.bsc.rpc_urls', ['https://bsc-rpc.test']);
        $contract = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $hash = '0x'.str_repeat('11', 32);
        $signature = '0x'.str_repeat('22', 65);

        Http::fake([
            'https://bsc-rpc.test' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x1626ba7e'.str_repeat('0', 56)]),
        ]);

        $this->assertTrue((new BscRpcClient)->isValidErc1271Signature($contract, $hash, $signature));

        Http::assertSent(function (Request $request) use ($contract, $hash, $signature): bool {
            $data = (string) data_get($request->data(), 'params.0.data');
            $signatureHex = substr($signature, 2);
            $expected = '0x1626ba7e'
                .substr($hash, 2)
                .str_pad('40', 64, '0', STR_PAD_LEFT)
                .str_pad(dechex(65), 64, '0', STR_PAD_LEFT)
                .$signatureHex
                .str_repeat('0', 64 - (strlen($signatureHex) % 64));

            return data_get($request->data(), 'params.0.to') === $contract
                && $data === $expected;
        });
    }

    public function test_it_falls_over_to_the_next_endpoint_when_the_first_one_is_degraded(): void
    {
        config()->set('services.bsc.rpc_urls', ['https://bsc-primary.test', 'https://bsc-fallback.test']);

        Http::fake([
            'https://bsc-primary.test' => Http::response('', 503),
            'https://bsc-fallback.test' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x38']),
        ]);

        $this->assertSame(56, (new BscRpcClient)->chainId());
    }

    public function test_it_stops_at_the_first_endpoint_that_answers(): void
    {
        config()->set('services.bsc.rpc_urls', ['https://bsc-primary.test', 'https://bsc-fallback.test']);

        Http::fake([
            'https://bsc-primary.test' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x38']),
            'https://bsc-fallback.test' => Http::response('', 500),
        ]);

        $this->assertSame(56, (new BscRpcClient)->chainId());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'bsc-fallback.test'));
    }

    public function test_it_filters_transfer_logs_by_token_contract_and_receiver(): void
    {
        config()->set('services.bsc.rpc_urls', ['https://bsc-rpc.test']);
        $contract = '0x55d398326f99059ff775485246999027b3197955';
        $receiver = '0x1111111111111111111111111111111111111111';
        $log = [
            'address' => $contract,
            'transactionHash' => '0x'.str_repeat('a', 64),
            'blockNumber' => '0x64',
        ];
        Http::fake([
            'https://bsc-rpc.test' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => [$log],
            ]),
        ]);

        $this->assertSame([$log], (new BscRpcClient)->transferLogs($contract, $receiver, 100, 120));
        Http::assertSent(function (Request $request) use ($contract, $receiver): bool {
            $filter = data_get($request->data(), 'params.0');
            $receiverTopic = '0x'.str_pad(substr($receiver, 2), 64, '0', STR_PAD_LEFT);

            return data_get($request->data(), 'method') === 'eth_getLogs'
                && data_get($filter, 'fromBlock') === '0x64'
                && data_get($filter, 'toBlock') === '0x78'
                && data_get($filter, 'address') === $contract
                && data_get($filter, 'topics.0') === '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef'
                && data_get($filter, 'topics.1') === null
                && data_get($filter, 'topics.2') === $receiverTopic;
        });
    }

    public function test_it_reads_the_latest_block_number_for_readiness_probes(): void
    {
        config()->set('services.bsc.rpc_urls', ['https://bsc-rpc.test']);
        Http::fake([
            'https://bsc-rpc.test' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => ['number' => '0x64', 'hash' => '0x'.str_repeat('a', 64)],
            ]),
        ]);

        $this->assertSame(100, (new BscRpcClient)->latestBlockNumber());
        Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'method') === 'eth_getBlockByNumber'
            && data_get($request->data(), 'params.0') === 'latest'
            && data_get($request->data(), 'params.1') === false);
    }

    public function test_latest_block_number_requires_a_valid_hex_quantity(): void
    {
        config()->set('services.bsc.rpc_urls', ['https://bsc-rpc.test']);
        Http::fake([
            'https://bsc-rpc.test' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => ['number' => 'head'],
            ]),
        ]);

        $this->expectException(BscRpcException::class);
        $this->expectExceptionMessage('invalid latest block number');

        (new BscRpcClient)->latestBlockNumber();
    }

    public function test_it_falls_over_when_the_primary_provider_rejects_transfer_log_queries(): void
    {
        config()->set('services.bsc.rpc_urls', ['https://bsc-primary.test', 'https://bsc-log-fallback.test']);
        $contract = '0x55d398326f99059ff775485246999027b3197955';
        $receiver = '0x1111111111111111111111111111111111111111';

        Http::fake([
            'https://bsc-primary.test' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'error' => ['code' => -32005, 'message' => 'limit exceeded'],
            ]),
            'https://bsc-log-fallback.test' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 2,
                'result' => [],
            ]),
        ]);

        $logs = (new BscRpcClient)->transferLogs($contract, $receiver, 100, 100);

        $this->assertSame([], $logs);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'bsc-log-fallback.test')
            && data_get($request->data(), 'method') === 'eth_getLogs');
    }

    public function test_it_reports_the_last_endpoint_failure_when_every_endpoint_is_unavailable(): void
    {
        config()->set('services.bsc.rpc_urls', ['https://bsc-primary.test', 'https://bsc-fallback.test']);

        Http::fake([
            'https://bsc-primary.test' => Http::response('', 500),
            'https://bsc-fallback.test' => Http::response('', 429),
        ]);

        try {
            (new BscRpcClient)->chainId();
            $this->fail('Expected the RPC client to fail when every endpoint is unavailable.');
        } catch (BscRpcException $exception) {
            $this->assertTrue($exception->retryable);
            $this->assertStringContainsString('429', $exception->getMessage());
        }
    }

    public function test_an_empty_endpoint_list_is_reported_as_not_configured(): void
    {
        config()->set('services.bsc.rpc_urls', []);

        $this->expectException(BscRpcException::class);
        $this->expectExceptionMessage('BSC RPC endpoints are not configured.');

        (new BscRpcClient)->chainId();
    }

    public function test_the_application_ships_pinned_https_public_endpoints(): void
    {
        $urls = (array) config('services.bsc.rpc_urls');

        $this->assertContains('https://bsc-dataseed.binance.org', $urls);
        $this->assertContains('https://bsc-rpc.publicnode.com', $urls);
        $this->assertNotContains('https://bsc.publicnode.com', $urls);
        foreach ($urls as $url) {
            $this->assertStringStartsWith('https://', (string) $url);
        }
    }
}
