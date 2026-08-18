<?php

namespace Tests\Feature;

use App\Enums\TelegramOutboundEffectState;
use App\Enums\UserRole;
use App\Jobs\SendTelegramBroadcastBatch;
use App\Models\Customer;
use App\Models\TelegramBroadcast;
use App\Models\TelegramBroadcastRecipient;
use App\Models\TelegramOutboundEffect;
use App\Models\User;
use App\Services\Telegram\TelegramBroadcastRateLimiter;
use App\Services\Telegram\TelegramBroadcastService;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Contracts\Bus\Dispatcher as DispatcherContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class AdminTelegramBroadcastQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.telegram.broadcast_rate_limit_per_second', 25);
        Cache::flush();
    }

    public function test_admin_broadcast_submission_persists_intent_and_enqueues_without_sending_to_recipients(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        Queue::fake();
        Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true])]);

        Customer::factory()->create(['telegram_id' => 111111111]);
        Customer::factory()->create(['telegram_id' => 222222222]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->post(route('admin.broadcast.store'), ['message' => 'Private launch note'])
            ->assertRedirect('/admin?tab=broadcast#admin-section-broadcast')
            ->assertSessionHas('status', 'Broadcast #1 masuk antrean. Progres bisa dipantau di tab Broadcast.');

        $broadcast = TelegramBroadcast::query()->firstOrFail();
        $this->assertSame('Private launch note', $broadcast->message);
        $this->assertSame(TelegramBroadcast::STATUS_QUEUED, $broadcast->status);
        // Admin Broadcast intentionally defers recipient/effect materialization
        // until its worker, as the ADR-0007 migration exception requires.
        $this->assertSame(0, TelegramBroadcastRecipient::query()->count());
        $this->assertSame(0, TelegramOutboundEffect::query()->count());

        Queue::assertPushedOn('telegram-broadcast', SendTelegramBroadcastBatch::class);
        Http::assertNothingSent();
    }

    public function test_admin_broadcast_delivery_processes_a_bounded_batch_and_schedules_continuation(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        Queue::fake();
        $sentChatIds = [];
        Http::fake(function (HttpRequest $request) use (&$sentChatIds) {
            $sentChatIds[] = (string) $request['chat_id'];

            return Http::response(['ok' => true, 'result' => ['message_id' => count($sentChatIds)]]);
        });
        Customer::factory()->count(50)->sequence(fn ($sequence) => ['telegram_id' => 100000 + $sequence->index])->create();
        $broadcast = TelegramBroadcast::create([
            'broadcast_key' => (string) Str::uuid(),
            'source' => 'admin',
            'message' => 'Batch me',
            'status' => TelegramBroadcast::STATUS_QUEUED,
        ]);

        app(TelegramBroadcastService::class)->processAdminBroadcastBatch($broadcast->id, 10);

        $broadcast->refresh();
        $this->assertCount(10, $sentChatIds);
        Http::assertSentCount(10);
        Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request['text'] === "📣 Info from Admin\n━━━━━━━━━━━━━━\nBatch me\n\nNeed help? Reply with /support.");
        $this->assertSame(50, $broadcast->recipient_count);
        $this->assertSame(10, $broadcast->sent_count);
        $this->assertSame(40, $broadcast->pending_count);
        $this->assertSame(TelegramBroadcast::STATUS_IN_PROGRESS, $broadcast->status);
        Queue::assertNothingPushed();

        app(SendTelegramBroadcastBatch::class, ['broadcastId' => $broadcast->id])->handle(app(TelegramBroadcastService::class));

        Queue::assertPushedOn('telegram-broadcast', SendTelegramBroadcastBatch::class);
    }

    public function test_photo_broadcast_delivery_uses_send_photo_with_the_stored_reference_and_literal_caption(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        Http::fake(['https://api.telegram.org/*/sendPhoto' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 123],
        ])]);
        Customer::factory()->create(['telegram_id' => 111111111, 'locale' => 'id']);
        Customer::factory()->create(['telegram_id' => 222222222, 'locale' => 'en']);
        Customer::factory()->create(['telegram_id' => 333333333, 'locale' => 'ms']);
        $broadcast = TelegramBroadcast::create([
            'broadcast_key' => (string) Str::uuid(),
            'source' => 'admin',
            'message' => 'Promo foto literal',
            'photo_file_id' => 'bot-owned-photo-file-id',
            'reply_markup' => [
                'id' => ['inline_keyboard' => [[['text' => 'Beli', 'callback_data' => 'product:1']]]],
                'en' => ['inline_keyboard' => [[['text' => 'Buy', 'callback_data' => 'product:1']]]],
                'ms' => ['inline_keyboard' => [[['text' => 'Beli', 'callback_data' => 'product:1']]]],
            ],
            'status' => TelegramBroadcast::STATUS_QUEUED,
        ]);

        $result = app(TelegramBroadcastService::class)->processAdminBroadcastBatch($broadcast->id, 25);

        $this->assertSame(3, $result->sent);
        $requests = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto'));
        $this->assertCount(3, $requests);
        $this->assertSame(['111111111', '222222222', '333333333'], $requests->map(fn (array $record): string => (string) $record[0]['chat_id'])->all());
        $this->assertSame('bot-owned-photo-file-id', $requests->first()[0]['photo']);
        $this->assertSame('Promo foto literal', $requests->first()[0]['caption']);
        $this->assertSame('Beli', $requests->first()[0]['reply_markup']['inline_keyboard'][0][0]['text']);
        $this->assertSame('Buy', $requests->get(1)[0]['reply_markup']['inline_keyboard'][0][0]['text']);
        $this->assertSame('Beli', $requests->get(2)[0]['reply_markup']['inline_keyboard'][0][0]['text']);
    }

    public function test_admin_broadcast_text_wrappers_follow_each_snapshot_locale_while_body_stays_literal(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 123],
        ])]);
        Customer::factory()->create(['telegram_id' => 111111111, 'locale' => 'id']);
        Customer::factory()->create(['telegram_id' => 222222222, 'locale' => 'en']);
        Customer::factory()->create(['telegram_id' => 333333333, 'locale' => 'ms']);
        $broadcast = TelegramBroadcast::create([
            'broadcast_key' => (string) Str::uuid(),
            'source' => 'admin',
            'message' => 'Admin-authored body — Keep this exact.',
            'reply_markup' => [
                'id' => ['inline_keyboard' => [[['text' => 'Beli', 'callback_data' => 'product:1']]]],
                'en' => ['inline_keyboard' => [[['text' => 'Buy', 'callback_data' => 'product:1']]]],
                'ms' => ['inline_keyboard' => [[['text' => 'Beli', 'callback_data' => 'product:1']]]],
            ],
            'status' => TelegramBroadcast::STATUS_QUEUED,
        ]);

        app(TelegramBroadcastService::class)->processAdminBroadcastBatch($broadcast->id, 25);

        $requests = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage'));
        $this->assertCount(3, $requests);
        $this->assertSame(
            "📣 Info dari Admin\n━━━━━━━━━━━━━━\nAdmin-authored body — Keep this exact.\n\nButuh bantuan? Balas /support ya.",
            $requests->first()[0]['text'],
        );
        $this->assertSame(
            "📣 Info from Admin\n━━━━━━━━━━━━━━\nAdmin-authored body — Keep this exact.\n\nNeed help? Reply with /support.",
            $requests->get(1)[0]['text'],
        );
        $this->assertSame(
            "📣 Maklumat daripada Admin\n━━━━━━━━━━━━━━\nAdmin-authored body — Keep this exact.\n\nPerlukan bantuan? Balas /support.",
            $requests->get(2)[0]['text'],
        );
        $this->assertSame('Beli', $requests->first()[0]['reply_markup']['inline_keyboard'][0][0]['text']);
        $this->assertSame('Buy', $requests->get(1)[0]['reply_markup']['inline_keyboard'][0][0]['text']);
        $this->assertSame('Beli', $requests->get(2)[0]['reply_markup']['inline_keyboard'][0][0]['text']);
        $this->assertSame('Admin-authored body — Keep this exact.', $broadcast->fresh()->message);
    }

    public function test_shared_broadcast_rate_limit_leaves_unclaimed_recipients_pending(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        config()->set('services.telegram.broadcast_rate_limit_per_second', 1);
        Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 123],
        ])]);
        Customer::factory()->create(['telegram_id' => 111111111]);
        Customer::factory()->create(['telegram_id' => 222222222]);
        $broadcast = TelegramBroadcast::create([
            'broadcast_key' => (string) Str::uuid(),
            'source' => 'admin',
            'message' => 'Rate limited',
            'status' => TelegramBroadcast::STATUS_QUEUED,
        ]);

        $result = app(TelegramBroadcastService::class)->processAdminBroadcastBatch($broadcast->id, 25);

        $broadcast->refresh();
        $this->assertSame(1, $result->sent);
        $this->assertSame(1, $result->retryAfterSeconds);
        $this->assertSame(1, $broadcast->sent_count);
        $this->assertSame(1, $broadcast->pending_count);
        $this->assertSame(1, TelegramBroadcastRecipient::query()->where('attempts', 1)->count());
        $this->assertSame(1, TelegramBroadcastRecipient::query()->where('attempts', 0)->count());
        Http::assertSentCount(1);
    }

    public function test_configured_broadcast_limit_stays_below_telegram_default_even_when_set_to_thirty(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        config()->set('services.telegram.broadcast_rate_limit_per_second', 30);
        Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 123],
        ])]);
        Customer::factory()->count(30)->sequence(fn ($sequence) => ['telegram_id' => 300000 + $sequence->index])->create();
        $broadcast = TelegramBroadcast::create([
            'broadcast_key' => (string) Str::uuid(),
            'source' => 'admin',
            'message' => 'Conservative limit',
            'status' => TelegramBroadcast::STATUS_QUEUED,
        ]);

        $result = app(TelegramBroadcastService::class)->processAdminBroadcastBatch($broadcast->id, 50);

        $this->assertSame(25, $result->sent);
        $this->assertNull($result->retryAfterSeconds);
        $this->assertSame(25, Http::recorded()->count());
        $this->assertSame(25, TelegramBroadcastRecipient::query()->where('attempts', 1)->whereNotNull('sent_at')->count());
        $this->assertSame(5, TelegramBroadcastRecipient::query()->where('attempts', 0)->count());
        $this->assertSame(5, $broadcast->fresh()->pending_count);

        Cache::flush();
        $allowed = 0;
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $allowed += app(TelegramBroadcastRateLimiter::class)->tryAcquire()->allowed ? 1 : 0;
        }

        $this->assertSame(29, $allowed);
    }

    public function test_job_requeues_limiter_deferred_work_for_the_next_safe_window_without_claiming_it(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        config()->set('services.telegram.broadcast_rate_limit_per_second', 1);
        Queue::fake();
        Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 123],
        ])]);
        Customer::factory()->count(2)->sequence(fn ($sequence) => ['telegram_id' => 400000 + $sequence->index])->create();
        $broadcast = TelegramBroadcast::create([
            'broadcast_key' => (string) Str::uuid(),
            'source' => 'admin',
            'message' => 'Defer the remainder',
            'status' => TelegramBroadcast::STATUS_QUEUED,
        ]);
        $now = Carbon::parse('2026-08-13 10:00:00');
        Carbon::setTestNow($now);

        try {
            (new SendTelegramBroadcastBatch($broadcast->id, $now->toISOString()))
                ->handle(app(TelegramBroadcastService::class));

            $this->assertSame(1, Http::recorded()->count());
            $this->assertSame(1, TelegramBroadcastRecipient::query()->where('attempts', 1)->whereNotNull('sent_at')->count());
            $this->assertSame(1, TelegramBroadcastRecipient::query()->where('attempts', 0)->count());
            Queue::assertPushed(SendTelegramBroadcastBatch::class, function (SendTelegramBroadcastBatch $job) use ($broadcast, $now): bool {
                return $job->broadcastId === $broadcast->id
                    && $job->delay instanceof \DateTimeInterface
                    && $job->delay->getTimestamp() >= $now->copy()->addSecond()->getTimestamp();
            });
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_provider_retry_after_is_bounded_and_allows_retry_after_the_provider_window(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            return $attempts === 1
                ? Http::response([
                    'ok' => false,
                    'description' => 'Too Many Requests',
                    'parameters' => ['retry_after' => 45],
                ], 429)
                : Http::response(['ok' => true, 'result' => ['message_id' => 123]]);
        });
        Customer::factory()->create(['telegram_id' => 111111111]);
        $broadcast = TelegramBroadcast::create([
            'broadcast_key' => (string) Str::uuid(),
            'source' => 'admin',
            'message' => 'Provider retry',
            'status' => TelegramBroadcast::STATUS_QUEUED,
        ]);
        $now = Carbon::parse('2026-08-13 10:00:00');
        Carbon::setTestNow($now);

        try {
            $first = app(TelegramBroadcastService::class)->processAdminBroadcastBatch($broadcast->id, 25);

            $recipient = TelegramBroadcastRecipient::query()->firstOrFail();
            $this->assertSame(45, $first->retryAfterSeconds);
            $this->assertSame(1, $recipient->attempts);
            $this->assertNotNull($recipient->retry_at);
            $this->assertTrue($recipient->retry_at->equalTo($now->copy()->addSeconds(45)));

            Cache::flush();
            Carbon::setTestNow($now->copy()->addSeconds(31));
            $early = app(TelegramBroadcastService::class)->processAdminBroadcastBatch($broadcast->id, 25);

            $this->assertSame(0, $early->sent);
            $this->assertSame(1, $attempts);

            Cache::flush();
            Carbon::setTestNow($now->copy()->addSeconds(46));
            $second = app(TelegramBroadcastService::class)->processAdminBroadcastBatch($broadcast->id, 25);

            $this->assertSame(1, $second->sent);
            $this->assertSame(2, $attempts);
            $this->assertNotNull($broadcast->fresh()->completed_at);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_provider_flood_control_stops_the_current_batch_before_the_next_recipient(): void
    {
        config()->set('services.telegram.token', 'SENSITIVE_TELEGRAM_TOKEN');
        $attemptsByChat = [];
        Http::fake(function (HttpRequest $request) use (&$attemptsByChat) {
            $chatId = (string) $request['chat_id'];
            $attemptsByChat[$chatId] = ($attemptsByChat[$chatId] ?? 0) + 1;

            if ($chatId === '222222222') {
                return Http::response([
                    'ok' => false,
                    'description' => 'Too Many Requests',
                    'parameters' => ['retry_after' => 45],
                ], 429);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => array_sum($attemptsByChat)]]);
        });
        Customer::factory()->create(['telegram_id' => 111111111]);
        Customer::factory()->create(['telegram_id' => 222222222]);
        Customer::factory()->create(['telegram_id' => 333333333]);
        $broadcast = TelegramBroadcast::create([
            'broadcast_key' => (string) Str::uuid(),
            'source' => 'admin',
            'message' => 'SENSITIVE_BROADCAST_TEXT',
            'status' => TelegramBroadcast::STATUS_QUEUED,
        ]);

        $now = Carbon::parse('2026-08-13 10:00:00');
        Carbon::setTestNow($now);

        try {
            $result = app(TelegramBroadcastService::class)->processAdminBroadcastBatch($broadcast->id, 25);

            $this->assertSame(1, $result->sent);
            $this->assertSame(1, $result->transientFailures);
            $this->assertSame(45, $result->retryAfterSeconds);
            $this->assertSame(2, Http::recorded()->count());
            $this->assertSame(1, $attemptsByChat['111111111']);
            $this->assertSame(1, $attemptsByChat['222222222']);
            $this->assertArrayNotHasKey('333333333', $attemptsByChat);

            $this->assertSame(1, TelegramBroadcastRecipient::query()->where('telegram_id', '111111111')->value('attempts'));
            $this->assertNotNull(TelegramBroadcastRecipient::query()->where('telegram_id', '111111111')->value('sent_at'));
            $this->assertSame(1, TelegramBroadcastRecipient::query()->where('telegram_id', '222222222')->value('attempts'));
            $this->assertSame(0, TelegramBroadcastRecipient::query()->where('telegram_id', '333333333')->value('attempts'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_transient_failures_retry_without_resending_successful_recipients(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        $attemptsByChat = [];
        Http::fake(function (HttpRequest $request) use (&$attemptsByChat) {
            $chatId = (string) $request['chat_id'];
            $attemptsByChat[$chatId] = ($attemptsByChat[$chatId] ?? 0) + 1;

            if ($chatId === '222222222' && $attemptsByChat[$chatId] === 1) {
                return Http::response(['ok' => false, 'description' => 'Too Many Requests'], 429);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => array_sum($attemptsByChat)]]);
        });
        Customer::factory()->create(['telegram_id' => 111111111]);
        Customer::factory()->create(['telegram_id' => 222222222]);
        $broadcast = TelegramBroadcast::create([
            'broadcast_key' => (string) Str::uuid(),
            'source' => 'admin',
            'message' => 'Retry me',
            'status' => TelegramBroadcast::STATUS_QUEUED,
        ]);

        $first = app(TelegramBroadcastService::class)->processAdminBroadcastBatch($broadcast->id, 2);
        $this->assertSame(1, $first->sent);
        $this->assertSame(1, $first->transientFailures);
        $this->assertTrue($first->hasMore);

        TelegramBroadcastRecipient::query()
            ->where('telegram_id', '222222222')
            ->update([
                'failed_at' => now()->subSeconds(31),
                'retry_at' => now()->subSecond(),
            ]);
        TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_ADMIN_BROADCAST)
            ->where('telegram_chat_id', '222222222')
            ->sole()
            ->forceFill(['next_attempt_at' => now()->subSecond()])
            ->save();

        $second = app(TelegramBroadcastService::class)->processAdminBroadcastBatch($broadcast->id, 2);

        $broadcast->refresh();
        $this->assertSame(1, $second->sent);
        $this->assertSame(2, $broadcast->sent_count);
        $this->assertSame(0, $broadcast->pending_count);
        $this->assertSame(TelegramBroadcast::STATUS_COMPLETED, $broadcast->status);
        $this->assertSame(1, $attemptsByChat['111111111']);
        $this->assertSame(2, $attemptsByChat['222222222']);
    }

    public function test_same_confirmation_key_reuses_one_durable_intent_and_dispatch(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        Queue::fake();

        $first = app(TelegramBroadcastService::class)->queueAdminBroadcast(
            'Idempotent broadcast',
            [],
            'draft-confirmation-token',
        );
        $second = app(TelegramBroadcastService::class)->queueAdminBroadcast(
            'Idempotent broadcast',
            [],
            'draft-confirmation-token',
        );

        $this->assertSame($first['broadcast_id'], $second['broadcast_id']);
        $this->assertSame(1, TelegramBroadcast::query()->count());
        $this->assertNotNull(TelegramBroadcast::query()->firstOrFail()->queue_dispatched_at);
        Queue::assertPushedOn('telegram-broadcast', SendTelegramBroadcastBatch::class);
        Queue::assertPushedTimes(SendTelegramBroadcastBatch::class, 1);
    }

    public function test_queue_dispatch_failure_keeps_the_durable_intent_retryable(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        $dispatcher = Mockery::mock(DispatcherContract::class);
        $this->app->instance(DispatcherContract::class, $dispatcher);
        $dispatchAttempts = 0;
        $dispatcher->shouldReceive('dispatch')
            ->twice()
            ->andReturnUsing(function () use (&$dispatchAttempts): null {
                $dispatchAttempts++;
                if ($dispatchAttempts === 1) {
                    throw new \RuntimeException('queue unavailable');
                }

                return null;
            });

        $firstException = null;
        try {
            app(TelegramBroadcastService::class)->queueAdminBroadcast('Retry dispatch', [], 'retry-dispatch-token');
        } catch (\RuntimeException $exception) {
            $firstException = $exception;
        }

        $this->assertNotNull($firstException);
        $this->assertSame('queue unavailable', $firstException?->getMessage());
        $broadcast = TelegramBroadcast::query()->firstOrFail();
        $this->assertNull($broadcast->queue_dispatched_at);

        app(TelegramBroadcastService::class)->queueAdminBroadcast('Retry dispatch', [], 'retry-dispatch-token');

        $this->assertNotNull($broadcast->fresh()->queue_dispatched_at);
        $this->assertSame(1, TelegramBroadcast::query()->count());
    }

    public function test_permanent_recipient_failure_becomes_terminal_without_blocking_other_recipients(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        Http::fake(fn (HttpRequest $request) => (string) $request['chat_id'] === '222222222'
            ? Http::response(['ok' => false, 'description' => 'Forbidden: bot was blocked by the user'], 403)
            : Http::response(['ok' => true, 'result' => ['message_id' => 77]])
        );
        Customer::factory()->create(['telegram_id' => 111111111]);
        Customer::factory()->create(['telegram_id' => 222222222]);
        $broadcast = TelegramBroadcast::create([
            'broadcast_key' => (string) Str::uuid(),
            'source' => 'admin',
            'message' => 'Terminal me',
            'status' => TelegramBroadcast::STATUS_QUEUED,
        ]);

        $result = app(TelegramBroadcastService::class)->processAdminBroadcastBatch($broadcast->id, 2);

        $broadcast->refresh();
        $failed = TelegramBroadcastRecipient::query()->where('telegram_id', '222222222')->firstOrFail();
        $this->assertSame(1, $result->sent);
        $this->assertSame(1, $result->terminalFailures);
        $this->assertNotNull($failed->terminal_failed_at);
        $this->assertSame(TelegramBroadcastService::MAX_RECIPIENT_ATTEMPTS, $failed->attempts);
        $this->assertSame(1, $broadcast->sent_count);
        $this->assertSame(1, $broadcast->failed_count);
        $this->assertSame(TelegramBroadcast::STATUS_PARTIALLY_FAILED, $broadcast->status);
    }

    public function test_duplicate_jobs_do_not_resend_already_successful_recipients(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        $sentChatIds = [];
        Http::fake(function (HttpRequest $request) use (&$sentChatIds) {
            $sentChatIds[] = (string) $request['chat_id'];

            return Http::response(['ok' => true, 'result' => ['message_id' => count($sentChatIds)]]);
        });
        Customer::factory()->create(['telegram_id' => 111111111]);
        Customer::factory()->create(['telegram_id' => 222222222]);
        $broadcast = TelegramBroadcast::create([
            'broadcast_key' => (string) Str::uuid(),
            'source' => 'admin',
            'message' => 'No duplicates',
            'status' => TelegramBroadcast::STATUS_QUEUED,
        ]);

        app(TelegramBroadcastService::class)->processAdminBroadcastBatch($broadcast->id, 25);
        app(TelegramBroadcastService::class)->processAdminBroadcastBatch($broadcast->id, 25);

        $this->assertSame(['111111111', '222222222'], $sentChatIds);
        $this->assertSame(2, TelegramBroadcastRecipient::query()->whereNotNull('sent_at')->count());
        $this->assertSame(2, TelegramBroadcastRecipient::query()->where('attempts', 1)->count());
    }

    public function test_admin_broadcast_operational_logs_keep_diagnostics_but_redact_sensitive_values(): void
    {
        config()->set('services.telegram.token', 'SENSITIVE_TELEGRAM_TOKEN');
        config()->set('services.dogfood_timing.enabled', true);
        $logs = [];
        Log::listen(function (MessageLogged $event) use (&$logs): void {
            $logs[] = ['message' => $event->message, 'context' => $event->context];
        });
        Http::fake(function (HttpRequest $request) {
            if ((string) ($request['chat_id'] ?? '') === '987654322') {
                return Http::response([
                    'ok' => false,
                    'description' => 'SENSITIVE_PROVIDER_DESCRIPTION',
                ], 500);
            }

            return Http::response([
                'ok' => true,
                'result' => ['message_id' => 123],
            ]);
        });
        Customer::factory()->create(['telegram_id' => 987654321]);
        Customer::factory()->create(['telegram_id' => 987654322]);
        $broadcast = TelegramBroadcast::create([
            'broadcast_key' => (string) Str::uuid(),
            'source' => 'admin',
            'message' => 'SENSITIVE_CAPTION',
            'photo_file_id' => 'SENSITIVE_PHOTO_FILE_ID',
            'reply_markup' => [
                'id' => ['inline_keyboard' => [[['text' => 'Beli', 'callback_data' => 'broadcast:send:SENSITIVE_DRAFT_ID']]]],
            ],
            'status' => TelegramBroadcast::STATUS_QUEUED,
        ]);

        app(TelegramBroadcastService::class)->processAdminBroadcastBatch($broadcast->id, 25);

        $serializedLogs = json_encode($logs, JSON_THROW_ON_ERROR);
        foreach (['SENSITIVE_BROADCAST_TEXT', 'SENSITIVE_CAPTION', 'SENSITIVE_PHOTO_FILE_ID', 'SENSITIVE_TELEGRAM_TOKEN', 'SENSITIVE_DRAFT_ID', 'SENSITIVE_PROVIDER_DESCRIPTION', '987654321', '987654322'] as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $serializedLogs);
        }
        $this->assertStringContainsString('broadcast_id', $serializedLogs);
        $this->assertStringContainsString('recipient_correlation_id', $serializedLogs);
        $this->assertStringContainsString((string) $broadcast->id, $serializedLogs);
        $this->assertStringContainsString('exception', $serializedLogs);
        $this->assertStringContainsString('500', $serializedLogs);
    }

    public function test_recover_outbound_redispatches_an_unmaterialized_broadcast_and_an_expired_claim(): void
    {
        Queue::fake();
        $unmaterialized = TelegramBroadcast::create([
            'broadcast_key' => (string) Str::uuid(),
            'source' => 'admin',
            'message' => 'Awaiting snapshot',
            'status' => TelegramBroadcast::STATUS_QUEUED,
        ]);
        $expiredClaim = TelegramBroadcast::create([
            'broadcast_key' => (string) Str::uuid(),
            'source' => 'admin',
            'message' => 'Recover recipient',
            'status' => TelegramBroadcast::STATUS_IN_PROGRESS,
            'recipients_materialized_at' => now(),
        ]);
        $recipient = TelegramBroadcastRecipient::create([
            'broadcast_id' => $expiredClaim->id,
            'customer_id' => Customer::factory()->create()->id,
            'telegram_id' => '111111111',
            'locale' => 'id',
            'attempts' => 1,
        ]);

        $this->artisan('telegram:recover-outbound')
            ->expectsOutput('telegram:recover-outbound completed cleanly: dispatched=2')
            ->assertSuccessful();

        Queue::assertPushedOn('telegram-broadcast', SendTelegramBroadcastBatch::class);
        Queue::assertPushed(SendTelegramBroadcastBatch::class, fn (SendTelegramBroadcastBatch $job): bool => $job->broadcastId === $unmaterialized->id);
        Queue::assertPushed(SendTelegramBroadcastBatch::class, fn (SendTelegramBroadcastBatch $job): bool => $job->broadcastId === $expiredClaim->id);
    }

    public function test_recover_outbound_leaves_a_broadcast_with_an_active_claim_to_its_worker(): void
    {
        Queue::fake();
        $broadcast = TelegramBroadcast::create([
            'broadcast_key' => (string) Str::uuid(),
            'source' => 'admin',
            'message' => 'Already claimed',
            'status' => TelegramBroadcast::STATUS_IN_PROGRESS,
            'recipients_materialized_at' => now(),
        ]);
        $recipient = TelegramBroadcastRecipient::create([
            'broadcast_id' => $broadcast->id,
            'customer_id' => Customer::factory()->create()->id,
            'telegram_id' => '111111111',
            'locale' => 'id',
            'attempts' => 1,
        ]);
        app(TelegramOutboundEffectService::class)->recordAdminBroadcastRecipient(
            $broadcast,
            $recipient,
            TelegramOutboundEffectState::Sending,
        );

        $this->artisan('telegram:recover-outbound')
            ->expectsOutput('telegram:recover-outbound completed cleanly: dispatched=0')
            ->assertSuccessful();

        Queue::assertNotPushed(SendTelegramBroadcastBatch::class);
    }

    public function test_recover_outbound_does_not_dispatch_a_provider_deferred_recipient_before_its_retry_window(): void
    {
        Queue::fake();
        $broadcast = TelegramBroadcast::create([
            'broadcast_key' => (string) Str::uuid(),
            'source' => 'admin',
            'message' => 'Deferred recovery',
            'status' => TelegramBroadcast::STATUS_IN_PROGRESS,
            'recipients_materialized_at' => now(),
        ]);
        TelegramBroadcastRecipient::create([
            'broadcast_id' => $broadcast->id,
            'customer_id' => Customer::factory()->create()->id,
            'telegram_id' => '333333333',
            'locale' => 'id',
            'failed_at' => now(),
            'retry_at' => now()->addMinute(),
            'attempts' => 1,
        ]);

        $this->artisan('telegram:recover-outbound')
            ->expectsOutput('telegram:recover-outbound completed cleanly: dispatched=0')
            ->assertSuccessful();
        Queue::assertNotPushed(SendTelegramBroadcastBatch::class);

        TelegramBroadcastRecipient::query()->update(['retry_at' => now()->subSecond()]);

        $this->artisan('telegram:recover-outbound')
            ->expectsOutput('telegram:recover-outbound completed cleanly: dispatched=1')
            ->assertSuccessful();
        Queue::assertPushed(SendTelegramBroadcastBatch::class);
    }
}
