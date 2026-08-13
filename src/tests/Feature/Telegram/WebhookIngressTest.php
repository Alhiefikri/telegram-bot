<?php

namespace Tests\Feature\Telegram;

use App\Jobs\ProcessTelegramUpdate;
use App\Models\Customer;
use App\Models\Order;
use App\Models\TelegramUpdate;
use App\Services\Telegram\TelegramUpdateProcessor;
use App\Support\DogfoodTiming;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\TelegramBotTestSupport;

class WebhookIngressTest extends TelegramBotTestSupport
{
    public function test_webhook_secret_stores_update_and_dispatches_job(): void
    {
        Bus::fake();

        $this->postTelegramWebhook($this->messageUpdate(1, '/start'))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(2, '/start'), 'bad')->assertForbidden();

        $this->assertSame(1, TelegramUpdate::count());
        Bus::assertDispatched(ProcessTelegramUpdate::class);
    }

    public function test_supported_callback_update_is_accepted_and_dispatches_job(): void
    {
        Bus::fake();

        $this->postTelegramWebhook($this->callbackUpdate(302, 'callback-302', 'katalog'))->assertOk();

        $this->assertSame(1, TelegramUpdate::count());
        Bus::assertDispatched(ProcessTelegramUpdate::class);
    }

    public function test_callback_webhook_uses_telegram_response_method_for_fast_ack(): void
    {
        config()->set('services.telegram.webhook_fast_callback_ack', true);
        Bus::fake();

        $response = $this->postTelegramWebhook($this->callbackUpdate(303, 'callback-fast-ack', 'katalog'));

        $response->assertOk()->assertExactJson([
            'method' => 'answerCallbackQuery',
            'callback_query_id' => 'callback-fast-ack',
        ]);
        $this->assertTrue(TelegramUpdate::firstOrFail()->payload['callback_query']['_acknowledged_via_webhook']);
    }

    public function test_worker_skips_duplicate_callback_ack_after_webhook_response_ack(): void
    {
        config()->set('services.telegram.webhook_fast_callback_ack', true);
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->callbackUpdate(304, 'callback-worker-ack', 'katalog'))->assertOk();

        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/answerCallbackQuery'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage'));
    }

    public function test_webhook_persists_minimized_message_payload(): void
    {
        Bus::fake();
        $payload = $this->messageUpdate(303, '/start', ['username' => 'tester']);
        $payload['message']['contact'] = ['phone_number' => '08123456789'];
        $payload['message']['location'] = ['latitude' => -6.2, 'longitude' => 106.8];

        $this->postTelegramWebhook($payload)->assertOk();

        $stored = TelegramUpdate::firstOrFail()->payload;
        $this->assertSame('/start', $stored['message']['text']);
        $this->assertSame('tester', $stored['message']['from']['username']);
        $this->assertArrayNotHasKey('contact', $stored['message']);
        $this->assertArrayNotHasKey('location', $stored['message']);
        $this->assertStringNotContainsString('08123456789', json_encode($stored, JSON_THROW_ON_ERROR));
    }

    public function test_malformed_message_updates_are_rejected_before_persistence_or_dispatch(): void
    {
        Bus::fake();

        $missingFromId = $this->messageUpdate(401, '/start');
        unset($missingFromId['message']['from']['id']);

        $missingChatId = $this->messageUpdate(402, '/start');
        unset($missingChatId['message']['chat']['id']);

        foreach ([
            'missing message.from.id' => $missingFromId,
            'missing message.chat.id' => $missingChatId,
        ] as $case => $payload) {
            $this->assertMalformedTelegramPayloadRejected($payload, $case);
        }
    }

    public function test_malformed_callback_updates_are_rejected_before_persistence_or_dispatch(): void
    {
        Bus::fake();

        $missingFromId = $this->callbackUpdate(501, 'callback-501', 'katalog');
        unset($missingFromId['callback_query']['from']['id']);

        $missingMessageChatId = $this->callbackUpdate(502, 'callback-502', 'katalog');
        unset($missingMessageChatId['callback_query']['message']['chat']['id']);

        $missingCallbackId = $this->callbackUpdate(503, 'callback-503', 'katalog');
        unset($missingCallbackId['callback_query']['id']);

        $missingCallbackData = $this->callbackUpdate(504, 'callback-504', 'katalog');
        unset($missingCallbackData['callback_query']['data']);

        foreach ([
            'missing callback_query.from.id' => $missingFromId,
            'missing callback_query.message.chat.id' => $missingMessageChatId,
            'missing callback_query.id' => $missingCallbackId,
            'missing callback_query.data' => $missingCallbackData,
        ] as $case => $payload) {
            $this->assertMalformedTelegramPayloadRejected($payload, $case);
        }
    }

    public function test_missing_webhook_secret_outside_local_or_testing_returns_configured_error(): void
    {
        $originalEnvironment = app()->environment();
        $originalConfigEnvironment = config('app.env');

        try {
            config()->set('services.telegram.webhook_secret', '');
            config()->set('app.env', 'production');
            app()->detectEnvironment(fn () => 'production');

            $this->postTelegramWebhook($this->messageUpdate(301, '/start'), '')
                ->assertStatus(500)
                ->assertExactJson(['message' => 'Telegram webhook secret is not configured.']);
        } finally {
            config()->set('app.env', $originalConfigEnvironment);
            app()->detectEnvironment(fn () => $originalEnvironment);
        }
    }

    public function test_duplicate_unprocessed_webhook_post_is_redispatched_and_invalid_payload_is_rejected(): void
    {
        Bus::fake();
        $payload = $this->messageUpdate(101, '/start');

        $this->postTelegramWebhook($payload)->assertOk();
        $this->postTelegramWebhook($payload)->assertOk();
        $this->postTelegramWebhook(['update_id' => 102, 'edited_message' => []])->assertUnprocessable();

        $this->assertSame(1, TelegramUpdate::count());
        Bus::assertDispatchedTimes(ProcessTelegramUpdate::class, 2);
    }

    public function test_duplicate_update_is_not_processed_twice(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);

        $payload = $this->messageUpdate(10, '/start');
        $this->postTelegramWebhook($payload)->assertOk();
        $processedAt = TelegramUpdate::first()->processed_at;
        $this->postTelegramWebhook($payload)->assertOk();

        $this->assertNotNull($processedAt);
        $this->assertEquals($processedAt, TelegramUpdate::first()->processed_at);
        Http::assertSentCount(2);
    }

    #[Group('postgres-release')]
    public function test_duplicate_direct_job_does_not_send_twice(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $update = TelegramUpdate::create(['update_id' => 103, 'payload' => $this->messageUpdate(103, '/start'), 'created_at' => now()]);

        (new ProcessTelegramUpdate($update->id))->handle(app(TelegramUpdateProcessor::class));
        $processedAt = $update->fresh()->processed_at;
        (new ProcessTelegramUpdate($update->id))->handle(app(TelegramUpdateProcessor::class));

        $this->assertNotNull($processedAt);
        $this->assertEquals($processedAt, $update->fresh()->processed_at);
        Http::assertSentCount(2);
    }

    public function test_recovery_command_redispatches_only_unprocessed_stored_updates(): void
    {
        Bus::fake();
        $unprocessed = TelegramUpdate::create(['update_id' => 201, 'payload' => $this->messageUpdate(201, '/start'), 'created_at' => now()]);
        TelegramUpdate::create(['update_id' => 202, 'payload' => $this->messageUpdate(202, '/start'), 'processed_at' => now(), 'created_at' => now()]);

        $this->artisan('telegram:recover-updates', ['--chunk' => 1, '--queue' => 'default'])
            ->expectsOutputToContain('telegram:recover-updates completed cleanly: dispatched=1')
            ->assertSuccessful();

        Bus::assertDispatched(ProcessTelegramUpdate::class, fn (ProcessTelegramUpdate $job): bool => $job->telegramUpdateId === $unprocessed->id && $job->queue === 'default');
        $this->assertNotNull($unprocessed->fresh()->next_retry_at);
    }

    public function test_recovery_command_skips_terminal_and_not_yet_due_updates(): void
    {
        Bus::fake();
        $due = TelegramUpdate::create([
            'update_id' => 211,
            'payload' => $this->messageUpdate(211, '/start'),
            'next_retry_at' => now()->subSecond(),
            'created_at' => now(),
        ]);
        $notDue = TelegramUpdate::create([
            'update_id' => 212,
            'payload' => $this->messageUpdate(212, '/start'),
            'next_retry_at' => now()->addHour(),
            'created_at' => now(),
        ]);
        $terminal = TelegramUpdate::create([
            'update_id' => 213,
            'payload' => $this->messageUpdate(213, '/start'),
            'terminal_failed_at' => now(),
            'created_at' => now(),
        ]);

        $this->artisan('telegram:recover-updates', ['--queue' => 'default'])
            ->expectsOutputToContain('telegram:recover-updates completed cleanly: dispatched=1')
            ->assertSuccessful();

        Bus::assertDispatched(ProcessTelegramUpdate::class, fn (ProcessTelegramUpdate $job): bool => $job->telegramUpdateId === $due->id);
        Bus::assertNotDispatched(ProcessTelegramUpdate::class, fn (ProcessTelegramUpdate $job): bool => in_array($job->telegramUpdateId, [$notDue->id, $terminal->id], true));
    }

    public function test_repeated_processing_failure_becomes_terminal_and_is_not_recovered_again(): void
    {
        config()->set('services.telegram.processing_max_attempts', 3);
        config()->set('services.telegram.processing_retry_delay_seconds', 60);
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'blocked'], 403),
        ]);
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $update = TelegramUpdate::create([
            'update_id' => 214,
            'payload' => $this->messageUpdate(214, '/start'),
            'created_at' => now(),
        ]);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                (new ProcessTelegramUpdate($update->id))->handle(app(TelegramUpdateProcessor::class));
                if ($attempt < 3) {
                    $this->fail("Attempt {$attempt} should fail.");
                }
            } catch (RequestException) {
                $this->assertLessThan(3, $attempt, 'The terminal attempt must be quarantined instead of entering failed_jobs.');
                // The replay guard records the failure before rethrowing it.
            }

            $update->refresh();
            $this->assertSame($attempt, $update->processing_attempts);
        }

        $this->assertNotNull($update->terminal_failed_at);
        $this->assertNull($update->next_retry_at);
        $this->assertSame('RequestException', $update->last_error_class);

        Bus::fake();
        $this->artisan('telegram:recover-updates')
            ->expectsOutputToContain('telegram:recover-updates completed cleanly: dispatched=0')
            ->assertSuccessful();
        Bus::assertNotDispatched(ProcessTelegramUpdate::class);
    }

    public function test_recovery_command_is_scheduled(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('telegram:recover-updates --queue=default')
            ->assertSuccessful();
    }

    public function test_failed_telegram_update_recovery_reprocesses_only_the_stored_update_and_removes_its_failed_record(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $update = TelegramUpdate::create([
            'update_id' => 204,
            'payload' => $this->messageUpdate(204, '/start'),
            'created_at' => now(),
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => ProcessTelegramUpdate::class,
                'data' => ['command' => serialize(new ProcessTelegramUpdate($update->id))],
            ], JSON_THROW_ON_ERROR),
            'exception' => 'temporary Telegram failure',
            'failed_at' => now(),
        ]);

        $this->artisan('telegram:recover-failed-updates')
            ->expectsOutputToContain('telegram:recover-failed-updates completed: supported=1 retryable=1 recovered=1 stale=0 terminal=0 failed_pruned=0 unsupported=0 failed=0')
            ->assertSuccessful();

        $this->assertNotNull($update->fresh()->processed_at);
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_failed_telegram_update_recovery_prunes_stale_jobs_without_replaying_deleted_updates(): void
    {
        $this->fakeTelegramHttp();

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => ProcessTelegramUpdate::class,
                'data' => ['command' => serialize(new ProcessTelegramUpdate(999999))],
            ], JSON_THROW_ON_ERROR),
            'exception' => 'stale Telegram failure',
            'failed_at' => now(),
        ]);

        $this->artisan('telegram:recover-failed-updates')
            ->expectsOutputToContain('telegram:recover-failed-updates completed: supported=1 retryable=0 recovered=0 stale=1 terminal=0 failed_pruned=0 unsupported=0 failed=0')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_failed_telegram_update_recovery_dry_run_reports_unsupported_jobs_without_mutating_them(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'UnsupportedJob', 'data' => []], JSON_THROW_ON_ERROR),
            'exception' => 'unsupported job failure',
            'failed_at' => now(),
        ]);

        $this->artisan('telegram:recover-failed-updates --dry-run')
            ->expectsOutputToContain('telegram:recover-failed-updates dry-run: supported=0 retryable=0 recovered=0 stale=0 terminal=0 failed_pruned=0 unsupported=1 failed=0')
            ->assertSuccessful();

        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    public function test_failed_telegram_update_recovery_removes_terminal_jobs_without_replaying_them(): void
    {
        $update = TelegramUpdate::create([
            'update_id' => 206,
            'payload' => $this->messageUpdate(206, '/start'),
            'terminal_failed_at' => now(),
            'created_at' => now(),
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => ProcessTelegramUpdate::class,
                'data' => ['command' => serialize(new ProcessTelegramUpdate($update->id))],
            ], JSON_THROW_ON_ERROR),
            'exception' => 'terminal Telegram failure',
            'failed_at' => now(),
        ]);

        $this->artisan('telegram:recover-failed-updates')
            ->expectsOutputToContain('telegram:recover-failed-updates completed: supported=1 retryable=0 recovered=0 stale=0 terminal=1 failed_pruned=0 unsupported=0 failed=0')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertNotNull($update->fresh()->terminal_failed_at);
        $this->assertNull($update->fresh()->processed_at);
    }

    public function test_failed_telegram_update_recovery_can_prune_unrecoverable_jobs_after_retry_failure(): void
    {
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'temporary Telegram failure'], 500),
        ]);

        $update = TelegramUpdate::create([
            'update_id' => 205,
            'payload' => $this->messageUpdate(205, '/start'),
            'created_at' => now(),
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => ProcessTelegramUpdate::class,
                'data' => ['command' => serialize(new ProcessTelegramUpdate($update->id))],
            ], JSON_THROW_ON_ERROR),
            'exception' => 'temporary Telegram failure',
            'failed_at' => now(),
        ]);

        $this->artisan('telegram:recover-failed-updates --prune-unrecoverable')
            ->expectsOutputToContain('telegram:recover-failed-updates completed: supported=1 retryable=1 recovered=0 stale=0 terminal=0 failed_pruned=1 unsupported=0 failed=0')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertNull($update->fresh()->processed_at);
        $this->assertNotNull($update->fresh()->terminal_failed_at);
    }

    public function test_telegram_webhook_is_rate_limited_by_user(): void
    {
        config()->set('services.telegram.webhook_rate_limit_per_minute', 1);
        Bus::fake();

        $this->postTelegramWebhook($this->messageUpdate(120, '/start'))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(121, '/start'))->assertTooManyRequests();
    }

    public function test_bad_secret_webhook_requests_from_same_ip_share_rate_limit_before_payload_identity(): void
    {
        config()->set('services.telegram.webhook_rate_limit_per_minute', 1);
        Bus::fake();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.113']);

        $this->postTelegramWebhook(
            $this->messageUpdate(113001, '/start', ['id' => 9001], 9101),
            'bad-secret-one'
        )->assertForbidden();
        $this->postTelegramWebhook(
            $this->messageUpdate(113002, '/start', ['id' => 9002], 9102),
            'bad-secret-two'
        )->assertTooManyRequests();

        $this->assertSame(0, TelegramUpdate::count());
        Bus::assertNotDispatched(ProcessTelegramUpdate::class);
    }

    public function test_bad_secret_webhook_rate_limit_does_not_cross_source_ips(): void
    {
        config()->set('services.telegram.webhook_rate_limit_per_minute', 1);
        Bus::fake();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.113'])
            ->postTelegramWebhook($this->messageUpdate(113101, '/start', ['id' => 9001], 9101), 'bad-secret-one')
            ->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.114'])
            ->postTelegramWebhook($this->messageUpdate(113102, '/start', ['id' => 9002], 9102), 'bad-secret-two')
            ->assertForbidden();

        $this->assertSame(0, TelegramUpdate::count());
        Bus::assertNotDispatched(ProcessTelegramUpdate::class);
    }

    public function test_dogfood_timing_is_disabled_by_default(): void
    {
        Log::spy();
        Bus::fake();

        $this->postTelegramWebhook($this->messageUpdate(130, '/start'))->assertOk();

        Log::shouldNotHaveReceived('info');
    }

    public function test_dogfood_timing_can_use_a_configured_non_file_log_channel(): void
    {
        config()->set('services.dogfood_timing.enabled', true);
        config()->set('services.dogfood_timing.log_channel', 'stderr');
        $logger = Mockery::mock();

        Log::shouldReceive('channel')->once()->with('stderr')->andReturn($logger);
        $logger->shouldReceive('info')->once()->with('dogfood.test_event', ['status' => 'ok']);
        Log::shouldReceive('info')->never();

        DogfoodTiming::log('dogfood.test_event', ['status' => 'ok']);
    }

    public function test_enabled_dogfood_timing_logs_safe_webhook_job_and_outbound_context(): void
    {
        config()->set('services.dogfood_timing.enabled', true);
        $logs = [];
        Log::shouldReceive('info')->zeroOrMoreTimes()->withArgs(function (string $event, array $context = []) use (&$logs) {
            $logs[] = [$event, $context];

            return true;
        });
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(131, '/start', from: ['id' => 987654], chatId: 123456))->assertOk();

        $this->assertLog($logs, 'dogfood.telegram.webhook_ack', fn (array $context) => ! array_key_exists('update_id', $context)
            && $context['update_correlation_id'] === DogfoodTiming::correlation(131)
            && array_key_exists('ack_duration_ms', $context)
            && array_key_exists('job_enqueued_at', $context)
            && $context['duplicate'] === false
        );
        $this->assertLog($logs, 'dogfood.telegram.job_picked_up', fn (array $context) => array_key_exists('job_picked_up_at', $context)
            && array_key_exists('job_enqueued_at', $context)
        );
        $this->assertLog($logs, 'dogfood.telegram.handler_finished', fn (array $context) => array_key_exists('handler_duration_ms', $context)
            && array_key_exists('db_query_count', $context)
            && array_key_exists('memory_before_bytes', $context)
            && array_key_exists('memory_after_bytes', $context)
        );
        $this->assertLog($logs, 'dogfood.outbound_http', fn (array $context) => $context['provider'] === 'telegram'
            && $context['operation'] === 'sendMessage'
            && array_key_exists('status', $context)
            && array_key_exists('duration_ms', $context)
        );

        $joined = json_encode($logs, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('123456', $joined);
        $this->assertStringNotContainsString('987654', $joined);
        $this->assertStringNotContainsString('bot-token', $joined);
        $this->assertStringNotContainsString('pakasir-key', $joined);
        $this->assertStringNotContainsString('/start', $joined);
    }

    public function test_start_welcome_uses_wib_time_and_sends_banner_before_message(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-04 14:15:00', 'UTC'));
        config()->set('services.telegram.welcome_banner_url', 'telegram-banner-file-id');
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(210, '/start', from: ['first_name' => 'Fikri']))->assertOk();

        $recorded = Http::recorded();
        $welcomePhotoIndex = $recorded->search(fn (array $record) => str_ends_with($record[0]->url(), '/sendPhoto')
            && $record[0]->data()['photo'] === 'telegram-banner-file-id');
        $welcomeMessageIndex = $recorded->search(fn (array $record) => str_ends_with($record[0]->url(), '/sendPhoto')
            && str_contains((string) ($record[0]->data()['caption'] ?? ''), '🌙 Selamat malam, Fikri'));

        $this->assertIsInt($welcomePhotoIndex);
        $this->assertIsInt($welcomeMessageIndex);
        $this->assertSame($welcomePhotoIndex, $welcomeMessageIndex);
    }

    public function test_start_welcome_uses_local_banner_when_banner_url_is_not_configured(): void
    {
        Carbon::setTestNow('2026-07-04 09:00:00');
        config()->set('services.telegram.welcome_banner_url', null);
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(211, '/start', from: ['first_name' => 'Fikri']))->assertOk();

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto'));
    }

    private function assertMalformedTelegramPayloadRejected(array $payload, string $case): void
    {
        $this->postTelegramWebhook($payload)
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'Unsupported update type']);

        $this->assertSame(0, Customer::count(), "{$case} created a customer");
        $this->assertSame(0, TelegramUpdate::count(), "{$case} stored a Telegram update");
        Bus::assertNotDispatched(ProcessTelegramUpdate::class);
        $this->assertSame(0, Order::count(), "{$case} created an order");
    }

    private function assertLog(array $logs, string $event, callable $predicate): void
    {
        foreach ($logs as [$loggedEvent, $context]) {
            if ($loggedEvent === $event && $predicate($context)) {
                $this->assertTrue(true);

                return;
            }
        }

        $this->fail("Expected log [{$event}] was not emitted with matching context.");
    }
}
