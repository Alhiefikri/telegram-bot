<?php

namespace Tests\Feature\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\StoreBalanceEntryKind;
use App\Models\Customer;
use App\Models\CustomerBalanceAccount;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TelegramNavigationRoot;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\Telegram\TelegramStoreStatsReport;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\TelegramBotTestSupport;

final class StoreBalanceHomeTest extends TelegramBotTestSupport
{
    public function test_private_home_renders_greeting_profile_store_activity_and_action_prompt(): void
    {
        Cache::flush();
        config()->set('services.telegram.welcome_banner_url', 'banner-file-id');
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create([
            'telegram_id' => 45301,
            'locale' => 'en',
            'first_name' => 'Ayu',
            'username' => 'ayu_store',
        ]);
        Customer::factory()->create(['telegram_id' => 45302]);
        $this->createSuccessfulOrder($customer);
        app(StoreBalanceLedger::class)->credit(
            $customer,
            25000,
            StoreBalanceEntryKind::TopUpCredit,
            'balance_top_up',
            '1',
            'balance_top_up:1:credit',
        );

        $this->postTelegramWebhook($this->messageUpdate(45301, '/start', [
            'id' => 45301,
            'first_name' => 'Ayu',
            'username' => 'ayu_store',
        ], 45301))->assertOk();

        $request = $this->homeRequest(45301);
        $caption = (string) $this->telegramRequestField($request, 'caption');

        $this->assertStringContainsString('Ayu', $caption);
        $this->assertStringContainsString('👤 My Profile', $caption);
        $this->assertStringContainsString('🆔 Telegram ID: 45301', $caption);
        $this->assertStringContainsString('🔗 Username: @ayu_store', $caption);
        $this->assertStringContainsString('💰 My Balance: Rp 25.000', $caption);
        $this->assertStringContainsString('📊 Store Activity', $caption);
        $this->assertStringContainsString('👥 Telegram users: 2', $caption);
        $this->assertStringContainsString('🧾 Successful orders: 1', $caption);
        $this->assertStringContainsString('Choose a button below', $caption);

        $positions = array_map(fn (string $needle): int|false => mb_strpos($caption, $needle), [
            'Ayu',
            'My Profile',
            'My Balance',
            'Store Activity',
            'Successful orders',
            'Choose a button below',
        ]);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'Home caption must order greeting → Profile → Store Activity → prompt.');

        $markup = $request->data()['reply_markup'];
        $this->assertArrayNotHasKey('keyboard', $markup);
        $this->assertSame(['katalog'], array_column($markup['inline_keyboard'][0], 'callback_data'));
        $this->assertSame(['balance', 'menu:orders'], array_column($markup['inline_keyboard'][1], 'callback_data'));
        $this->assertSame(['menu:language', 'menu:support'], array_column($markup['inline_keyboard'][2], 'callback_data'));

        $lowered = mb_strtolower($caption);
        foreach (['sold', 'terjual', 'revenue', 'gmv', 'earnings', 'subscription', 'langganan'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $lowered);
        }

        $parsed = html_entity_decode($caption, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertLessThanOrEqual(1024, mb_strlen($parsed));
        $this->assertSame(0, Http::recorded(fn (Request $recorded): bool => str_ends_with($recorded->url(), '/sendMessage')
            && (string) ($recorded['chat_id'] ?? '') === '45301')->count());
    }

