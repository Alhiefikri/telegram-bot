<?php

namespace Tests\Feature;

use App\Enums\SakurupiahEnvironment;
use App\Services\Admin\SakurupiahChannelSettings;
use App\Services\ProviderVerification\ProviderVerificationBudgetExhausted;
use App\Services\Sakurupiah\SakurupiahApplicationException;
use App\Services\Sakurupiah\SakurupiahClient;
use App\Services\Sakurupiah\SakurupiahCreateInvoice;
use App\Services\Sakurupiah\SakurupiahCreatePreRequestException;
use App\Services\Sakurupiah\SakurupiahHistoryFilter;
use App\Services\Sakurupiah\SakurupiahHttpException;
use App\Services\Sakurupiah\SakurupiahJsonException;
use App\Services\Sakurupiah\SakurupiahProviderAdapter;
use App\Services\Sakurupiah\SakurupiahProviderException;
use App\Services\Sakurupiah\SakurupiahProviderReadiness;
use App\Services\Sakurupiah\SakurupiahReadinessException;
use App\Services\Sakurupiah\SakurupiahRequestBackpressure;
use App\Services\Sakurupiah\SakurupiahSchemaException;
use App\Services\Sakurupiah\SakurupiahTransportException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SakurupiahGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.sakurupiah', array_replace(config('services.sakurupiah', []), [
            'sandbox_enabled' => true,
            'sandbox_base_url' => 'https://sakurupiah.id/api-sanbox/',
            'sandbox_api_id' => 'api-test',
            'sandbox_api_key' => 'secret-key',
            'http_timeout_seconds' => 4.5,
            'http_connect_timeout_seconds' => 1.5,
            'lookup_max_attempts' => 3,
            'lookup_retry_delay_ms' => 0,
            'lookup_retry_max_delay_ms' => 0,
        ]));
    }

    public function test_rate_rejected_polling_defers_other_calls_and_checkout_without_dispatch_until_cooldown_expires(): void
    {
        Http::fakeSequence()
            ->push(['status' => '400', 'message' => 'Permintaan terlalu cepat silahkan coba lagi nanti.'], 400)
            ->push(['status' => '200', 'data' => [['status' => 'pending']]], 200);

        try {
            app(SakurupiahProviderAdapter::class)->transactionStatus('old-invoice');
            $this->fail('Expected provider throttle.');
        } catch (SakurupiahApplicationException $exception) {
            $this->assertSame('400', $exception->providerStatus);
        }

        foreach (['transactionStatus', 'transactionHistory', 'listChannels'] as $operation) {
            try {
                $client = app(SakurupiahProviderAdapter::class);
                $operation === 'transactionStatus' ? $client->$operation('another-invoice') : $client->$operation();
                $this->fail('Expected merchant cooldown.');
            } catch (SakurupiahProviderException $exception) {
                $this->assertSame('rate_limit_cooldown', $exception->reason);
                $this->assertFalse($exception->isOutcomeUnknown());
                $this->assertFalse($exception->isProviderRejection());
            }
        }
        try {
            app(SakurupiahProviderAdapter::class)->createInvoice($this->invoice());
            $this->fail('Expected create to remain safely undispatched.');
        } catch (SakurupiahCreatePreRequestException) {
        }
        Http::assertSentCount(1);

        $this->travel(61)->seconds();
        $this->assertTrue(app(SakurupiahProviderAdapter::class)->transactionStatus('old-invoice')->isPending());
        Http::assertSentCount(2);
    }

    public function test_throttle_is_shared_only_by_the_same_environment_and_merchant(): void
    {
        Http::fakeSequence()
            ->push(['status' => '400', 'message' => 'Permintaan terlalu cepat silahkan coba lagi nanti.'], 200)
            ->push(['status' => '200', 'data' => [['status' => 'pending']]]);
        try {
            app(SakurupiahProviderAdapter::class)->transactionStatus('throttled-invoice');
            $this->fail('Expected provider throttle.');
        } catch (SakurupiahApplicationException) {
        }
        $this->assertFalse((new SakurupiahRequestBackpressure(SakurupiahEnvironment::Production))->isCoolingDown());
        config()->set('services.sakurupiah.sandbox_api_id', 'another-merchant');
        $this->assertTrue(app(SakurupiahProviderAdapter::class)->transactionStatus('another-invoice')->isPending());
        config()->set('services.sakurupiah.sandbox_api_id', 'api-test');
        try {
            app(SakurupiahProviderAdapter::class)->transactionStatus('throttled-invoice');
            $this->fail('Expected original merchant to remain deferred.');
        } catch (SakurupiahProviderException $exception) {
            $this->assertSame('rate_limit_cooldown', $exception->reason);
        }
        Http::assertSentCount(2);
    }

    public function test_provider_adapter_is_bound_without_activating_checkout(): void
    {
        $this->assertInstanceOf(SakurupiahClient::class, app(SakurupiahProviderAdapter::class));
    }

    public function test_create_posts_exact_sandbox_contract_and_signature_once(): void
    {
        Http::fake(fn () => Http::response([
            'status' => '200',
            'message' => 'success',
            'data' => [[
                'trx_id' => 'trx-408',
                'merchant_ref' => 'INV-408',
                'payment_kode' => 'QRISC',
                'payment_status' => 'pending',
                'amount' => '0012000',
                'total' => '12084',
                'fee' => '84',
                'date' => '2026-09-02',
                'time' => '12:00:00',
                'expired' => '2026-09-03 12:00:00',
            ]],
        ], 200));

        $invoice = $this->invoice();
        $result = app(SakurupiahProviderAdapter::class)->createInvoice($invoice);

        $this->assertSame('trx-408', $result->transactionId());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://sakurupiah.id/api-sanbox/create.php'
            && $request->hasHeader('Authorization', 'Bearer secret-key')
            && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded')
            && $request->hasHeader('Accept', 'application/json')
            && $request['api_id'] === 'api-test'
            && $request['method'] === 'QRISC'
            && $request['phone'] === '08123456789'
            && $request['amount'] === '0012000'
            && $request['merchant_fee'] === 1
            && $request['merchant_ref'] === 'INV-408'
            && $request['callback_url'] === 'https://store.example.test/api/webhook/sakurupiah'
            && $request['return_url'] === 'https://store.example.test/shop/orders/INV-408'
            && $request['signature'] === 'b6bc2c4327846d2cd5c661a7ed9a5c1b2537512837a36bc6b709060776f544c2');
    }

    public function test_list_channels_posts_provider_path_and_returns_typed_metadata(): void
    {
        Http::fake(fn () => Http::response([
            'status' => '200',
            'data' => [[
                'kode' => 'QRISC',
                'nama' => 'QRISC Custom Merchant',
                'minimal' => '200',
                'maksimal' => 20_000_000,
                'biaya' => '0.7%',
                'percent' => '0.7',
                'tipe' => 'DIRECT',
                'status' => 'Aktif',
                'addition' => ['default_expired' => '24'],
            ]],
        ], 200));

        $channels = app(SakurupiahProviderAdapter::class)->listChannels();

        $this->assertCount(1, $channels);
        $this->assertTrue($channels[0]->isQris());
        $this->assertTrue($channels[0]->isActive());
        $this->assertSame(200, $channels[0]->minimumAmount());
        $this->assertSame('0.7%', $channels[0]->fee?->submitted());
        $this->assertSame('0.7', $channels[0]->percent);
        Http::assertSent(fn ($request) => $request->url() === 'https://sakurupiah.id/api-sanbox/list-payment.php'
            && $request['api_id'] === 'api-test'
            && $request['method'] === 'list');
    }

    public function test_fractional_dana_expiry_does_not_block_qris_readiness(): void
    {
        $channel = [
            'kode' => 'QRISC',
            'nama' => 'QRISC Custom Merchant',
            'minimal' => '200',
            'maksimal' => '20000000',
            'tipe' => 'DIRECT',
            'status' => 'Aktif',
            'addition' => ['default_expired' => '24'],
        ];
        Http::fake(fn () => Http::response([
            'status' => '200',
            'data' => [
                array_replace($channel, [
                    'kode' => 'DANA',
                    'nama' => 'DANA',
                    'tipe' => 'REDIRECT',
                    'addition' => ['default_expired' => '0.5'],
                ]),
                $channel,
            ],
        ]));

        $report = app(SakurupiahProviderReadiness::class)->observe(channelOverride: 'QRISC');

        $this->assertTrue($report->isReady(), $report->configurationMessage());
        $this->assertSame('QRISC', $report->qrisChannel()?->code);
        $this->assertSame(24, $report->qrisChannel()?->addition?->defaultExpiredHours());
    }

    public function test_balance_posts_provider_path_and_returns_typed_amount(): void
    {
        Http::fake(fn () => Http::response([
            'status' => '200',
            'data' => ['saldo' => '12345', 'currency' => 'IDR'],
        ], 200));

        $balance = app(SakurupiahProviderAdapter::class)->checkBalance();

        $this->assertSame(12345, $balance->value());
        Http::assertSent(fn ($request) => $request->url() === 'https://sakurupiah.id/api-sanbox/check_balance.php'
            && $request['api_id'] === 'api-test'
            && $request['method'] === 'balance');
    }

    public function test_readiness_failure_precedes_signature_and_http(): void
    {
        config()->set('services.sakurupiah.sandbox_api_key', null);
        Http::fake();

        try {
            app(SakurupiahProviderAdapter::class)->createInvoice($this->invoice());
            $this->fail('Expected readiness failure.');
        } catch (SakurupiahReadinessException $exception) {
            $this->assertStringContainsString('API key', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_request_evidence_records_each_retry_with_sanitized_timing_and_merchant_context(): void
    {
        $records = [];
        Log::listen(function (MessageLogged $event) use (&$records): void {
            if ($event->message === 'sakurupiah.request_attempt') {
                $records[] = $event->context;
            }
        });
        config()->set('app.key', 'evidence-test-key');
        Http::fakeSequence()
            ->push(['status' => '500', 'message' => 'secret-key api-test trx-408'], 503)
            ->push(['status' => '500', 'message' => 'https://private.example/INV-408'], 503)
            ->push(['status' => '200', 'data' => [['status' => 'pending', 'qr' => 'private-qr']]], 200)
            ->push(['status' => '200', 'data' => [['status' => 'pending']]], 200);

        app(SakurupiahProviderAdapter::class)->transactionStatus('trx-408');

        $this->assertCount(3, $records);
        $this->assertSame([1, 2, 3], array_column($records, 'attempt'));
        $this->assertSame(['http_unavailable', 'http_unavailable', 'application_accepted'], array_column($records, 'outcome'));
        $this->assertCount(1, array_unique(array_column($records, 'request_id')));
        foreach ($records as $record) {
            $this->assertSame('unknown', $record['caller']);
            $this->assertSame('transactionStatus', $record['operation']);
            $this->assertSame('sandbox', $record['environment']);
            $this->assertSame(3, $record['max_attempts']);
            $this->assertSame(substr(hash_hmac('sha256', 'sandbox:api-test', 'evidence-test-key'), 0, 16), $record['merchant_fingerprint']);
            $this->assertGreaterThanOrEqual(0, $record['duration_ms']);
            $this->assertLessThanOrEqual($record['finished_at'], $record['started_at']);
            $this->assertEqualsCanonicalizing([
                'request_id', 'environment', 'merchant_fingerprint', 'caller', 'operation',
                'max_attempts', 'attempt', 'started_at', 'finished_at', 'duration_ms',
                'outcome', 'provider_status', 'http_status',
            ], array_keys($record));
        }
        $encoded = json_encode($records, JSON_THROW_ON_ERROR);
        foreach (['secret-key', 'api-test', 'trx-408', 'INV-408', 'private.example', 'private-qr'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $encoded);
        }
        config()->set('services.sakurupiah.sandbox_api_id', 'another-merchant');
        app(SakurupiahProviderAdapter::class)->transactionStatus('trx-408');
        $this->assertNotSame($records[0]['merchant_fingerprint'], $records[3]['merchant_fingerprint']);
    }

    public function test_create_evidence_distinguishes_explicit_rate_rejection_from_other_application_400_without_retries(): void
    {
        $records = [];
        Log::listen(function (MessageLogged $event) use (&$records): void {
            if ($event->message === 'sakurupiah.request_attempt') {
                $records[] = $event->context;
            }
        });
        Http::fakeSequence()
            ->push(['status' => '400', 'message' => 'Permintaan terlalu cepat silahkan coba lagi nanti.'], 200)
            ->push(['status' => '400', 'message' => 'Permintaan terlalu cepat silahkan coba lagi nanti.'], 400)
            ->push(['status' => '400', 'message' => 'Unknown rejection INV-408 08123456789 private-qr https://private.example'], 400);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                app(SakurupiahProviderAdapter::class)->createInvoice($this->invoice());
                $this->fail('Expected explicit application rejection.');
            } catch (SakurupiahApplicationException $exception) {
                $this->assertTrue($exception->isProviderRejection());
            }
            $this->travel(61)->seconds();
        }

        Http::assertSentCount(3);
        $this->assertSame(['application_rate_rejected', 'application_rate_rejected', 'application_rejected_unknown'], array_column($records, 'outcome'));
        $this->assertSame([1, 1, 1], array_column($records, 'attempt'));
        $this->assertSame([1, 1, 1], array_column($records, 'max_attempts'));
        $this->assertSame(['createInvoice', 'createInvoice', 'createInvoice'], array_column($records, 'operation'));
        $encoded = json_encode($records, JSON_THROW_ON_ERROR);
        foreach (['Permintaan', 'INV-408', '08123456789', 'private-qr', 'private.example'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $encoded);
        }
    }

    public function test_lookup_evidence_distinguishes_not_found_unknown_outage_and_budget_exhaustion(): void
    {
        $records = [];
        Log::listen(function (MessageLogged $event) use (&$records): void {
            if ($event->message === 'sakurupiah.request_attempt') {
                $records[] = $event->context;
            }
        });
        config()->set('services.sakurupiah.lookup_max_attempts', 1);
        $responses = [
            Http::response(['status' => '404', 'message' => 'private reference'], 200),
            Http::response('private gateway response', 404),
            Http::response(['status' => '500', 'message' => 'private outage'], 200),
            Http::response(['status' => '400', 'message' => 'unknown rejection'], 200),
            Http::response('not-json', 200),
            Http::response(['unexpected' => 'schema'], 200),
            new ConnectionException('secret-key https://private.example'),
            new ProviderVerificationBudgetExhausted('deadline'),
        ];
        Http::fake(function () use (&$responses) {
            $response = array_shift($responses);
            if ($response instanceof \Throwable) {
                throw $response;
            }

            return $response;
        });
        for ($attempt = 0; $attempt < 8; $attempt++) {
            try {
                app(SakurupiahProviderAdapter::class)->transactionStatus('trx-408');
                $this->fail('Expected lookup failure.');
            } catch (SakurupiahProviderException) {
            }
        }

        $this->assertSame([
            'application_not_found', 'http_not_found', 'application_unavailable',
            'application_rejected_unknown', 'invalid_json', 'invalid_envelope',
            'transport_unknown', 'budget_exhausted',
        ], array_column($records, 'outcome'));
    }

    public function test_channel_and_history_evidence_use_closed_caller_and_operation_labels(): void
    {
        $records = [];
        Log::listen(function (MessageLogged $event) use (&$records): void {
            if ($event->message === 'sakurupiah.request_attempt') {
                $records[] = $event->context;
            }
        });
        Http::fake(fn () => Http::response(['status' => '200', 'data' => []]));

        app(SakurupiahChannelSettings::class)->available();
        app(SakurupiahProviderAdapter::class)->transactionHistory(new SakurupiahHistoryFilter(
            trxId: 'trx-408',
            merchantRef: 'INV-408',
        ));

        $this->assertSame(['admin_channel_settings', 'unknown'], array_column($records, 'caller'));
        $this->assertSame(['listChannels', 'transactionHistory'], array_column($records, 'operation'));
        $this->assertSame(['application_accepted', 'application_accepted'], array_column($records, 'outcome'));
    }

    public function test_status_honors_429_retry_after_without_immediate_retries(): void
    {
        Http::fakeSequence()
            ->push(['status' => '429', 'message' => 'retry'], 429, ['Retry-After' => '120'])
            ->push(['status' => '200', 'data' => [['status' => 'pending']]], 200);

        try {
            app(SakurupiahProviderAdapter::class)->transactionStatus('trx-408');
            $this->fail('Expected HTTP throttle.');
        } catch (SakurupiahHttpException $exception) {
            $this->assertSame(429, $exception->httpStatus);
        }
        $this->travel(61)->seconds();
        try {
            app(SakurupiahProviderAdapter::class)->transactionStatus('trx-408');
            $this->fail('Expected Retry-After cooldown.');
        } catch (SakurupiahProviderException $exception) {
            $this->assertSame('rate_limit_cooldown', $exception->reason);
        }
        Http::assertSentCount(1);
        $this->travel(60)->seconds();
        $this->assertTrue(app(SakurupiahProviderAdapter::class)->transactionStatus('trx-408')->isPending());
        Http::assertSentCount(2);
    }

    public function test_status_retries_5xx(): void
    {
        Http::fakeSequence()
            ->push(['status' => '500', 'message' => 'retry'], 500)
            ->push(['status' => '200', 'data' => [['status' => 'pending']]], 200);

        $status = app(SakurupiahProviderAdapter::class)->transactionStatus('trx-408');

        $this->assertTrue($status->isPending());
        Http::assertSentCount(2);
    }

    public function test_status_does_not_retry_provider_application_rejection(): void
    {
        Http::fake(fn () => Http::response(['status' => '401', 'message' => 'Merchant tidak aktif'], 401));

        try {
            app(SakurupiahProviderAdapter::class)->transactionStatus('trx-408');
            $this->fail('Expected provider application rejection.');
        } catch (SakurupiahApplicationException $exception) {
            $this->assertSame('401', $exception->providerStatus);
            $this->assertSame('Merchant tidak aktif', $exception->providerMessage);
            $this->assertTrue($exception->isProviderRejection());
            $this->assertFalse($exception->isOutcomeUnknown());
        }
        Http::assertSentCount(1);
    }

    public function test_status_does_not_retry_http_failure_without_the_provider_contract(): void
    {
        Http::fake(fn () => Http::response('<html>gateway timeout</html>', 401));

        try {
            app(SakurupiahProviderAdapter::class)->transactionStatus('trx-408');
            $this->fail('Expected HTTP failure.');
        } catch (SakurupiahHttpException $exception) {
            $this->assertSame(401, $exception->httpStatus);
            $this->assertNull($exception->providerStatus);
            $this->assertFalse($exception->isProviderRejection());
        }
        Http::assertSentCount(1);
    }

    public function test_provider_rejection_redacts_known_identifiers(): void
    {
        Http::fake(fn () => Http::response([
            'status' => '400',
            'message' => "Kode ref atau merchant_ref trx-408 sudah ada\nstatus pending",
        ], 400));

        try {
            app(SakurupiahProviderAdapter::class)->transactionStatus('trx-408');
            $this->fail('Expected provider application rejection.');
        } catch (SakurupiahApplicationException $exception) {
            $this->assertSame('Kode ref atau merchant_ref [redacted] sudah ada status pending', $exception->providerMessage);
        }
    }

    public function test_create_transport_failure_is_outcome_unknown_and_never_retried(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('connection failed');
        });

        try {
            app(SakurupiahProviderAdapter::class)->createInvoice($this->invoice());
            $this->fail('Expected create transport failure.');
        } catch (SakurupiahTransportException $exception) {
            $this->assertTrue($exception->isOutcomeUnknown());
        }

        $this->assertSame(1, $attempts);
    }

    public function test_create_http_failure_is_outcome_unknown_and_never_retried(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            return Http::response(['status' => '503'], 503);
        });

        try {
            app(SakurupiahProviderAdapter::class)->createInvoice($this->invoice());
            $this->fail('Expected create HTTP failure.');
        } catch (SakurupiahHttpException $exception) {
            $this->assertTrue($exception->isOutcomeUnknown());
        }

        $this->assertSame(1, $attempts);
    }

    public function test_client_distinguishes_json_application_failure(): void
    {
        Http::fake(fn () => Http::response(['status' => '400', 'message' => 'rejected'], 200));

        $this->expectException(SakurupiahApplicationException::class);
        app(SakurupiahProviderAdapter::class)->transactionStatus('trx-408');
    }

    public function test_client_distinguishes_invalid_json(): void
    {
        Http::fake(fn () => Http::response('not-json', 200));

        $this->expectException(SakurupiahJsonException::class);
        app(SakurupiahProviderAdapter::class)->transactionStatus('trx-408');
    }

    public function test_client_distinguishes_schema_failure(): void
    {
        Http::fake(fn () => Http::response(['status' => '200'], 200));

        $this->expectException(SakurupiahSchemaException::class);
        app(SakurupiahProviderAdapter::class)->transactionStatus('trx-408');
    }

    public function test_client_rejects_overflowing_application_status_without_casting(): void
    {
        Http::fake(fn () => Http::response([
            'status' => '999999999999999999999999999999',
        ], 200));

        $this->expectException(SakurupiahSchemaException::class);
        app(SakurupiahProviderAdapter::class)->transactionStatus('trx-408');
    }

    public function test_client_distinguishes_http_failure(): void
    {
        Http::fake(fn () => Http::response('<html>bad request</html>', 400));

        $this->expectException(SakurupiahHttpException::class);
        app(SakurupiahProviderAdapter::class)->transactionStatus('trx-408');
    }

    public function test_client_reads_the_documented_failure_contract_from_a_4xx_response(): void
    {
        Http::fake(fn () => Http::response([
            'status' => '400',
            'message' => 'Gagal membuat Invoice. hubungi tim dukungan',
        ], 400));

        try {
            app(SakurupiahProviderAdapter::class)->createInvoice($this->invoice());
            $this->fail('Expected provider application rejection.');
        } catch (SakurupiahApplicationException $exception) {
            $this->assertSame('400', $exception->providerStatus);
            $this->assertSame('Gagal membuat Invoice. hubungi tim dukungan', $exception->providerMessage);
            $this->assertFalse($exception->isOutcomeUnknown());
        }
    }

    public function test_history_preserves_provider_mechant_spelling(): void
    {
        Http::fake(fn () => Http::response([
            'status' => '200',
            'data' => [[
                'trx_id' => 'trx-408',
                'merchant_ref' => 'INV-408',
                'payment_kode' => 'QRISC',
                'amount' => '12000',
                'status' => 'pending',
            ]],
        ], 200));

        $items = app(SakurupiahProviderAdapter::class)->transactionHistory(new SakurupiahHistoryFilter(
            mechant: 1,
            trxId: 'trx-408',
            merchantRef: 'INV-408',
        ));

        $this->assertCount(1, $items);
        Http::assertSent(fn ($request) => $request->url() === 'https://sakurupiah.id/api-sanbox/transaction.php'
            && $request['api_id'] === 'api-test'
            && $request['method'] === 'transaction'
            && $request['mechant'] === 1
            && $request['trx_id'] === 'trx-408'
            && $request['merchant_ref'] === 'INV-408'
            && ! array_key_exists('merchant', $request->data()));
    }

    private function invoice(): SakurupiahCreateInvoice
    {
        return new SakurupiahCreateInvoice(
            method: 'QRISC',
            phone: '08123456789',
            amount: '0012000',
            merchantFee: 1,
            merchantRef: 'INV-408',
            callbackUrl: 'https://store.example.test/api/webhook/sakurupiah',
            returnUrl: 'https://store.example.test/shop/orders/INV-408',
        );
    }
}
