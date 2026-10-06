<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BscCapabilityCommandTest extends TestCase
{
    private const CONTRACT = '0x55d398326f99059ff775485246999027b3197955';

    private const FINALIZED_NUMBER = 46_000_000;

    private const FINALIZED_HASH = '0xc3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3';

    private const TRANSACTION_HASH = '0xa1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.bsc.rpc_urls', ['https://bsc-rpc.test']);
        config()->set('services.bsc.usdt_contract', self::CONTRACT);
        config()->set('services.bsc.expected_token_decimals', 18);
        config()->set('services.bsc.log_scan_chunk_blocks', 1_000);
        config()->set('services.bsc.rpc_max_attempts', 1);
        config()->set('services.bsc.rpc_retry_delay_ms', 0);
    }

    public function test_command_reports_qualified_and_exits_success_for_verified_evidence(): void
    {
        $this->fakeRpc();

        $this->assertSame(0, Artisan::call('bsc:check-capabilities', ['--json' => true]));

        $output = Artisan::output();
        $this->assertStringContainsString('"rpc_qualified":true', $output);
        $this->assertStringContainsString('"canonical_receipt":{"status":"verified"', $output);
    }

    public function test_command_fails_closed_for_mismatch_and_unknown_evidence(): void
    {
        $this->fakeRpc(chainId: '0x61');

        $this->assertSame(1, Artisan::call('bsc:check-capabilities', ['--json' => true]));

        $output = Artisan::output();
        $this->assertStringContainsString('"rpc_qualified":false', $output);
        $this->assertStringContainsString('"chain_id":{"status":"unsupported"', $output);
    }

    private function fakeRpc(string $chainId = '0x38'): void
    {
        Http::fake(function (Request $request) use ($chainId): array {
            return match ((string) data_get($request->data(), 'method')) {
                'eth_chainId' => ['jsonrpc' => '2.0', 'id' => 1, 'result' => $chainId],
                'eth_getCode' => ['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x6080604052'],
                'eth_call' => ['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x'.str_pad('12', 64, '0', STR_PAD_LEFT)],
                'eth_getBlockByNumber' => ['jsonrpc' => '2.0', 'id' => 1, 'result' => data_get($request->data(), 'params.0') === 'finalized'
                    ? [
                        'number' => '0x'.dechex(self::FINALIZED_NUMBER),
                        'hash' => self::FINALIZED_HASH,
                        'transactions' => [self::TRANSACTION_HASH],
                    ]
                    : ['number' => '0x'.dechex(self::FINALIZED_NUMBER + 32), 'hash' => '0x'.str_repeat('b2', 32)]],
                'eth_getTransactionReceipt' => ['jsonrpc' => '2.0', 'id' => 1, 'result' => [
                    'transactionHash' => self::TRANSACTION_HASH,
                    'status' => '0x1',
                    'blockNumber' => '0x'.dechex(self::FINALIZED_NUMBER),
                    'blockHash' => self::FINALIZED_HASH,
                ]],
                'eth_getLogs' => ['jsonrpc' => '2.0', 'id' => 1, 'result' => []],
                default => ['jsonrpc' => '2.0', 'id' => 1, 'result' => null],
            };
        });
    }
}
