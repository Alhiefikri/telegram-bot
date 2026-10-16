<?php

namespace Tests\Feature\Telegram;

use App\Enums\BalancePaymentPreference;
use App\Enums\StoreBalanceEntryKind;
use App\Enums\TelegramNavigationScreen;
use App\Models\Customer;
use App\Models\CustomerApiCredential;
use App\Models\Order;
use App\Models\Product;
use App\Models\TelegramNavigationRoot;
use App\Models\TelegramUiIcon;
use App\Services\CustomerApi\CustomerApiCredentials;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\Telegram\CustomEmojiCapability;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\VerifiedUsdtFunding;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TelegramBotTestSupport;

final class TelegramCustomerApiManagementTest extends TelegramBotTestSupport
{
    private int $update = 800000;

    private array $sent = [];

    private ?string $displayFailure = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Lifecycle/navigation tests isolate shared admission; real Redis budgets have their own PostgreSQL lane.
        $connection = Mockery::mock();
        $connection->shouldReceive('eval')->andReturn([1, 3, 2, 2000000000, 0]);
        /** @var RedisStore&MockInterface $store */
        $store = Mockery::mock(RedisStore::class)->makePartial();
        $store->shouldReceive('connection')->andReturn($connection);
        $store->shouldReceive('getPrefix')->andReturn('s08-test:');
        Cache::extend('s08-admission', fn () => new Repository($store));
        config(['cache.stores.s08-admission' => ['driver' => 's08-admission'], 'cache.limiter' => 's08-admission',
            'services.telegram.welcome_banner_url' => 'banner-file-id']);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $payload = $request->data();
            $this->sent[] = ['method' => basename($request->url()), ...$payload];
            if ($this->displayFailure !== null && str_contains((string) ($payload['text'] ?? ''), 'capi_')) {
                return match ($this->displayFailure) {
                    'timeout' => throw new ConnectionException('Synthetic secret-display timeout'),
                    'rejected' => Http::response(['ok' => false, 'description' => 'Bad Request'], 400),
                    'missing-message-id' => Http::response(['ok' => true, 'result' => []]),
                };
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => count($this->sent) + 700, 'photo' => [['file_id' => 'banner-file-id']]]]);
        });
    }

    public function test_generate_reopen_rotate_and_disable_never_recover_secrets_or_revoke_a_newer_key(): void
    {
        $customer = $this->start();
        $root = TelegramNavigationRoot::query()->sole();
        $this->press('api:menu');
        $this->assertContains('api:generate', $this->callbacks());
        $docs = collect($this->buttons())->firstWhere('url', url('/api-docs/'));
        $this->assertNotNull($docs);
        $this->get($docs['url'])->assertOk();
        $this->press('api:generate');
        $old = $this->secret();
        $this->assertNotNull(app(CustomerApiCredentials::class)->authenticate('Bearer '.$old));
        $order = Order::factory()->for($customer)->create();
        $product = Product::factory()->create();
        app(StoreBalanceLedger::class)->credit($customer, 25000, StoreBalanceEntryKind::TopUpCredit, 'balance_top_up', '627', 's08:history');
        app(CustomerUsdtBalanceLedger::class)->creditVerifiedTopUp($customer,
            new VerifiedUsdtFunding($customer->id, UsdtMicroAmount::fromUnits(2000000), 's08-funding', 's08-receipt', 'v1'),
            's08-usdt-history');
        DB::table('customer_api_order_claims')->insert([
            'customer_id' => $customer->id, 'operation' => 'create_order', 'idempotency_key' => 'retained-after-rotation',
            'fingerprint' => str_repeat('a', 64), 'credential_id' => CustomerApiCredential::query()->sole()->id,
            'state' => 'accepted', 'preference' => 'idr_first', 'preference_source' => 'account',
            'product_id' => $product->id, 'quantity' => 1, 'expected_idr_total' => 1000, 'order_id' => $order->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $history = [];
        foreach (['orders', 'customer_balance_accounts', 'store_balance_entries', 'customer_usdt_balance_accounts', 'customer_usdt_balance_entries', 'customer_api_order_claims'] as $table) {
            $history[$table] = json_encode(DB::table($table)->get(), JSON_THROW_ON_ERROR);
        }
        $this->press('api:generate');
        $this->assertSame(1, CustomerApiCredential::query()->count());
        $this->assertCount(1, $this->secretMessages());
        $this->press('menu:home');
        $this->press('api:menu');
        $this->assertNotContains('api:generate', $this->callbacks());
        $this->assertStringNotContainsString($old, json_encode($this->lastEdit(), JSON_THROW_ON_ERROR));
        $this->press('api:disable');
        $staleDisable = $this->callbacks()[0];
        $this->press('api:rotate');
        $rotate = $this->callbacks()[0];
        $this->press($rotate);
        $new = $this->secret();
        $this->assertNotSame($old, $new);
        $this->assertNull(app(CustomerApiCredentials::class)->authenticate('Bearer '.$old));
        $this->press($rotate);
        $this->press($staleDisable);
        $this->assertNotNull(app(CustomerApiCredentials::class)->authenticate('Bearer '.$new));
        $this->assertCount(2, $this->secretMessages());
        $this->assertSame(2, CustomerApiCredential::query()->count());
        $this->press('api:disable');
        $disable = $this->callbacks()[0];
        $this->press($disable);
        $this->press($disable);
        $this->assertNull(app(CustomerApiCredentials::class)->authenticate('Bearer '.$new));
        $this->assertContains('api:generate', $this->callbacks());
        $this->press('menu:back');
        $this->assertSame(TelegramNavigationScreen::Home, $root->fresh()->screen);
        foreach ($history as $table => $snapshot) {
            $this->assertSame($snapshot, json_encode(DB::table($table)->get(), JSON_THROW_ON_ERROR), $table.' survives key replacement/revocation.');
        }
        foreach ([$old, $new] as $secret) {
            foreach (['customer_api_credentials', 'telegram_navigation_roots', 'telegram_updates', 'telegram_outbound_effects', 'jobs'] as $table) {
                $this->assertStringNotContainsString($secret, json_encode(DB::table($table)->get(), JSON_THROW_ON_ERROR));
                $this->assertStringNotContainsString(substr($secret, -64), json_encode(DB::table($table)->get(), JSON_THROW_ON_ERROR));
            }
        }
        $this->assertSame($customer->id, CustomerApiCredential::query()->first()->customer_id);
    }

    #[DataProvider('displayFailures')]
    public function test_failed_initial_display_is_not_retried_and_reopen_offers_only_regeneration(string $failure): void
    {
        $this->start();
        $this->displayFailure = $failure;
        $this->press('api:generate');
        $this->assertSame(1, CustomerApiCredential::query()->whereNull('revoked_at')->count());
        $this->assertCount(1, $this->secretMessages());
        $this->assertContains('api:rotate', $this->callbacks());
        $this->assertNotContains('api:generate', $this->callbacks());
        $this->press('api:menu');
        $this->assertCount(1, $this->secretMessages());
        $this->assertStringNotContainsString($this->secret(), json_encode($this->lastEdit(), JSON_THROW_ON_ERROR));
    }

    public static function displayFailures(): array
    {
        return [['timeout'], ['rejected'], ['missing-message-id']];
    }

    public function test_group_foreign_actor_stale_root_and_wrong_purpose_confirmations_have_no_authority(): void
    {
        $this->start();
        $this->press('api:generate');
        $secret = $this->secret();
        $this->press('api:disable');
        $disable = $this->callbacks()[0];
        $this->press(str_replace('api:disable:', 'api:rotate:', $disable));
        $root = TelegramNavigationRoot::query()->sole();
        foreach ([['group', 88770, 88770, $root->message_id], ['private', 88771, 88770, $root->message_id], ['private', 88770, 88770, $root->message_id + 999]] as [$type, $actor, $chat, $message]) {
            $before = count($this->secretMessages());
            $this->postTelegramWebhook($this->callbackUpdate(++$this->update, 'bad-'.$this->update, $disable, ['id' => $actor], $chat, $type, $message))->assertOk();
            $this->assertCount($before, $this->secretMessages());
            $this->assertNotNull(app(CustomerApiCredentials::class)->authenticate('Bearer '.$secret));
        }
        $this->postTelegramWebhook($this->messageUpdate(++$this->update, '/start', [], 88770))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(++$this->update, 'old-root', $disable, [], 88770, 'private', $root->message_id))->assertOk();
        $this->assertNotNull(app(CustomerApiCredentials::class)->authenticate('Bearer '.$secret));
        $this->assertSame(1, CustomerApiCredential::query()->count());
    }

    public function test_all_preferences_save_without_mutating_credential_or_account_history(): void
    {
        $customer = $this->start();
        foreach (BalancePaymentPreference::cases() as $preference) {
            $this->press('api:preferences');
            $this->assertContains('api:preference:'.$preference->value, $this->callbacks());
            $this->press('api:preference:'.$preference->value);
            $this->assertSame($preference, $customer->fresh()->balance_payment_preference);
        }
        $this->assertSame(0, CustomerApiCredential::query()->count());
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(0, DB::table('store_balance_entries')->count());
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
    }

    public function test_unavailable_limiter_does_not_create_a_key(): void
    {
        $this->start();
        config(['cache.limiter' => 'array']);
        $this->press('api:generate');
        $this->assertSame(0, CustomerApiCredential::query()->count());
        $this->assertCount(0, $this->secretMessages());
    }

    public function test_api_custom_icon_and_unicode_fallback_keep_the_same_action(): void
    {
        TelegramUiIcon::query()->where('key', 'menu.api')->sole()->update(['custom_emoji_id' => '5368324170671202286']);
        app(CustomEmojiCapability::class)->markAvailable();
        $this->start();
        $custom = collect($this->homeButtons())->firstWhere('callback_data', 'api:menu');
        $this->assertSame('5368324170671202286', $custom['icon_custom_emoji_id']);
        $this->assertSame('primary', $custom['style']);
        app(CustomEmojiCapability::class)->markUnavailable('Synthetic unsupported custom emoji');
        $this->postTelegramWebhook($this->messageUpdate(++$this->update, '/start', [], 88770))->assertOk();
        $fallback = collect($this->homeButtons())->firstWhere('callback_data', 'api:menu');
        $this->assertSame($custom['callback_data'], $fallback['callback_data']);
        $this->assertArrayNotHasKey('icon_custom_emoji_id', $fallback);
        $this->assertStringContainsString('🔌', $fallback['text']);
    }

    private function start(): Customer
    {
        $customer = Customer::factory()->create(['telegram_id' => 88770, 'locale' => 'en']);
        $this->postTelegramWebhook($this->messageUpdate(++$this->update, '/start', [], 88770))->assertOk();

        return $customer;
    }

    private function press(string $data): void
    {
        $root = TelegramNavigationRoot::query()->where('chat_id', '88770')->sole();
        $this->postTelegramWebhook($this->callbackUpdate(++$this->update, 's08-'.$this->update, $data, [], 88770, 'private', $root->message_id))->assertOk();
    }

    private function lastEdit(): array
    {
        return collect($this->sent)->filter(fn ($send) => in_array($send['method'], ['editMessageText', 'editMessageCaption'], true))->last();
    }

    private function buttons(): array
    {
        return array_merge(...$this->lastEdit()['reply_markup']['inline_keyboard']);
    }

    private function callbacks(): array
    {
        return array_column($this->buttons(), 'callback_data');
    }

    private function homeButtons(): array
    {
        $send = collect($this->sent)->last(fn ($send) => $send['method'] === 'sendPhoto');
        $markup = $send['reply_markup'];
        if (is_string($markup)) {
            $markup = json_decode($markup, true, flags: JSON_THROW_ON_ERROR);
        }

        return array_merge(...$markup['inline_keyboard']);
    }

    private function secretMessages(): array
    {
        return array_values(array_filter($this->sent, fn ($send) => $send['method'] === 'sendMessage' && str_contains((string) ($send['text'] ?? ''), 'capi_')));
    }

    private function secret(): string
    {
        $messages = $this->secretMessages();
        preg_match('/capi_[a-f0-9]{32}\.[a-f0-9]{64}/', $messages[array_key_last($messages)]['text'], $match);

        return $match[0];
    }
}
