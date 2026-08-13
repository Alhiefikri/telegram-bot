<?php

namespace Tests\Feature\Telegram;

use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StoreChannelSetting;
use App\Models\TelegramCommunityGatePrompt;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\TelegramBotTestSupport;

class CommunityGateTest extends TelegramBotTestSupport
{
    public function test_language_selection_requires_channel_membership_before_welcome(): void
    {
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'telegram_chat_id' => '-100123',
            'channel_username' => '@store_channel',
            'community_gate_enabled' => true,
            'last_test_success' => true,
        ]);
        $this->fakeGateHttp('left');

        $this->postTelegramWebhook($this->messageUpdate(500, '/start'))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(501, 'locale-en', 'locale:en'))->assertOk();

        $this->assertDatabaseHas('customers', ['telegram_id' => 12345, 'locale' => 'en']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains((string) ($r['text'] ?? ''), 'Join the channel first')
            && str_contains(json_encode($r->data()), 'I Have Joined'));
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains((string) ($r['text'] ?? ''), 'Welcome to LHIE STORE'));
    }

    public function test_language_command_can_change_an_existing_customer_locale(): void
    {
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(502, '/language'))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(503, 'locale-en-again', 'locale:en'))->assertOk();

        $this->assertDatabaseHas('customers', ['telegram_id' => 12345, 'locale' => 'en']);
        Http::assertSent(fn (Request $r) => (str_ends_with($r->url(), '/sendMessage') || str_ends_with($r->url(), '/sendPhoto'))
            && str_contains((string) ($this->telegramRequestField($r, 'text') ?? $this->telegramRequestField($r, 'caption')), 'Welcome to LHIE STORE'));
    }

    public function test_changing_locale_replaces_an_active_gate_prompt_with_english_copy(): void
    {
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'telegram_chat_id' => '-100123',
            'channel_username' => '@store_channel',
            'community_gate_enabled' => true,
            'last_test_success' => true,
        ]);
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $nextMessageId = 700;
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => function (Request $request) use (&$nextMessageId) {
            if (str_ends_with($request->url(), '/getChatMember')) {
                return Http::response(['ok' => true, 'result' => ['status' => 'left']]);
            }
            if (str_ends_with($request->url(), '/sendMessage')) {
                return Http::response(['ok' => true, 'result' => ['message_id' => ++$nextMessageId]]);
            }

            return Http::response(['ok' => true]);
        }]);

        $this->postTelegramWebhook($this->messageUpdate(504, '/katalog'))->assertOk();
        $initialPromptMessageId = TelegramCommunityGatePrompt::query()->sole()->message_id;
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains((string) ($r['text'] ?? ''), 'Gabung kanal dulu')
            && str_contains(json_encode($r->data()), 'Gabung kanal')
            && str_contains(json_encode($r->data()), 'Saya sudah bergabung'));

        $this->postTelegramWebhook($this->messageUpdate(505, '/language'))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(506, 'locale-en-active-gate', 'locale:en'))->assertOk();

        $this->assertDatabaseHas('customers', ['telegram_id' => 12345, 'locale' => 'en']);
        $this->assertNotSame(
            $initialPromptMessageId,
            TelegramCommunityGatePrompt::query()->sole()->message_id,
            'Changing locale must replace the active gate prompt instead of reusing its Indonesian message.',
        );
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains((string) ($r['text'] ?? ''), 'Join the channel first')
            && ! str_contains((string) ($r['text'] ?? ''), 'Gabung kanal dulu')
            && str_contains(json_encode($r->data()), 'Join Channel')
            && str_contains(json_encode($r->data()), 'I Have Joined')
            && str_contains(json_encode($r->data()), 'community_gate:verify'));
    }

    public function test_non_member_is_blocked_and_receives_a_fresh_prompt_for_each_store_action(): void
    {
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'telegram_chat_id' => '-100123',
            'channel_username' => '@store_channel',
            'community_gate_enabled' => true,
            'last_test_success' => true,
        ]);
        $product = Product::factory()->create(['is_active' => true, 'stock' => 5, 'reserved_stock' => 0]);
        $nextMessageId = 700;
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => function (Request $request) use (&$nextMessageId) {
            if (str_ends_with($request->url(), '/getChatMember')) {
                return Http::response(['ok' => true, 'result' => ['status' => 'left']]);
            }
            if (str_ends_with($request->url(), '/sendMessage')) {
                return Http::response(['ok' => true, 'result' => ['message_id' => ++$nextMessageId]]);
            }

            return Http::response(['ok' => true]);
        }]);

        $this->postTelegramWebhook($this->messageUpdate(1, '/katalog'))->assertOk();
        $firstPromptMessageId = TelegramCommunityGatePrompt::query()->sole()->message_id;
        $this->postTelegramWebhook($this->callbackUpdate(2, 'cb-buy', 'buy:'.$product->id))->assertOk();

        $this->assertSame(1, TelegramCommunityGatePrompt::count());
        $this->assertSame(0, CartItem::count());
        $this->assertSame(2, $this->sentCount('/sendMessage', 'Gabung kanal dulu'));
        $this->assertNotSame($firstPromptMessageId, TelegramCommunityGatePrompt::query()->sole()->message_id);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/getChatMember')
            && $r['chat_id'] === '-100123'
            && $r['user_id'] === 12345);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/deleteMessage')
            && $r['chat_id'] === '12345'
            && $r['message_id'] === $firstPromptMessageId);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains(json_encode($r->data()), 'Gabung kanal')
            && str_contains(json_encode($r->data()), 'Saya sudah bergabung')
            && str_contains(json_encode($r->data()), 'community_gate:verify'));
    }

    public function test_prompt_delete_failure_still_sends_and_stores_a_fresh_prompt(): void
    {
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'telegram_chat_id' => '-100123',
            'channel_username' => '@store_channel',
            'community_gate_enabled' => true,
            'last_test_success' => true,
        ]);
        $nextMessageId = 800;
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => function (Request $request) use (&$nextMessageId) {
            if (str_ends_with($request->url(), '/getChatMember')) {
                return Http::response(['ok' => true, 'result' => ['status' => 'left']]);
            }
            if (str_ends_with($request->url(), '/deleteMessage')) {
                return Http::response(['ok' => false, 'description' => 'Bad Request: message cannot be deleted'], 400);
            }
            if (str_ends_with($request->url(), '/sendMessage')) {
                return Http::response(['ok' => true, 'result' => ['message_id' => ++$nextMessageId]]);
            }

            return Http::response(['ok' => true]);
        }]);

        $this->postTelegramWebhook($this->messageUpdate(3, '/katalog'))->assertOk();
        $firstPromptMessageId = TelegramCommunityGatePrompt::query()->sole()->message_id;
        $this->postTelegramWebhook($this->messageUpdate(4, '/katalog'))->assertOk();

        $this->assertSame(2, $this->sentCount('/sendMessage', 'Gabung kanal dulu'));
        $this->assertNotSame($firstPromptMessageId, TelegramCommunityGatePrompt::query()->sole()->message_id);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/deleteMessage')
            && $r['message_id'] === $firstPromptMessageId);
    }

    public function test_concurrent_blocked_update_does_not_emit_a_second_prompt_while_refresh_lock_is_held(): void
    {
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'telegram_chat_id' => '-100123',
            'channel_username' => '@store_channel',
            'community_gate_enabled' => true,
            'last_test_success' => true,
        ]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $this->fakeGateHttp('left');
        $lock = Cache::lock('telegram:community-gate-prompt:'.$customer->id, 30);
        $this->assertTrue($lock->get());

        try {
            $this->postTelegramWebhook($this->messageUpdate(7, '/katalog'))->assertOk();

            $this->assertSame(0, TelegramCommunityGatePrompt::count());
            $this->assertSame(0, $this->sentCount('/sendMessage', 'Gabung kanal dulu'));
        } finally {
            $lock->release();
        }

        $this->postTelegramWebhook($this->messageUpdate(8, '/katalog'))->assertOk();

        $this->assertSame(1, TelegramCommunityGatePrompt::count());
        $this->assertSame(1, $this->sentCount('/sendMessage', 'Gabung kanal dulu'));
    }

    public function test_existing_customer_start_is_blocked_before_welcome_when_not_a_member(): void
    {
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'telegram_chat_id' => '-100123',
            'channel_username' => '@store_channel',
            'community_gate_enabled' => true,
            'last_test_success' => true,
        ]);
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $this->fakeGateHttp('left');

        $this->postTelegramWebhook($this->messageUpdate(5, '/start'))->assertOk();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains((string) ($r['text'] ?? ''), 'Gabung kanal dulu'));
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains((string) ($r['text'] ?? ''), 'selamat datang di LHIE STORE'));
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains((string) ($r['text'] ?? ''), 'Menu utama'));
    }

    public function test_existing_customer_start_keeps_welcome_flow_when_already_a_member(): void
    {
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'telegram_chat_id' => '-100123',
            'channel_username' => '@store_channel',
            'community_gate_enabled' => true,
            'last_test_success' => true,
        ]);
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $this->fakeGateHttp('member');

        $this->postTelegramWebhook($this->messageUpdate(6, '/start'))->assertOk();

        Http::assertSent(fn (Request $r) => (str_ends_with($r->url(), '/sendMessage') || str_ends_with($r->url(), '/sendPhoto'))
            && str_contains((string) ($this->telegramRequestField($r, 'text') ?? $this->telegramRequestField($r, 'caption')), 'selamat datang di LHIE STORE'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains((string) ($r['text'] ?? ''), 'Menu utama'));
    }

    public function test_existing_non_member_product_deep_link_is_blocked_before_product_content(): void
    {
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'telegram_chat_id' => '-100123',
            'channel_username' => '@store_channel',
            'community_gate_enabled' => true,
            'last_test_success' => true,
        ]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $product = Product::factory()->create(['is_active' => true, 'name' => 'Netflix Premium', 'stock' => 5, 'reserved_stock' => 0]);
        $this->fakeGateHttp('left');

        $this->postTelegramWebhook($this->messageUpdate(9, '/start product_'.$product->id))->assertOk();

        $this->assertSame($customer->id, TelegramCommunityGatePrompt::query()->sole()->customer_id);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains((string) ($r['text'] ?? ''), 'Gabung kanal dulu'));
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains((string) ($r['text'] ?? ''), 'Netflix Premium'));
    }

    public function test_invalid_validated_channel_setup_fails_closed_with_retry_guidance(): void
    {
        foreach ([
            'missing chat id' => ['telegram_chat_id' => null, 'last_test_success' => true, 'update_id' => 10],
            'failed validation' => ['telegram_chat_id' => '-100123', 'last_test_success' => false, 'update_id' => 11],
        ] as $case => $setup) {
            StoreChannelSetting::query()->delete();
            TelegramCommunityGatePrompt::query()->delete();
            StoreChannelSetting::create([
                'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
                'community_gate_enabled' => true,
                'telegram_chat_id' => $setup['telegram_chat_id'],
                'last_test_success' => $setup['last_test_success'],
            ]);
            $this->fakeTelegramHttp();

            $this->postTelegramWebhook($this->messageUpdate($setup['update_id'], '/katalog', [], $setup['update_id']))->assertOk();

            $this->assertSame(1, TelegramCommunityGatePrompt::count(), "{$case} should create a community gate prompt.");
            $this->assertSame(0, $this->sentCount('/sendMessage', 'Produk'), "{$case} should not send catalog or product copy.");
            Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage')
                && $r['chat_id'] === (string) $setup['update_id']
                && str_contains($r['text'] ?? '', 'belum bisa dicek')
                && str_contains($r['text'] ?? '', 'Gabung kanal dulu')
                && str_contains($r['text'] ?? '', 'coba lagi')
                && str_contains($r['text'] ?? '', 'nanti')
                && str_contains(json_encode($r->data()), 'Saya sudah bergabung')
                && str_contains(json_encode($r->data()), 'community_gate:verify'));
        }
    }

    public function test_member_administrator_creator_and_restricted_member_statuses_are_allowed(): void
    {
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'telegram_chat_id' => '-100123',
            'community_gate_enabled' => true,
            'last_test_success' => true,
        ]);

        foreach ([['member', false], ['administrator', false], ['creator', false], ['restricted', true]] as $index => [$status, $isMember]) {
            $this->fakeGateHttp($status, $isMember);

            $this->postTelegramWebhook($this->messageUpdate(100 + $index, '/katalog', [], 9000 + $index))->assertOk();

            $this->assertSame(0, TelegramCommunityGatePrompt::count(), "{$status} should not be prompted.");
            Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage')
                && str_contains($r['text'] ?? '', 'Produk'));
        }
    }

    public function test_positive_membership_is_reused_for_short_interaction_lease(): void
    {
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'telegram_chat_id' => '-100123',
            'community_gate_enabled' => true,
            'last_test_success' => true,
        ]);
        $this->fakeGateHttpSequence(['member', 'left']);

        $this->postTelegramWebhook($this->messageUpdate(600, '/katalog'))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(601, '/katalog'))->assertOk();

        $membershipRequests = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/getChatMember'));
        $this->assertCount(1, $membershipRequests);
        $this->assertSame(0, TelegramCommunityGatePrompt::count());
    }

    public function test_get_chat_member_failure_fails_closed_with_retry_guidance(): void
    {
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'telegram_chat_id' => '-100-bad',
            'community_gate_enabled' => true,
            'last_test_success' => true,
        ]);
        config()->set('services.telegram.token', 'sensitive-bot-token');
        $warnings = [];
        Log::shouldReceive('warning')->once()->withArgs(function (string $event, array $context) use (&$warnings) {
            $warnings[] = [$event, $context];

            return true;
        });
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => function (Request $request) {
            if (str_ends_with($request->url(), '/getChatMember')) {
                return Http::response([
                    'ok' => false,
                    'description' => 'Bad Request via https://api.telegram.org/botsensitive-bot-token/getChatMember: chat not found',
                ], 400);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 77]]);
        }]);

        $this->postTelegramWebhook($this->messageUpdate(20, '/katalog'))->assertOk();

        $this->assertSame(1, TelegramCommunityGatePrompt::count());
        $this->assertSame(0, $this->sentCount('/sendMessage', 'Produk'));
        $this->assertCount(1, $warnings);
        $this->assertSame('telegram.community_gate_membership_check_failed', $warnings[0][0]);
        $this->assertArrayHasKey('exception', $warnings[0][1]);
        $this->assertArrayHasKey('error', $warnings[0][1]);
        $this->assertStringNotContainsString('sensitive-bot-token', $warnings[0][1]['error']);
        $this->assertArrayNotHasKey('telegram_user_id', $warnings[0][1]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'] ?? '', 'belum bisa dicek')
            && str_contains($r['text'] ?? '', 'Gabung kanal dulu')
            && str_contains($r['text'] ?? '', 'coba lagi')
            && str_contains($r['text'] ?? '', 'nanti')
            && str_contains(json_encode($r->data()), 'Saya sudah bergabung')
            && str_contains(json_encode($r->data()), 'community_gate:verify'));
    }

    public function test_successful_verification_deletes_prompt_and_sends_welcome_menu(): void
    {
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'telegram_chat_id' => '-100123',
            'channel_username' => '@store_channel',
            'community_gate_enabled' => true,
            'last_test_success' => true,
        ]);
        config()->set('services.telegram.welcome_banner_url', 'telegram-banner-file-id');
        $this->fakeGateHttpSequence(['left', 'member']);
        $this->postTelegramWebhook($this->messageUpdate(30, '/katalog', ['first_name' => 'Ayu']))->assertOk();
        $this->assertSame(1, TelegramCommunityGatePrompt::count());

        $this->postTelegramWebhook($this->callbackUpdate(31, 'verify', 'community_gate:verify', ['first_name' => 'Ayu']))->assertOk();

        $this->assertSame(0, TelegramCommunityGatePrompt::count());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/deleteMessage')
            && $r['chat_id'] === '12345'
            && $r['message_id'] === 77);
        Http::assertSent(fn (Request $r) => (str_ends_with($r->url(), '/sendMessage') || str_ends_with($r->url(), '/sendPhoto'))
            && str_contains((string) ($this->telegramRequestField($r, 'text') ?? $this->telegramRequestField($r, 'caption')), 'Ayu')
            && str_contains((string) ($this->telegramRequestField($r, 'text') ?? $this->telegramRequestField($r, 'caption')), 'selamat datang di LHIE STORE'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'] ?? '', 'Menu utama'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/answerCallbackQuery')
            && $r['callback_query_id'] === 'verify');
    }

    public function test_delete_prompt_failure_does_not_block_successful_verification(): void
    {
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'telegram_chat_id' => '-100123',
            'channel_username' => '@store_channel',
            'community_gate_enabled' => true,
            'last_test_success' => true,
        ]);
        config()->set('services.telegram.welcome_banner_url', 'telegram-banner-file-id');
        $this->fakeGateHttpSequence(['left', 'member'], deleteFails: true);
        $this->postTelegramWebhook($this->messageUpdate(35, '/katalog', ['first_name' => 'Ayu']))->assertOk();

        $this->postTelegramWebhook($this->callbackUpdate(36, 'verify-delete-fails', 'community_gate:verify', ['first_name' => 'Ayu']))->assertOk();

        $this->assertSame(0, TelegramCommunityGatePrompt::count());
        Http::assertSent(fn (Request $r) => (str_ends_with($r->url(), '/sendMessage') || str_ends_with($r->url(), '/sendPhoto'))
            && str_contains((string) ($this->telegramRequestField($r, 'text') ?? $this->telegramRequestField($r, 'caption')), 'Ayu')
            && str_contains((string) ($this->telegramRequestField($r, 'text') ?? $this->telegramRequestField($r, 'caption')), 'selamat datang di LHIE STORE'));
    }

    public function test_leaving_channel_blocks_later_store_flow_again(): void
    {
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'telegram_chat_id' => '-100123',
            'channel_username' => '@store_channel',
            'community_gate_enabled' => true,
            'last_test_success' => true,
        ]);
        $this->fakeGateHttpSequence(['member', 'left']);
        config()->set('services.telegram.community_gate_cache_seconds', 0);
        $this->postTelegramWebhook($this->messageUpdate(40, '/katalog'))->assertOk();
        $this->assertSame(0, TelegramCommunityGatePrompt::count());

        $this->postTelegramWebhook($this->messageUpdate(41, '/katalog'))->assertOk();

        $this->assertSame(1, TelegramCommunityGatePrompt::count());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'] ?? '', 'Gabung kanal dulu'));
    }

    private function fakeGateHttp(string $status, bool $isMember = false): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => function (Request $request) use ($status, $isMember) {
            if (str_ends_with($request->url(), '/getChatMember')) {
                return Http::response(['ok' => true, 'result' => ['status' => $status, 'is_member' => $isMember]]);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 77]]);
        }]);
    }

    /**
     * @param  list<string>  $statuses
     */
    private function fakeGateHttpSequence(array $statuses, bool $deleteFails = false): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => function (Request $request) use (&$statuses, $deleteFails) {
            if (str_ends_with($request->url(), '/getChatMember')) {
                $status = array_shift($statuses) ?? 'left';

                return Http::response(['ok' => true, 'result' => ['status' => $status]]);
            }
            if ($deleteFails && str_ends_with($request->url(), '/deleteMessage')) {
                return Http::response(['ok' => false], 400);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 77]]);
        }]);
    }

    private function sentCount(string $method, string $textContains): int
    {
        $count = 0;
        Http::assertSent(function (Request $request) use (&$count, $method, $textContains) {
            if (str_ends_with($request->url(), $method) && str_contains($request['text'] ?? '', $textContains)) {
                $count++;
            }

            return true;
        });

        return $count;
    }
}
