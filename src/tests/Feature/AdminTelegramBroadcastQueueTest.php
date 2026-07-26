<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\SendTelegramBroadcastBatch;
use App\Models\Customer;
use App\Models\TelegramBroadcast;
use App\Models\TelegramBroadcastRecipient;
use App\Models\User;
use App\Services\Telegram\TelegramBroadcastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminTelegramBroadcastQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_broadcast_submission_persists_intent_and_enqueues_without_sending_to_recipients(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        Queue::fake();
        Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true])]);

        Customer::factory()->create(['telegram_id' => 111111111]);
        Customer::factory()->create(['telegram_id' => 222222222]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->post(route('admin.broadcast.store'), ['message' => 'Private launch note'])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'broadcast']))
            ->assertSessionHas('status', 'Broadcast #1 masuk antrean. Progres bisa dipantau di tab Broadcast.');

        $broadcast = TelegramBroadcast::query()->firstOrFail();
        $this->assertSame('Private launch note', $broadcast->message);
        $this->assertSame(TelegramBroadcast::STATUS_QUEUED, $broadcast->status);
        $this->assertSame(0, TelegramBroadcastRecipient::query()->count());

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
        $this->assertSame(50, $broadcast->recipient_count);
        $this->assertSame(10, $broadcast->sent_count);
        $this->assertSame(40, $broadcast->pending_count);
        $this->assertSame(TelegramBroadcast::STATUS_IN_PROGRESS, $broadcast->status);
        Queue::assertNothingPushed();

        app(SendTelegramBroadcastBatch::class, ['broadcastId' => $broadcast->id])->handle(app(TelegramBroadcastService::class));

        Queue::assertPushedOn('telegram-broadcast', SendTelegramBroadcastBatch::class);
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
            ->update(['failed_at' => now()->subSeconds(31)]);

        $second = app(TelegramBroadcastService::class)->processAdminBroadcastBatch($broadcast->id, 2);

        $broadcast->refresh();
        $this->assertSame(1, $second->sent);
        $this->assertSame(2, $broadcast->sent_count);
        $this->assertSame(0, $broadcast->pending_count);
        $this->assertSame(TelegramBroadcast::STATUS_COMPLETED, $broadcast->status);
        $this->assertSame(1, $attemptsByChat['111111111']);
        $this->assertSame(2, $attemptsByChat['222222222']);
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
}
