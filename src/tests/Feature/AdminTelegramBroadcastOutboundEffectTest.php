<?php

namespace Tests\Feature;

use App\Enums\TelegramOutboundEffectState;
use App\Models\Customer;
use App\Models\TelegramBroadcast;
use App\Models\TelegramBroadcastRecipient;
use App\Models\TelegramOutboundEffect;
use App\Models\User;
use App\Services\Telegram\TelegramBroadcastService;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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

    public function test_stale_legacy_recipient_is_backfilled_as_unknown_without_a_blind_send(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $customer = Customer::factory()->create(['telegram_id' => 633333333]);
        $broadcast = $this->broadcast('Stale legacy recipient', TelegramBroadcast::STATUS_IN_PROGRESS);
        TelegramBroadcastRecipient::create([
            'broadcast_id' => $broadcast->id,
            'customer_id' => $customer->id,
            'telegram_id' => (string) $customer->telegram_id,
            'locale' => 'id',
            'send_started_at' => now()->subMinutes(16),
            'send_claim_token' => (string) Str::uuid(),
            'attempts' => 1,
        ]);

        app(TelegramBroadcastService::class)->processAdminBroadcastBatch($broadcast->id, 25);

        $effect = TelegramOutboundEffect::query()->sole();
        $this->assertSame(TelegramOutboundEffectState::Unknown, $effect->state);
        $this->assertSame('legacy_broadcast_claim_ambiguous', $effect->last_error_code);
        Http::assertNothingSent();
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

        $this->assertTrue(app(TelegramOutboundEffectService::class)->markSent($effect, '905', $owner));

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

    private function broadcast(string $message, string $status = TelegramBroadcast::STATUS_QUEUED): TelegramBroadcast
    {
        return TelegramBroadcast::create([
            'broadcast_key' => (string) Str::uuid(),
            'source' => 'admin',
            'message' => $message,
            'status' => $status,
        ]);
    }
}
