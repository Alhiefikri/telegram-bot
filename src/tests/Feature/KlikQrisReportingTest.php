<?php

namespace Tests\Feature;

use App\Services\KlikQris\KlikQrisClient;
use App\Services\KlikQris\KlikQrisReportingException;
use App\Services\KlikQris\KlikQrisWithdrawalQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\TestCase;

final class KlikQrisReportingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        KlikQrisSandboxScenarios::configureReadySandbox();
        config()->set('services.provider_verification.reconciliation.retry_delay_ms', 0);
        config()->set('services.provider_verification.reconciliation.retry_max_delay_ms', 0);
        config()->set('services.klikqris.reporting_cache_seconds', 0);
        Http::preventStrayRequests();
    }

    public function test_history_page_uses_the_sandbox_contract_and_parses_a_page(): void
    {
        Http::fake([
            'https://klikqris.com/api/sandbox/qris/history*' => Http::response([
                'status' => true,
                'data' => [
                    'current_page' => 2,
                    'data' => [[
                        'order_id' => 'INV-245-1',
                        'created_at' => '2026-08-14 10:15:00',
                        'total_amount' => '125000',
                        'status' => 'success',
                        'type' => 'qris',
                        'keterangan' => 'Payment INV-245-1',
                        'via' => 'mobile',
                    ]],
                    'per_page' => 1,
                    'total' => 3,
                    'last_page' => 3,
                    'first_page_url' => 'https://klikqris.com/api/sandbox/qris/history?page=1',
                    'last_page_url' => 'https://klikqris.com/api/sandbox/qris/history?page=3',
                    'next_page_url' => 'https://evil.example/history?page=3',
                    'prev_page_url' => 'https://klikqris.com/api/sandbox/qris/history?page=1',
                ],
            ], 200),
        ]);
        $page = app(KlikQrisClient::class)->historyPage(2);
        $item = $page->items()[0];

        $this->assertSame(2, $page->currentPage());
        $this->assertSame(1, $page->perPage());
        $this->assertSame(3, $page->total());
        $this->assertSame(3, $page->lastPage());
        $this->assertSame('INV-245-1', $item->orderId());
        $this->assertSame(125000, $item->totalAmount());
        $this->assertSame('SUCCESS', $item->status());
        $this->assertSame('qris', $item->type());
        $this->assertSame('Payment INV-245-1', $item->keterangan());
        $this->assertSame('mobile', $item->via());
        $this->assertSame('Asia/Jakarta', $item->createdAt()->getTimezone()->getName());

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://klikqris.com/api/sandbox/qris/history?page=2'
            && $request->hasHeader('x-api-key', 'test-only-key')
            && $request->hasHeader('id_merchant', 'merchant-test'));
    }

    public function test_withdrawal_query_is_sent_as_validated_array_and_parses_nullable_proof(): void
    {
        Http::fake(fn () => Http::response([
            'status' => true,
            'data' => [
                'current_page' => 3,
                'data' => [[
                    'order_id' => 'INV-245-1',
                    'original_amount' => '100000',
                    'amount_paid_uniq' => 100016,
                    'mdr' => '700',
                    'net_amount_settled' => '99316',
                    'withdrawal_status' => 'success',
                    'transaction_paid_at' => '2026-08-14 03:15:00',
                    'withdrawal_requested_at' => '2026-08-14 11:20:00',
                    'withdrawal_processed_at' => null,
                    'withdrawal_code' => 'WD-245-1',
                    'withdrawal_proof_url' => 'https://klikqris.com/proofs/WD-245-1.png',
                ]],
                'per_page' => 1,
                'total' => 4,
                'last_page' => 4,
                'first_page_url' => 'https://klikqris.com/api/sandbox/report?page=1',
                'last_page_url' => 'https://klikqris.com/api/sandbox/report?page=4',
                'next_page_url' => 'https://klikqris.com/api/sandbox/report?page=4',
                'prev_page_url' => 'https://klikqris.com/api/sandbox/report?page=2',
            ],
        ], 200));

        $query = new KlikQrisWithdrawalQuery(
            status: 'SUCCESS',
            orderId: ' INV/245-1 ',
            startDate: '2026-08-01',
            endDate: '2026-08-14',
            limit: 25,
            page: 3,
        );
        $page = app(KlikQrisClient::class)->withdrawalOrdersPage($query);
        $item = $page->items()[0];

        $this->assertSame(3, $page->currentPage());
        $this->assertSame(4, $page->total());
        $this->assertSame('INV-245-1', $item->orderId());
        $this->assertSame(100000, $item->originalAmount());
        $this->assertSame(100016, $item->amountPaidUniq());
        $this->assertSame(700, $item->mdr());
        $this->assertSame(99316, $item->netAmountSettled());
        $this->assertSame('SUCCESS', $item->withdrawalStatus());
        $this->assertSame('WD-245-1', $item->withdrawalCode());
        $this->assertSame('https://klikqris.com/proofs/WD-245-1.png', $item->withdrawalProofUrl());
        $this->assertSame('Asia/Jakarta', $item->transactionPaidAt()?->getTimezone()->getName());
        $this->assertNull($item->withdrawalProcessedAt());

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://klikqris.com/api/sandbox/withdraw/orders?status=success&order_id=INV%2F245-1&start_date=2026-08-01&end_date=2026-08-14&limit=25&page=3'
            && $request->hasHeader('x-api-key', 'test-only-key')
            && $request->hasHeader('id_merchant', 'merchant-test'));
    }

    public function test_omitted_withdrawal_filters_are_not_sent(): void
    {
        Http::fake(fn () => Http::response([
            'status' => true,
            'data' => [
                'current_page' => 1,
                'data' => [],
                'per_page' => 100,
                'total' => 0,
                'last_page' => 1,
                'first_page_url' => 'https://klikqris.com/api/sandbox/report?page=1',
                'last_page_url' => 'https://klikqris.com/api/sandbox/report?page=1',
                'next_page_url' => null,
                'prev_page_url' => null,
            ],
        ], 200));

        app(KlikQrisClient::class)->withdrawalOrdersPage(new KlikQrisWithdrawalQuery);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://klikqris.com/api/sandbox/withdraw/orders?page=1');
    }

    public function test_reporting_retries_transient_http_and_connection_failures(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts): mixed {
            $attempts++;
            if ($attempts === 1) {
                return Http::response(['provider_secret' => 'never-returned'], 503);
            }
            if ($attempts === 2) {
                throw new ConnectionException('provider_secret connection details');
            }

            return Http::response([
                'status' => true,
                'data' => [
                    'current_page' => 1,
                    'data' => [],
                    'per_page' => 100,
                    'total' => 0,
                    'last_page' => 1,
                    'first_page_url' => 'https://klikqris.com/api/sandbox/report?page=1',
                    'last_page_url' => 'https://klikqris.com/api/sandbox/report?page=1',
                    'next_page_url' => null,
                    'prev_page_url' => null,
                ],
            ], 200);
        });

        $page = app(KlikQrisClient::class)->historyPage();

        $this->assertSame(0, $page->total());
        $this->assertSame(3, $attempts);
    }

    public function test_non_retryable_http_failure_is_safe_and_sent_once(): void
    {
        Http::fake(fn () => Http::response(['secret_body' => 'do-not-expose'], 422));

        try {
            app(KlikQrisClient::class)->historyPage();
            $this->fail('Expected a safe reporting HTTP failure.');
        } catch (KlikQrisReportingException $exception) {
            $this->assertSame('http', $exception->reason);
            $this->assertSame(422, $exception->status);
            $this->assertFalse($exception->retryable);
            $this->assertStringNotContainsString('secret_body', $exception->getMessage());
            $this->assertStringNotContainsString('do-not-expose', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_invalid_json_and_schema_fail_closed_without_provider_details(): void
    {
        Http::fakeSequence()
            ->push('secret-response-body', 200)
            ->push([
                'status' => false,
                'data' => ['provider_secret' => 'hidden'],
            ], 200);

        try {
            app(KlikQrisClient::class)->historyPage();
            $this->fail('Expected a safe reporting JSON failure.');
        } catch (KlikQrisReportingException $exception) {
            $this->assertSame('json', $exception->reason);
            $this->assertStringNotContainsString('secret-response-body', $exception->getMessage());
        }

        try {
            app(KlikQrisClient::class)->historyPage();
            $this->fail('Expected a safe reporting schema failure.');
        } catch (KlikQrisReportingException $exception) {
            $this->assertSame('schema', $exception->reason);
            $this->assertStringNotContainsString('hidden', $exception->getMessage());
        }
    }

    public function test_malformed_history_amount_fails_closed(): void
    {
        Http::fake(fn () => Http::response([
            'status' => true,
            'data' => [
                'current_page' => 1,
                'data' => [[
                    'order_id' => 'INV-245-1',
                    'created_at' => '2026-08-14 10:15:00',
                    'total_amount' => '100.5',
                    'status' => 'SUCCESS',
                ]],
                'per_page' => 1,
                'total' => 1,
                'last_page' => 1,
                'first_page_url' => 'https://klikqris.com/api/sandbox/report?page=1',
                'last_page_url' => 'https://klikqris.com/api/sandbox/report?page=1',
                'next_page_url' => null,
                'prev_page_url' => null,
            ],
        ], 200));

        $this->expectException(KlikQrisReportingException::class);
        $this->expectExceptionMessage('invalid response schema');
        app(KlikQrisClient::class)->historyPage();
    }

    public function test_non_scalar_optional_history_field_fails_closed(): void
    {
        Http::fake(fn () => Http::response([
            'status' => true,
            'data' => [
                'current_page' => 1,
                'data' => [[
                    'order_id' => 'INV-245-3',
                    'created_at' => '2026-08-14 10:15:00',
                    'total_amount' => 100000,
                    'status' => 'SUCCESS',
                    'type' => [],
                ]],
                'per_page' => 1,
                'total' => 1,
                'last_page' => 1,
                'first_page_url' => 'https://klikqris.com/api/sandbox/report?page=1',
                'last_page_url' => 'https://klikqris.com/api/sandbox/report?page=1',
                'next_page_url' => null,
                'prev_page_url' => null,
            ],
        ], 200));

        $this->expectException(KlikQrisReportingException::class);
        $this->expectExceptionMessage('invalid response schema');
        app(KlikQrisClient::class)->historyPage();
    }

    public function test_withdrawal_query_rejects_unsupported_filters(): void
    {
        config()->set('services.klikqris.reporting_max_limit', 25);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('withdrawal query status is invalid');

        new KlikQrisWithdrawalQuery(status: 'failed', limit: 26);
    }

    public function test_invalid_utf8_query_is_reported_as_safe_validation_failure(): void
    {
        $client = app(KlikQrisClient::class);

        try {
            $client->withdrawalOrdersPage(new KlikQrisWithdrawalQuery(orderId: "\xB1\x31"));
            $this->fail('Expected a safe reporting validation failure.');
        } catch (KlikQrisReportingException $exception) {
            $this->assertSame('validation', $exception->reason);
            $this->assertStringNotContainsString('JsonException', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_untrusted_withdrawal_proof_is_preserved_as_null(): void
    {
        Http::fake(fn () => Http::response([
            'status' => true,
            'data' => [
                'current_page' => 1,
                'data' => [[
                    'order_id' => 'INV-245-2',
                    'original_amount' => 100000,
                    'amount_paid_uniq' => 100001,
                    'mdr' => 700,
                    'net_amount_settled' => 99301,
                    'withdrawal_status' => 'pending',
                    'transaction_paid_at' => null,
                    'withdrawal_requested_at' => null,
                    'withdrawal_processed_at' => null,
                    'withdrawal_proof_url' => 'https://klikqris.com/proof.png?token=secret',
                ]],
                'per_page' => 1,
                'total' => 1,
                'last_page' => 1,
                'first_page_url' => 'https://klikqris.com/api/sandbox/report?page=1',
                'last_page_url' => 'https://klikqris.com/api/sandbox/report?page=1',
                'next_page_url' => null,
                'prev_page_url' => null,
            ],
        ], 200));

        $item = app(KlikQrisClient::class)
            ->withdrawalOrdersPage(new KlikQrisWithdrawalQuery)
            ->items()[0];

        $this->assertNull($item->withdrawalProofUrl());
    }

    public function test_reporting_cache_key_varies_by_environment_and_query_without_credentials(): void
    {
        config()->set('services.klikqris.reporting_cache_seconds', 30);
        Http::fake(fn () => Http::response([
            'status' => true,
            'data' => [
                'current_page' => 1,
                'data' => [],
                'per_page' => 100,
                'total' => 0,
                'last_page' => 1,
                'first_page_url' => 'https://klikqris.com/api/sandbox/report?page=1',
                'last_page_url' => 'https://klikqris.com/api/sandbox/report?page=1',
                'next_page_url' => null,
                'prev_page_url' => null,
            ],
        ], 200));

        $client = app(KlikQrisClient::class);
        $client->historyPage(1);
        config()->set('services.klikqris.sandbox_api_key', 'changed-secret');
        $client->historyPage(1);
        $client->historyPage(2);

        Http::assertSentCount(2);
    }

    public function test_readiness_failure_is_safe_and_prevents_requests(): void
    {
        config()->set('services.klikqris.sandbox_api_key', null);
        Http::fake();

        try {
            app(KlikQrisClient::class)->historyPage();
            $this->fail('Expected a safe reporting readiness failure.');
        } catch (KlikQrisReportingException $exception) {
            $this->assertSame('readiness', $exception->reason);
            $this->assertStringNotContainsString('test-only-key', $exception->getMessage());
            $this->assertStringNotContainsString('merchant-test', $exception->getMessage());
        }

        Http::assertNothingSent();
    }
}
