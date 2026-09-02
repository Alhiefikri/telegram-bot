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
        config()->set('services.bsc.rpc_url', 'https://bsc-rpc.test');

        Http::fakeSequence()
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x38'])
            ->push(['jsonrpc' => '2.0', 'id' => 2, 'result' => '0x60016001'])
            ->push(['jsonrpc' => '2.0', 'id' => 3, 'result' => '0x'.str_pad('12', 64, '0', STR_PAD_LEFT)]);

        $client = new BscRpcClient;

        $this->assertSame(56, $client->chainId());
        $this->assertSame('0x60016001', $client->contractCode('0x55d398326f99059ff775485246999027b3197955'));
        $this->assertSame(18, $client->tokenDecimals('0x55d398326f99059ff775485246999027b3197955'));
    }

    public function test_transfer_logs_resolves_latest_block_for_non_archive_rpc_endpoints(): void
    {
        config()->set('services.bsc.rpc_url', 'https://bsc-rpc.test');

        Http::fakeSequence()
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x100'])
            ->push(['jsonrpc' => '2.0', 'id' => 2, 'result' => []]);

        $logs = (new BscRpcClient)->transferLogs(
            '0x55d398326f99059ff775485246999027b3197955',
            'latest',
            'latest',
        );

        $this->assertSame([], $logs);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->data()['method'] === 'eth_blockNumber');
        Http::assertSent(fn (Request $request): bool => $request->data()['method'] === 'eth_getLogs'
            && data_get($request->data(), 'params.0.fromBlock') === '0x100'
            && data_get($request->data(), 'params.0.toBlock') === '0x100');

        config()->set('services.bsc.max_scan_blocks', 1);
        try {
            (new BscRpcClient)->transferLogs(
                '0x55d398326f99059ff775485246999027b3197955',
                0,
                1,
            );
            $this->fail('Expected oversized BSC log ranges to be rejected.');
        } catch (BscRpcException $exception) {
            $this->assertStringContainsString('bounded window', $exception->getMessage());
        }
    }

    public function test_it_uses_the_erc1271_magic_value_and_abi_encodes_the_signature(): void
    {
        config()->set('services.bsc.rpc_url', 'https://bsc-rpc.test');
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
}
