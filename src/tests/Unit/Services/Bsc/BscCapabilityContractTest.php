<?php

namespace Tests\Unit\Services\Bsc;

use App\Services\Bsc\BscCapabilityProbe;
use App\Services\Bsc\BscCapabilityReport;
use App\Services\Bsc\BscCapabilityStatus;
use App\Services\Bsc\BscRpcClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BscCapabilityContractTest extends TestCase
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

    public function test_well_formed_primary_bsc_fixtures_report_verified_capabilities(): void
    {
        $this->fakeRpc();

        $report = $this->probe();

        foreach ($report->requiredCapabilities() as $capability) {
            $this->assertSame(
                BscCapabilityStatus::Verified,
                $report->status($capability),
                $capability.' should be verified: '.$report->detail($capability),
            );
        }
        $this->assertTrue($report->isQualified());
        $this->assertSame(56, $report->chainId);
        $this->assertSame(18, $report->tokenDecimals);

        $payload = $report->toArray();
        $this->assertTrue($payload['rpc_qualified']);
        $this->assertSame('verified', $payload['capabilities']['canonical_receipt']['status']);
    }

    public function test_testnet_chain_fixture_is_unsupported_and_fails_closed(): void
    {
        // Sandbox/testnet fixture (chain 97). This is not production evidence
        // and must never qualify the primary mainnet rail.
        $this->fakeRpc(results: ['eth_chainId' => '0x61']);

        $report = $this->probe();

        $this->assertSame(BscCapabilityStatus::Unsupported, $report->status('chain_id'));
        $this->assertFalse($report->isQualified());
    }

    public function test_empty_contract_code_fixture_is_unsupported_and_fails_closed(): void
    {
        $this->fakeRpc(results: ['eth_getCode' => '0x']);

        $report = $this->probe();

        $this->assertSame(BscCapabilityStatus::Unsupported, $report->status('token_contract_code'));
        $this->assertFalse($report->isQualified());
    }

    public function test_decimals_mismatch_fixture_is_unsupported_and_fails_closed(): void
    {
        $this->fakeRpc(results: ['eth_call' => '0x'.str_pad('6', 64, '0', STR_PAD_LEFT)]);

        $report = $this->probe();

        $this->assertSame(BscCapabilityStatus::Unsupported, $report->status('token_decimals'));
        $this->assertSame(6, $report->tokenDecimals);
        $this->assertFalse($report->isQualified());
    }

    public function test_method_not_found_finalized_fixture_is_unsupported_and_fails_closed(): void
    {
        $this->fakeRpc(errors: [
            'eth_getBlockByNumber' => function (Request $request): ?array {
                if (data_get($request->data(), 'params.0') !== 'finalized') {
                    return null;
                }

                return ['code' => -32601, 'message' => 'the method eth_getBlockByNumber does not exist/is not available'];
            },
        ]);

        $report = $this->probe();

        $this->assertSame(BscCapabilityStatus::Unsupported, $report->status('finalized_block'));
        $this->assertSame(BscCapabilityStatus::Unknown, $report->status('canonical_receipt'));
        $this->assertFalse($report->isQualified());
    }

    public function test_incomplete_finalized_block_fixture_is_unknown_and_fails_closed(): void
    {
        $this->fakeRpc(results: [
            'eth_getBlockByNumber' => function (Request $request): ?array {
                if (data_get($request->data(), 'params.0') !== 'finalized') {
                    return $this->defaultResult('eth_getBlockByNumber', $request);
                }

                return ['number' => '0x'.dechex(self::FINALIZED_NUMBER)];
            },
        ]);

        $report = $this->probe();

        $this->assertSame(BscCapabilityStatus::Unknown, $report->status('finalized_block'));
        $this->assertFalse($report->isQualified());
    }

    public function test_finalized_tag_that_never_moves_behind_head_is_unknown_and_fails_closed(): void
    {
        $this->fakeRpc(results: [
            'eth_getBlockByNumber' => fn (Request $request): array => [
                'number' => '0x'.dechex(self::FINALIZED_NUMBER),
                'hash' => self::FINALIZED_HASH,
                'transactions' => data_get($request->data(), 'params.0') === 'finalized' ? [self::TRANSACTION_HASH] : [],
            ],
        ]);

        $report = $this->probe();

        $this->assertSame(BscCapabilityStatus::Unknown, $report->status('finalized_block'));
        $this->assertSame(BscCapabilityStatus::Unknown, $report->status('canonical_receipt'));
        $this->assertFalse($report->isQualified());
    }

    public function test_empty_finalized_transactions_keep_canonical_receipt_unknown(): void
    {
        $this->fakeRpc(results: [
            'eth_getBlockByNumber' => function (Request $request): ?array {
                if (data_get($request->data(), 'params.0') !== 'finalized') {
                    return $this->defaultResult('eth_getBlockByNumber', $request);
                }

                return [
                    'number' => '0x'.dechex(self::FINALIZED_NUMBER),
                    'hash' => self::FINALIZED_HASH,
                    'transactions' => [],
                ];
            },
        ]);

        $report = $this->probe();

        $this->assertSame(BscCapabilityStatus::Verified, $report->status('finalized_block'));
        $this->assertSame(BscCapabilityStatus::Unknown, $report->status('canonical_receipt'));
        $this->assertFalse($report->isQualified());
    }

    public function test_incomplete_receipt_fixture_is_unknown_and_fails_closed(): void
    {
        $this->fakeRpc(results: [
            'eth_getTransactionReceipt' => [
                'transactionHash' => self::TRANSACTION_HASH,
                'blockNumber' => '0x'.dechex(self::FINALIZED_NUMBER),
                'blockHash' => self::FINALIZED_HASH,
            ],
        ]);

        $report = $this->probe();

        $this->assertSame(BscCapabilityStatus::Unknown, $report->status('canonical_receipt'));
        $this->assertFalse($report->isQualified());
    }

    public function test_receipt_block_hash_mismatch_fixture_is_unsupported_and_fails_closed(): void
    {
        $this->fakeRpc(results: [
            'eth_getTransactionReceipt' => [
                'transactionHash' => self::TRANSACTION_HASH,
                'status' => '0x1',
                'blockNumber' => '0x'.dechex(self::FINALIZED_NUMBER),
                'blockHash' => '0x'.str_repeat('d4', 32),
            ],
        ]);

        $report = $this->probe();

        $this->assertSame(BscCapabilityStatus::Unsupported, $report->status('canonical_receipt'));
        $this->assertFalse($report->isQualified());
    }

    public function test_transfer_log_limit_rejection_is_not_verified(): void
    {
        $this->fakeRpc(errors: [
            'eth_getLogs' => ['code' => -32005, 'message' => 'limit exceeded'],
        ]);

        $report = $this->probe();

        $this->assertSame(BscCapabilityStatus::Unknown, $report->status('transfer_logs'));
        $this->assertFalse($report->isQualified());
    }

    public function test_transport_failure_fixture_is_unknown_and_fails_closed(): void
    {
        Http::fake(fn (Request $request) => data_get($request->data(), 'method') === 'eth_chainId'
            ? Http::response('', 500)
            : Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => $this->defaultResult((string) data_get($request->data(), 'method'), $request),
            ]));

        $report = $this->probe();

        $this->assertSame(BscCapabilityStatus::Unknown, $report->status('chain_id'));
        $this->assertFalse($report->isQualified());
    }

    public function test_malformed_json_fixture_is_unknown_and_fails_closed(): void
    {
        Http::fake(fn (Request $request) => data_get($request->data(), 'method') === 'eth_chainId'
            ? Http::response('<html>gateway</html>', 200)
            : Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => $this->defaultResult((string) data_get($request->data(), 'method'), $request),
            ]));

        $report = $this->probe();

        $this->assertSame(BscCapabilityStatus::Unknown, $report->status('chain_id'));
        $this->assertFalse($report->isQualified());
    }

    private function probe(): BscCapabilityReport
    {
        return (new BscCapabilityProbe(new BscRpcClient))->probe();
    }

    /**
     * @param  array<string, mixed>  $results
     * @param  array<string, array{code: int, message: string}|callable(Request): ?array>  $errors
     */
    private function fakeRpc(array $results = [], array $errors = []): void
    {
        Http::fake(function (Request $request) use ($results, $errors) {
            $method = (string) data_get($request->data(), 'method');

            if (array_key_exists($method, $errors)) {
                $error = $errors[$method];
                $error = is_callable($error) ? $error($request) : $error;
                if ($error !== null) {
                    return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'error' => $error]);
                }
            }

            if (array_key_exists($method, $results)) {
                $result = $results[$method];
                $result = is_callable($result) ? $result($request) : $result;
            } else {
                $result = $this->defaultResult($method, $request);
            }

            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
        });
    }

    private function defaultResult(string $method, Request $request): mixed
    {
        return match ($method) {
            'eth_chainId' => '0x38',
            'eth_getCode' => '0x6080604052',
            'eth_call' => '0x'.str_pad('12', 64, '0', STR_PAD_LEFT),
            'eth_getBlockByNumber' => data_get($request->data(), 'params.0') === 'finalized'
                ? [
                    'number' => '0x'.dechex(self::FINALIZED_NUMBER),
                    'hash' => self::FINALIZED_HASH,
                    'transactions' => [self::TRANSACTION_HASH],
                ]
                : [
                    'number' => '0x'.dechex(self::FINALIZED_NUMBER + 32),
                    'hash' => '0x'.str_repeat('b2', 32),
                    'transactions' => [],
                ],
            'eth_getTransactionReceipt' => $this->canonicalReceipt(),
            'eth_getLogs' => [],
            default => null,
        };
    }

    /** @return array<string, string> */
    private function canonicalReceipt(): array
    {
        return [
            'transactionHash' => self::TRANSACTION_HASH,
            'status' => '0x1',
            'blockNumber' => '0x'.dechex(self::FINALIZED_NUMBER),
            'blockHash' => self::FINALIZED_HASH,
        ];
    }
}
