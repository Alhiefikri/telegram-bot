<?php

namespace Tests\Feature\Telegram;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\StoreChannelSetting;
use App\Models\TelegramCommunityGatePrompt;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\TelegramBotTestSupport;

class CommunityGateTest extends TelegramBotTestSupport
{
    public function test_non_member_is_blocked_from_store_flow_and_prompt_is_reused(): void
    {
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'telegram_chat_id' => '-100123',
            'channel_username' => '@store_channel',
            'community_gate_enabled' => true,
            'last_test_success' => true,
        ]);
        $product = Product::factory()->create(['is_active' => true, 'stock' => 5, 'reserved_stock' => 0]);
        $this->fakeGateHttp('left');

        $this->postTelegramWebhook($this->messageUpdate(1, '/katalog'))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(2, 'cb-buy', 'buy:'.$product->id))->assertOk();

        $this->assertSame(1, TelegramCommunityGatePrompt::count());
        $this->assertSame(0, CartItem::count());
        $this->assertSame(1, $this->sentCount('/sendMessage', 'Join channel dulu ya'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/getChatMember')
            && $r['chat_id'] === '-100123'
            && $r['user_id'] === 12345);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains(json_encode($r->data()), 'Saya Sudah Join')
            && str_contains(json_encode($r->data()), 'community_gate:verify'));
    }

    public function test_members_are_allowed_and_invalid_setup_fails_open(): void
    {
        StoreChannelSetting::create(['singleton_key' => StoreChannelSetting::SINGLETON_KEY, 'community_gate_enabled' => true, 'telegram_chat_id' => null, 'last_test_success' => false]);
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(10, '/katalog'))->assertOk();

        $this->assertSame(0, TelegramCommunityGatePrompt::count());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && str_contains($r['text'] ?? '', 'Produk'));
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

    public function test_get_chat_member_failure_fails_open_to_avoid_bad_setup_lockout(): void
    {
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'telegram_chat_id' => '-100-bad',
            'community_gate_enabled' => true,
            'last_test_success' => true,
        ]);
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => function (Request $request) {
            if (str_ends_with($request->url(), '/getChatMember')) {
                return Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 77]]);
        }]);

        $this->postTelegramWebhook($this->messageUpdate(20, '/katalog'))->assertOk();

        $this->assertSame(0, TelegramCommunityGatePrompt::count());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && str_contains($r['text'] ?? '', 'Produk'));
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
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'] ?? '', 'Selamat')
            && str_contains($r['text'] ?? '', 'Ayu')
            && str_contains($r['text'] ?? '', 'welcome ke LHIE STORE'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'] ?? '', 'Menu utama'));
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
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'] ?? '', 'Selamat')
            && str_contains($r['text'] ?? '', 'Ayu')
            && str_contains($r['text'] ?? '', 'welcome ke LHIE STORE'));
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
        $this->postTelegramWebhook($this->messageUpdate(40, '/katalog'))->assertOk();
        $this->assertSame(0, TelegramCommunityGatePrompt::count());

        $this->postTelegramWebhook($this->messageUpdate(41, '/katalog'))->assertOk();

        $this->assertSame(1, TelegramCommunityGatePrompt::count());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'] ?? '', 'Join channel dulu ya'));
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
