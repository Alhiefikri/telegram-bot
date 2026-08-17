<?php

namespace Tests\Feature\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Services\Telegram\TelegramStoreStatsReport;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TelegramBotTestSupport;

class StartMetricsTest extends TelegramBotTestSupport
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public static function locales(): array
    {
        return [
            'indonesian' => ['id', 'Terjual: 7 unit', 'Pengguna bot: 2', 'Nama: Ayu', 'Username: @ayu_store'],
            'english' => ['en', 'Sold: 7', 'Bot users: 2', 'Name: Ayu', 'Username: @ayu_store'],
            'malay' => ['ms', 'Terjual: 7 unit', 'Pengguna bot: 2', 'Nama: Ayu', 'Nama pengguna: @ayu_store'],
        ];
    }

    #[DataProvider('locales')]
    public function test_start_sends_one_localized_banner_with_metrics_and_persistent_menu(
        string $locale,
        string $soldLine,
        string $usersLine,
        string $nameLine,
        string $usernameLine,
    ): void {
        Cache::flush();
        config()->set('services.telegram.welcome_banner_url', 'banner-file-id');
        $webCustomer = Customer::factory()->create(['telegram_id' => null]);
        Customer::factory()->create(['telegram_id' => 54321]);
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => $locale]);
        $this->createOrderWithPayment($webCustomer, PaymentStatus::Paid, 7);
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(901, '/start', [
            'id' => 12345,
            'first_name' => 'Ayu',
            'username' => 'ayu_store',
        ]))->assertOk();

        $photos = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto'));
        $this->assertCount(1, $photos);
        $request = $photos->first()[0];
        $caption = (string) $this->telegramRequestField($request, 'caption');

        $this->assertStringContainsString($soldLine, $caption);
        $this->assertStringContainsString($usersLine, $caption);
        $this->assertStringContainsString($nameLine, $caption);
        $this->assertStringContainsString($usernameLine, $caption);
        $this->assertTrue($request->data()['reply_markup']['is_persistent'] ?? false);
        $this->assertArrayHasKey('keyboard', $request->data()['reply_markup']);
        $this->assertArrayNotHasKey('inline_keyboard', $request->data()['reply_markup']);
        $this->assertStringNotContainsString('Rp', $caption);
        $this->assertStringNotContainsString('rupiah', strtolower($caption));
        $this->assertStringNotContainsString('revenue', strtolower($caption));
        $this->assertStringNotContainsString('gmv', strtolower($caption));
        $this->assertStringNotContainsString('earnings', strtolower($caption));
        $this->assertStringNotContainsString('transaction value', strtolower($caption));
    }

    public function test_start_group_keeps_public_metrics_but_omits_personal_fields(): void
    {
        Cache::flush();
        config()->set('services.telegram.welcome_banner_url', 'banner-file-id');
        $webCustomer = Customer::factory()->create(['telegram_id' => null]);
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'en']);
        $this->createOrderWithPayment($webCustomer, PaymentStatus::Paid, 4);
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(902, '/start', [
            'id' => 12345,
            'first_name' => 'Group Name',
            'username' => 'group_user',
        ], -100123, 'group'))->assertOk();

        $request = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto'))->first()[0];
        $caption = (string) $this->telegramRequestField($request, 'caption');

        $this->assertStringContainsString('Sold: 4', $caption);
        $this->assertStringContainsString('Bot users: 1', $caption);
        $this->assertStringNotContainsString('Group Name', $caption);
        $this->assertStringNotContainsString('@group_user', $caption);
        $this->assertArrayNotHasKey('web_app', $request->data()['reply_markup']['keyboard'][2][0] ?? []);
    }

    public function test_group_language_selection_keeps_the_welcome_caption_public(): void
    {
        Cache::flush();
        config()->set('services.telegram.welcome_banner_url', 'banner-file-id');
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(904, '/start', [
            'id' => 12345,
            'first_name' => 'Group Name',
            'username' => 'group_user',
        ], -100123, 'group'))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(905, 'group-locale', 'locale:en', [
            'id' => 12345,
            'first_name' => 'Group Name',
            'username' => 'group_user',
        ], -100123, 'group'))->assertOk();

        $request = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto'))->first()[0];
        $caption = (string) $this->telegramRequestField($request, 'caption');

        $this->assertStringContainsString('Sold: 0', $caption);
        $this->assertStringNotContainsString('Group Name', $caption);
        $this->assertStringNotContainsString('@group_user', $caption);
    }

    public function test_start_uses_safe_placeholders_when_private_profile_fields_are_missing(): void
    {
        Cache::flush();
        config()->set('services.telegram.welcome_banner_url', 'banner-file-id');
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'en']);
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(903, '/start', [
            'id' => 12345,
            'first_name' => '',
        ]))->assertOk();

        $request = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto'))->first()[0];
        $caption = (string) $this->telegramRequestField($request, 'caption');

        $this->assertStringContainsString('Name: -', $caption);
        $this->assertStringContainsString('Username: -', $caption);
    }

    public function test_start_metrics_sum_only_canonical_paid_order_items(): void
    {
        Cache::flush();
        $customer = Customer::factory()->create(['telegram_id' => null]);
        $this->createOrderWithPayment($customer, PaymentStatus::Paid, 3);
        $this->createOrderWithPayment($customer, PaymentStatus::Paid, 90, OrderStatus::Cancelled);
        $this->createOrderWithPayment($customer, PaymentStatus::Pending, 50);
        $this->createOrderWithPayment($customer, PaymentStatus::Failed, 60, OrderStatus::Cancelled);
        $this->createOrderWithPayment($customer, PaymentStatus::Expired, 70, OrderStatus::Expired);
        $this->createOrderWithPayment($customer, PaymentStatus::Refunded, 80, OrderStatus::Completed);

        $metrics = app(TelegramStoreStatsReport::class)->startMetrics();

        $this->assertSame(3, $metrics['sold_units']);
        $this->assertSame(0, $metrics['bot_users']);
    }

    public function test_start_metrics_use_aggregate_queries_and_a_short_lived_cache(): void
    {
        Cache::flush();
        config()->set('services.telegram.start_metrics_cache_seconds', 60);
        $customer = Customer::factory()->create(['telegram_id' => null]);
        $this->createOrderWithPayment($customer, PaymentStatus::Paid, 3);
        Customer::factory()->create(['telegram_id' => 12345]);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_starts_with(strtolower(trim($query->sql)), 'select')) {
                $queries[] = strtolower($query->sql);
            }
        });

        $first = app(TelegramStoreStatsReport::class)->startMetrics();
        $queryCountAfterFirstRead = count($queries);
        $second = app(TelegramStoreStatsReport::class)->startMetrics();

        $this->assertSame($first, $second);
        $this->assertSame(2, $queryCountAfterFirstRead);
        $this->assertCount(2, $queries);
        $this->assertTrue(collect($queries)->contains(fn (string $query): bool => str_contains($query, 'sum(')));
        $this->assertTrue(collect($queries)->contains(fn (string $query): bool => str_contains($query, 'count(')));
        $this->assertTrue(Cache::has('telegram:start:metrics'));
    }

    private function createOrderWithPayment(
        Customer $customer,
        PaymentStatus $paymentStatus,
        int $quantity,
        ?OrderStatus $orderStatus = null,
    ): Order {
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'order_status' => $orderStatus ?? ($paymentStatus === PaymentStatus::Paid ? OrderStatus::Completed : OrderStatus::PendingPayment),
            'payment_status' => $paymentStatus,
        ]);
        OrderItem::factory()->for($order)->create(['quantity' => $quantity, 'subtotal' => $quantity * 10000]);
        Payment::factory()->for($order)->create([
            'status' => $paymentStatus,
            'paid_at' => $paymentStatus === PaymentStatus::Paid ? now() : null,
        ]);

        return $order;
    }
}
