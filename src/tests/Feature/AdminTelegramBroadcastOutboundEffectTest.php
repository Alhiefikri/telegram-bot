<?php

namespace Tests\Feature;

use App\Enums\TelegramOutboundEffectState;
use App\Jobs\SendTelegramBroadcastBatch;
use App\Models\Customer;
use App\Models\TelegramBroadcast;
use App\Models\TelegramBroadcastRecipient;
use App\Models\TelegramOutboundEffect;
use App\Models\User;
use App\Services\Telegram\AdminBroadcastDeliveryPipeline;
use App\Services\Telegram\TelegramBroadcastService;
use App\Services\Telegram\TelegramOutboundEffectLedgerProtocol;
use App\Services\Telegram\TelegramOutboundEffectRecovery;
use App\Services\Telegram\TelegramOutboundEffectReviewService;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminTelegramBroadcastOutboundEffectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.telegram.token', 'bot-token');
        config()->set('services.telegram.broadcast_rate_limit_per_second', 25);
    }

    public function test_each_materialized_recipient_has_one_shared_effect_and_success_projects_sent_state(): void
    {
        Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 901],
        ])]);
        Customer::factory()->create(['telegram_id' => 611111111]);
        Customer::factory()->create(['telegram_id' => 622222222]);
        $broadcast = $this->broadcast('Effect-backed broadcast');

        app(TelegramBroadcastService::class)->processAdminBroadcastBatch($broadcast->id, 25);

        $effects = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_ADMIN_BROADCAST)
            ->orderBy('source_id')
            ->get();

        $this->assertCount(2, $effects);
        $this->assertSame(2, $effects->where('state', TelegramOutboundEffectState::Sent)->count());
        $this->assertSame(2, TelegramBroadcastRecipient::query()->whereNotNull('sent_at')->count());
        $this->assertSame(
            TelegramOutboundEffectService::adminBroadcastRecipientEffectKey(
                (string) $broadcast->broadcast_key,
                (string) $effects->first()->source_id,
            ),
            $effects->first()->effect_key,
        );
    }

    #[DataProvider('invalidTelegramResponses')]
    public function test_invalid_telegram_responses_never_mark_a_broadcast_recipient_sent(array $response): void
    {
        Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response($response)]);
        Customer::factory()->create(['telegram_id' => 611111112]);
        $broadcast = $this->broadcast('Invalid response recipient');

        $result = app(TelegramBroadcastService::class)->processAdminBroadcastBatch($broadcast->id, 25);
        $effect = TelegramOutboundEffect::query()->sole();
        $recipient = TelegramBroadcastRecipient::query()->sole();

        $this->assertSame(0, $result->sent);
        $this->assertSame(1, $result->transientFailures);
        $this->assertSame(TelegramOutboundEffectState::Failed, $effect->state);
        $this->assertNull($effect->telegram_message_id);
        $this->assertNull($recipient->sent_at);
    }

    public function test_ambiguous_recipient_becomes_unknown_and_later_batches_do_not_resend_it(): void
    {
        $calls = [];
        Http::fake(function (HttpRequest $request) use (&$calls) {
            $chatId = (string) $request['chat_id'];
            $calls[] = $chatId;
            if ($chatId === '611111111') {
                throw new ConnectionException('connection closed after Telegram accepted the request');
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 902]]);
        });
        Customer::factory()->create(['telegram_id' => 611111111]);
        Customer::factory()->create(['telegram_id' => 622222222]);
        $broadcast = $this->broadcast('Ambiguous recipient');

        $first = app(TelegramBroadcastService::class)->processAdminBroadcastBatch($broadcast->id, 25);
        $this->assertSame(1, $first->sent);
        $this->assertSame(1, $first->unknown);

        $unknown = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_ADMIN_BROADCAST)
            ->where('telegram_chat_id', '611111111')
            ->sole();
        $sent = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_ADMIN_BROADCAST)
            ->where('telegram_chat_id', '622222222')
            ->sole();
        $this->assertSame(TelegramOutboundEffectState::Unknown, $unknown->state);
        $this->assertSame(TelegramOutboundEffectState::Sent, $sent->state);

        app(TelegramBroadcastService::class)->processAdminBroadcastBatch($broadcast->id, 25);

        $this->assertSame(['611111111', '622222222'], $calls);
        $this->assertSame(TelegramOutboundEffectState::Unknown, $unknown->fresh()->state);
    }

    public function test_definite_forbidden_recipient_is_terminal_in_the_shared_ledger(): void
    {
        Http::fake(fn (HttpRequest $request) => (string) $request['chat_id'] === '644444444'
            ? Http::response(['ok' => false, 'description' => 'Forbidden'], 403)
            : Http::response(['ok' => true, 'result' => ['message_id' => 904]]));
        Customer::factory()->create(['telegram_id' => 644444444]);
        $broadcast = $this->broadcast('Forbidden recipient');

        $result = app(TelegramBroadcastService::class)->processAdminBroadcastBatch($broadcast->id, 25);

        $effect = TelegramOutboundEffect::query()->where('telegram_chat_id', '644444444')->sole();
        $recipient = TelegramBroadcastRecipient::query()->where('telegram_id', '644444444')->sole();
        $this->assertSame(1, $result->terminalFailures);
        $this->assertSame(TelegramOutboundEffectState::Failed, $effect->state);
        $this->assertSame('telegram_http_403', $effect->last_error_code);
        $this->assertSame(TelegramOutboundEffectService::ADMIN_BROADCAST_MAX_ATTEMPTS, $recipient->attempts);
        $this->assertNotNull($recipient->terminal_failed_at);
    }

    public function test_http_429_is_a_definite_retryable_broadcast_failure_not_an_ambiguous_delivery(): void
    {
        Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response([
            'ok' => false,
            'description' => 'Too Many Requests',
            'parameters' => ['retry_after' => 7],
        ], 429)]);
        Customer::factory()->create(['telegram_id' => 644444445]);
        $broadcast = $this->broadcast('Flood controlled recipient');

        $result = app(AdminBroadcastDeliveryPipeline::class)->deliver($broadcast->id, 25);
        $effect = TelegramOutboundEffect::query()->sole();
        $recipient = TelegramBroadcastRecipient::query()->sole();

        $this->assertSame(1, $result->transientFailures);
        $this->assertSame(0, $result->unknown);
        $this->assertTrue($result->hasMore);
        $this->assertSame(TelegramOutboundEffectState::Failed, $effect->state);
        $this->assertSame('telegram_http_429', $effect->last_error_code);
        $this->assertNull($effect->unknown_at);
        $this->assertNotNull($effect->next_attempt_at);
        $this->assertNotNull($recipient->failed_at);
        $this->assertNotNull($recipient->retry_at);
        $this->assertNull($recipient->terminal_failed_at);
    }

    public function test_expired_broadcast_lease_becomes_unknown_and_recomputes_the_aggregate_projection(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 644444446]);
        $broadcast = $this->broadcast('Expired lease recipient');
        $recipient = TelegramBroadcastRecipient::create([
            'broadcast_id' => $broadcast->id,
            'customer_id' => $customer->id,
            'telegram_id' => (string) $customer->telegram_id,
            'locale' => 'id',
        ]);
        $effect = app(TelegramOutboundEffectService::class)->recordAdminBroadcastRecipient($broadcast, $recipient);
        $claimed = app(TelegramOutboundEffectLedgerProtocol::class)->claim((string) $effect->getKey());
        $effect->fresh()->forceFill(['lease_until' => now()->subSecond()])->save();

        app(TelegramOutboundEffectRecovery::class)->recoverExpiredLeases();

        $this->assertSame(TelegramOutboundEffectState::Unknown, $effect->fresh()->state);
        $this->assertSame(1, $recipient->fresh()->attempts);
        $this->assertSame(TelegramBroadcast::STATUS_QUEUED, $broadcast->fresh()->status);
        $this->assertSame(1, $broadcast->fresh()->pending_count);
        $this->assertNotNull($claimed);
    }

    public function test_operator_can_mark_an_ambiguous_broadcast_recipient_sent_without_resending(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            throw new ConnectionException('connection closed after Telegram accepted the request');
        });
        Customer::factory()->create(['telegram_id' => 655555555]);
        $broadcast = $this->broadcast('Review this recipient');

        app(TelegramBroadcastService::class)->processAdminBroadcastBatch($broadcast->id, 25);
        $effect = TelegramOutboundEffect::query()->sole();
        $owner = User::factory()->create();

        $this->assertTrue(app(TelegramOutboundEffectReviewService::class)->markSent($effect, '905', $owner));

        $this->assertSame(1, $calls);
        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->fresh()->state);
        $this->assertNotNull(TelegramBroadcastRecipient::query()->sole()->sent_at);
        $this->assertSame(TelegramBroadcast::STATUS_COMPLETED, $broadcast->fresh()->status);
    }

    public function test_explicit_broadcast_resend_creates_a_new_effect_version(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new ConnectionException('connection closed after Telegram accepted the request');
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 906]]);
        });
        Customer::factory()->create(['telegram_id' => 666666666]);
        $broadcast = $this->broadcast('Explicit resend');

        app(TelegramBroadcastService::class)->processAdminBroadcastBatch($broadcast->id, 25);
        $original = TelegramOutboundEffect::query()->sole();
        $owner = User::factory()->create();
        $resend = app(TelegramOutboundEffectService::class)->resend($original, $owner);

        app(TelegramBroadcastService::class)->processAdminBroadcastBatch($broadcast->id, 25);

        $this->assertSame(2, $calls);
        $this->assertSame(TelegramOutboundEffectState::Unknown, $original->fresh()->state);
        $this->assertSame(TelegramOutboundEffectState::Sent, $resend->fresh()->state);
        $this->assertSame($original->id, $resend->supersedes_effect_id);
        $this->assertNotNull(TelegramBroadcastRecipient::query()->sole()->sent_at);
    }

    public function test_recovery_repairs_sent_recipient_and_broadcast_projection_without_redispatching(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $firstCustomer = Customer::factory()->create(['telegram_id' => 677777771]);
        $secondCustomer = Customer::factory()->create(['telegram_id' => 677777772]);
        $broadcast = $this->broadcast('Repair sent recipient', TelegramBroadcast::STATUS_IN_PROGRESS);
        $broadcast->forceFill([
            'recipients_materialized_at' => now(),
            'recipient_count' => 2,
            'sent_count' => 0,
            'failed_count' => 0,
            'pending_count' => 2,
        ])->save();

        $sentRecipient = TelegramBroadcastRecipient::create([
            'broadcast_id' => $broadcast->id,
            'customer_id' => $firstCustomer->id,
            'telegram_id' => (string) $firstCustomer->telegram_id,
            'locale' => 'id',
        ]);
        $unknownRecipient = TelegramBroadcastRecipient::create([
            'broadcast_id' => $broadcast->id,
            'customer_id' => $secondCustomer->id,
            'telegram_id' => (string) $secondCustomer->telegram_id,
            'locale' => 'id',
            'attempts' => 1,
            'last_error' => 'requires review',
        ]);

        $outboundEffects = app(TelegramOutboundEffectService::class);
        $sentEffect = $outboundEffects->recordAdminBroadcastRecipient($broadcast, $sentRecipient);
        $unknownEffect = $outboundEffects->recordAdminBroadcastRecipient(
            $broadcast,
            $unknownRecipient,
            state: TelegramOutboundEffectState::Unknown,
            unknownAt: now(),
            lastErrorCode: 'telegram_transport_ambiguous',
            lastError: 'requires review',
        );
        $ledger = app(TelegramOutboundEffectLedgerProtocol::class);
        $claimed = $ledger->claim((string) $sentEffect->getKey());
        $this->assertNotNull($claimed);
        $finalized = $ledger->finalizeSent(
            (string) $sentEffect->getKey(),
            (string) $claimed->claim_token,
            '907',
        );
        $this->assertNotNull($finalized);
        $this->assertSame(TelegramOutboundEffectState::Sent, $sentEffect->fresh()->state);
        $this->assertNull($sentRecipient->fresh()->sent_at);
        $this->assertSame(TelegramOutboundEffectState::Unknown, $unknownEffect->fresh()->state);

        $this->artisan('telegram:recover-outbound', ['--chunk' => 1])
            ->assertSuccessful();

        $repairedRecipient = $sentRecipient->fresh();
        $repairedBroadcast = $broadcast->fresh();
        $this->assertNotNull($repairedRecipient->sent_at);
        $this->assertSame(1, $repairedRecipient->attempts);
        $this->assertNull($repairedRecipient->failed_at);
        $this->assertNull($unknownRecipient->fresh()->sent_at);
        $this->assertSame(1, $unknownRecipient->fresh()->attempts);
        $this->assertSame(2, $repairedBroadcast->recipient_count);
        $this->assertSame(1, $repairedBroadcast->sent_count);
        $this->assertSame(0, $repairedBroadcast->failed_count);
        $this->assertSame(1, $repairedBroadcast->pending_count);
        $this->assertSame(TelegramBroadcast::STATUS_IN_PROGRESS, $repairedBroadcast->status);
        $this->assertNull($repairedBroadcast->completed_at);
        Queue::assertNotPushed(SendTelegramBroadcastBatch::class);
        Http::assertNothingSent();
        $sentAt = $repairedRecipient->sent_at;
        $this->artisan('telegram:recover-outbound', ['--chunk' => 1])
            ->assertSuccessful();
        $this->assertTrue($sentAt->equalTo($sentRecipient->fresh()->sent_at));
        $this->assertSame(TelegramOutboundEffectState::Sent, $sentEffect->fresh()->state);
        $this->assertSame(TelegramOutboundEffectState::Unknown, $unknownEffect->fresh()->state);
        Queue::assertNotPushed(SendTelegramBroadcastBatch::class);
    }

    private function broadcast(string $message, string $status = TelegramBroadcast::STATUS_QUEUED): TelegramBroadcast
    {
        return TelegramBroadcast::create([
            'broadcast_key' => (string) Str::uuid(),
            'source' => 'admin',
            'message' => $message,
            'status' => $status,
        ]);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidTelegramResponses(): iterable
    {
        yield 'ok false with numeric result' => [['ok' => false, 'result' => ['message_id' => 901]]];
        yield 'zero' => [['ok' => true, 'result' => ['message_id' => 0]]];
        yield 'negative' => [['ok' => true, 'result' => ['message_id' => -1]]];
        yield 'fractional' => [['ok' => true, 'result' => ['message_id' => 1.5]]];
        yield 'empty' => [['ok' => true, 'result' => ['message_id' => '']]];
        yield 'non numeric' => [['ok' => true, 'result' => ['message_id' => 'invalid']]];
    }
}
