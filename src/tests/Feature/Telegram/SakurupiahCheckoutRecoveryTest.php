<?php

namespace Tests\Feature\Telegram;

use App\Jobs\ProcessTelegramUpdate;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\CartService;
use App\Services\Telegram\Callbacks\TelegramCheckoutCallbackHandler;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramIntentSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TelegramBotTestSupport;

class SakurupiahCheckoutRecoveryTest extends TelegramBotTestSupport
{
    public function test_coordination_outage_preserves_cart_without_dispatching_or_claiming_a_retry_token(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        $product = Product::factory()->create(['stock' => 20, 'price_amount' => 600]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->setItemQuantity($cart, $product, 10);
        $this->fakeTelegramHttp();
        $handler = app(TelegramCheckoutCallbackHandler::class);
        $cache = \Mockery::mock(Cache::getFacadeRoot());
        $cache->shouldReceive('lock')->andThrow(new \RuntimeException('coordination unavailable'));
        Cache::swap($cache);
        $handler->handle(new TelegramCallbackContext(
            $customer, '12345', 'fixture-outage', 'checkout:current', 'private', 123457,
        ));
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(10, CartItem::query()->sole()->quantity);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'sakurupiah.id'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && ! str_contains(json_encode($request['reply_markup'] ?? []), 'checkout:retry:'));
    }

    #[DataProvider('recoveryChanges')]
    public function test_retry_is_owned_and_revalidates_purchase_snapshot(string $change): void
    {
        Queue::fakeExcept([ProcessTelegramUpdate::class]);
        config()->set('services.sakurupiah.sandbox_enabled', true);
        config()->set('services.sakurupiah.sandbox_api_id', 'fixture-id');
        config()->set('services.sakurupiah.sandbox_api_key', 'fixture-key');
        $this->fakeTelegramHttp(['https://sakurupiah.id/api-sanbox/list-payment.php' => Http::sequence()
            ->push(['status' => '400', 'message' => 'Permintaan terlalu cepat silahkan coba lagi nanti.'], 400)
            ->push(['status' => '200', 'data' => [['kode' => 'QRIS', 'nama' => 'QRIS', 'minimal' => 500,
                'maksimal' => 2_000_000, 'tipe' => 'DIRECT', 'status' => 'Aktif', 'addition' => ['default_expired' => 24]]]])]);
        $setting = ManualPaymentSetting::create(['payment_mode' => 'sakurupiah', 'sakurupiah_channel' => 'QRIS', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'en']);
        $other = Customer::factory()->create(['telegram_id' => 12346, 'locale' => 'en']);
        $product = Product::factory()->create(['price_amount' => 600, 'stock' => 20, 'reserved_stock' => 0, 'is_active' => true]);
        $this->postTelegramWebhook($this->callbackUpdate(71001, 'open', 'product:'.$product->id))->assertOk();
        $selection = app(TelegramIntentSession::class)->get($customer);
        $this->postTelegramWebhook($this->callbackUpdate(71002, 'custom', 'detail:qty:manual:'.$product->id.':'.$selection['interactionToken'], messageId: 123457))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(71003, '10'))->assertOk();
        $selection = app(TelegramIntentSession::class)->get($customer);
        $original = 'detail:pay:qris:'.$product->id.':'.$selection['interactionToken'];
        $this->postTelegramWebhook($this->callbackUpdate(71004, 'pay', $original, messageId: 123457))->assertOk();
        $retry = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains(json_encode($request['reply_markup'] ?? []), 'checkout:retry:'))->last();
        $this->assertNotNull($retry);
        $callback = $retry[0]['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $this->assertSame(10, CartItem::query()->sole()->quantity);
        $this->assertSame(0, Order::query()->count());
        $this->postTelegramWebhook($this->callbackUpdate(71005, 'foreign', $callback, chatId: 12346, messageId: 123457))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(71006, 'wrong-message', $callback, messageId: 999))->assertOk();
        $this->assertSame(0, Order::query()->count());
        $this->assertNull($other->carts()->first());
        $this->travel(60)->seconds();
        match ($change) {
            'settings' => $setting->increment('payment_provider_version'),
            'channel' => $setting->update(['sakurupiah_channel' => 'QRISC']),
            'price' => $product->update(['price_amount' => 700]),
            'stock' => $product->forceFill(['stock' => 0])->save(),
            'selection' => app(TelegramIntentSession::class)->start($customer, $product->id, 1, 'en'),
            default => null,
        };
        if ($change === 'stock') {
            $this->assertSame(0, $product->fresh()->availableStock(), 'Fixture must deplete available stock before retry.');
        }
        $this->postTelegramWebhook($this->callbackUpdate(71007, 'retry', $callback, messageId: 123457))->assertOk();
        if ($change === 'none') {
            $this->postTelegramWebhook($this->callbackUpdate(71008, 'duplicate-retry', $callback, messageId: 123457))->assertOk();
            $this->postTelegramWebhook($this->callbackUpdate(71009, 'duplicate-original', $original, messageId: 123457))->assertOk();
            $this->assertSame(1, Order::query()->count());
            $this->assertSame(1, Payment::query()->count());
            $this->assertSame(6000, Order::query()->sole()->total_amount);
            $this->assertSame(10, $product->fresh()->reserved_stock);
        } else {
            $this->assertSame(0, Order::query()->count());
            $this->assertSame(0, Payment::query()->count());
            $this->assertSame(0, $product->fresh()->reserved_stock);
            $this->assertSame(10, CartItem::query()->sole()->quantity);
        }
    }

    public static function recoveryChanges(): array
    {
        return array_combine(['none', 'settings', 'channel', 'price', 'stock', 'selection'],
            array_map(fn ($value) => [$value], ['none', 'settings', 'channel', 'price', 'stock', 'selection']));
    }
}
