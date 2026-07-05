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
                && str_contains($r['text'] ?? '', 'join channel')
                && str_contains($r['text'] ?? '', 'coba lagi')
                && str_contains($r['text'] ?? '', 'nanti')
                && str_contains(json_encode($r->data()), 'Saya Sudah Join')
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

    public function test_get_chat_member_failure_fails_closed_with_retry_guidance(): void
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

        $this->assertSame(1, TelegramCommunityGatePrompt::count());
        $this->assertSame(0, $this->sentCount('/sendMessage', 'Produk'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'] ?? '', 'belum bisa dicek')
            && str_contains($r['text'] ?? '', 'join channel')
            && str_contains($r['text'] ?? '', 'coba lagi')
            && str_contains($r['text'] ?? '', 'nanti')
            && str_contains(json_encode($r->data()), 'Saya Sudah Join')
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