    public function test_home_reads_the_customer_store_balance_fresh_and_never_from_the_shared_metrics_cache(): void
    {
        Cache::flush();
        config()->set('services.telegram.welcome_banner_url', 'banner-file-id');
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create([
            'telegram_id' => 45311,
            'locale' => 'en',
            'first_name' => 'Ayu',
            'username' => 'ayu_store',
        ]);
        $ledger = app(StoreBalanceLedger::class);

        // Warm the shared bounded-staleness global metrics cache first.
        app(TelegramStoreStatsReport::class)->startMetrics();
        $ledger->credit($customer, 10000, StoreBalanceEntryKind::TopUpCredit, 'balance_top_up', '11', 'balance_top_up:11:credit');

        $this->postTelegramWebhook($this->messageUpdate(45311, '/start', [], 45311))->assertOk();
        $root = TelegramNavigationRoot::query()->sole();
        $this->assertStringContainsString('My Balance: Rp 10.000', (string) $this->telegramRequestField($this->homeRequest(45311), 'caption'));

        $ledger->credit($customer, 5000, StoreBalanceEntryKind::TopUpCredit, 'balance_top_up', '12', 'balance_top_up:12:credit');

        $this->postTelegramWebhook($this->callbackUpdate(45312, 'locale-refresh', 'locale:en', [], 45311, 'private', $root->message_id))->assertOk();

        $edit = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageCaption')
            && (string) ($request['chat_id'] ?? '') === '45311')->first()[0];
        $this->assertStringContainsString('My Balance: Rp 15.000', (string) $this->telegramRequestField($edit, 'caption'));
        $this->assertStringContainsString('Successful orders: 0', (string) $this->telegramRequestField($edit, 'caption'));
    }

    public function test_home_fails_safe_instead_of_inventing_funds_when_the_projection_drifted(): void
    {
        Cache::flush();
        config()->set('services.telegram.welcome_banner_url', 'banner-file-id');
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create([
            'telegram_id' => 45321,
            'locale' => 'en',
            'first_name' => 'Ayu',
            'username' => 'ayu_store',
        ]);
        app(StoreBalanceLedger::class)->credit($customer, 10000, StoreBalanceEntryKind::TopUpCredit, 'balance_top_up', '21', 'balance_top_up:21:credit');
        CustomerBalanceAccount::query()->where('customer_id', $customer->id)->update(['balance' => 0]);

        $this->postTelegramWebhook($this->messageUpdate(45321, '/start', [], 45321))->assertOk();

        $caption = (string) $this->telegramRequestField($this->homeRequest(45321), 'caption');
        $this->assertStringContainsString('My Balance: Temporarily unavailable', $caption);
        $this->assertStringNotContainsString('Rp', $caption);
    }

    public function test_missing_username_uses_the_localized_missing_value(): void
    {
        Cache::flush();
        config()->set('services.telegram.welcome_banner_url', 'banner-file-id');
        $this->fakeTelegramHttp();
        Customer::factory()->create([
            'telegram_id' => 45331,
            'locale' => 'id',
            'first_name' => 'Ayu',
            'username' => null,
        ]);

        $this->postTelegramWebhook($this->messageUpdate(45331, '/start', [], 45331))->assertOk();

        $caption = (string) $this->telegramRequestField($this->homeRequest(45331), 'caption');
        $this->assertStringContainsString('Username: Belum diatur', $caption);
        $this->assertStringContainsString('Saldo Saya: Rp 0', $caption);
        $this->assertStringContainsString('Pesanan berhasil:', $caption);
    }

    public function test_group_welcome_stays_public_safe_without_identity_balance_or_balance_actions(): void
    {
        Cache::flush();
        config()->set('services.telegram.welcome_banner_url', 'banner-file-id');
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create([
            'telegram_id' => 45341,
            'locale' => 'en',
            'first_name' => 'Group Member',
            'username' => 'group_user',
        ]);
        $this->createSuccessfulOrder($customer);

        $this->postTelegramWebhook($this->messageUpdate(45341, '/start', [
            'id' => 45341,
            'first_name' => 'Group Member',
            'username' => 'group_user',
        ], -10045341, 'group'))->assertOk();

        $request = $this->homeRequest(-10045341);
        $caption = (string) $this->telegramRequestField($request, 'caption');

        $this->assertStringContainsString('Telegram users:', $caption);
        $this->assertStringContainsString('Successful orders: 1', $caption);
        $this->assertStringNotContainsString('45341', $caption);
        $this->assertStringNotContainsString('@group_user', $caption);
        $this->assertStringNotContainsString('Rp', $caption);
        $this->assertStringNotContainsString('My Balance', $caption);
        $this->assertStringNotContainsString('My Profile', $caption);
        $this->assertStringNotContainsString('balance', json_encode($request->data()['reply_markup'], JSON_THROW_ON_ERROR));
        $this->assertSame(0, TelegramNavigationRoot::query()->count());
    }

    private function createSuccessfulOrder(Customer $customer): Order
    {
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        return $order;
    }

    private function homeRequest(int $chatId): Request
    {
        return Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto')
            && (string) ($request['chat_id'] ?? '') === (string) $chatId)->first()[0];
    }
}
