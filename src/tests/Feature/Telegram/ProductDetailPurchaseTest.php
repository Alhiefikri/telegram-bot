<?php

namespace Tests\Feature\Telegram;

use App\Enums\CartStatus;
use App\Enums\TelegramOutboundEffectType;
use App\Models\Cart;
use App\Models\CheckoutMaintenanceSetting;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\TelegramOutboundEffect;
use App\Services\Telegram\TelegramIntentSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\TelegramBotTestSupport;

class ProductDetailPurchaseTest extends TelegramBotTestSupport
{
    public function test_product_detail_has_isolated_quantity_and_payment_rows_and_rotates_stale_controls(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 318001, 'locale' => 'en']);
        $product = Product::factory()->create([
            'name' => 'Detail Product',
            'price_amount' => 10000,
            'stock' => 3,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $this->openDetail($customer, $product);
        $detail = $this->lastDetailMessage('Detail Product');
        $keyboard = $detail['reply_markup']['inline_keyboard'];

        $this->assertCount(4, $keyboard);
        $this->assertCount(3, $keyboard[0]);
        $this->assertCount(1, $keyboard[1]);
        $this->assertCount(1, $keyboard[2]);
        $this->assertCount(1, $keyboard[3]);
        $this->assertSame('success', $keyboard[2][0]['style'] ?? null);
        $this->assertSame('💳 Pay with QRIS · Rp10.000', $keyboard[2][0]['text']);
        $this->assertStringContainsString('Quantity: 1', (string) $detail['text']);
        $this->assertStringContainsString('Subtotal: Rp10.000', (string) $detail['text']);
        $this->assertStringNotContainsString('checkout.payment_method', json_encode($detail, JSON_THROW_ON_ERROR));

        $state = $this->selection($customer);
        $oldToken = $state['interactionToken'];
        $this->assertSame('browsing', $state['mode']);
        $this->assertSame(1, $state['quantity']);
        $this->assertMatchesRegularExpression('/^detail:qty:\+:'.$product->id.':[A-Za-z0-9_-]+$/', $keyboard[0][2]['callback_data']);

        $this->postTelegramWebhook($this->callbackUpdate(3180011, 'decrease-at-floor', 'detail:qty:-:'.$product->id.':'.$oldToken, [], 318001, 'private', 88001))->assertOk();
        $this->assertSame(1, $this->selection($customer)['quantity']);
        $this->assertSame($oldToken, $this->selection($customer)['interactionToken']);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && (string) ($request['callback_query_id'] ?? '') === 'decrease-at-floor'
            && str_contains((string) ($request['text'] ?? ''), 'minimum'));

        $this->postTelegramWebhook($this->callbackUpdate(318002, 'increase', 'detail:qty:+:'.$product->id.':'.$oldToken, [], 318001, 'private', 88001))->assertOk();
        $next = $this->selection($customer);
        $this->assertSame(2, $next['quantity']);
        $this->assertNotSame($oldToken, $next['interactionToken']);
        $nextDetail = $this->lastDetailMessage('Detail Product');
        $this->assertStringContainsString('Subtotal: Rp20.000', (string) $nextDetail['text']);
        $this->assertSame('💳 Pay with QRIS · Rp20.000', $nextDetail['reply_markup']['inline_keyboard'][2][0]['text']);

        $messagesBeforeStale = $this->detailMessageCount('Detail Product');
        $this->postTelegramWebhook($this->callbackUpdate(318003, 'stale-increase', 'detail:qty:+:'.$product->id.':'.$oldToken, [], 318001, 'private', 88001))->assertOk();
        $this->assertSame($messagesBeforeStale, $this->detailMessageCount('Detail Product'));
        $this->assertSame(0, Cart::count());
        $this->assertSame(0, Order::count());
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && str_contains((string) ($request['text'] ?? ''), 'expired'));
    }

    public function test_current_quantity_callback_revalidates_live_stock_without_side_effects(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 318006, 'locale' => 'en']);
        $product = Product::factory()->create([
            'name' => 'Current Quantity Product',
            'price_amount' => 10000,
            'stock' => 1,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $this->openDetail($customer, $product);
        $state = $this->selection($customer);
        $product->forceFill(['stock' => 0])->save();

        $this->postTelegramWebhook($this->callbackUpdate(
            3180061,
            'current-after-stock-change',
            'detail:qty:current:'.$product->id.':'.$state['interactionToken'],
            [],
            318006,
            'private',
            88006,
        ))->assertOk();

        $this->assertNull(app(TelegramIntentSession::class)->get($customer));
        $this->assertSame(0, Cart::count());
        $this->assertSame(0, Order::count());
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && (string) ($request['callback_query_id'] ?? '') === 'current-after-stock-change'
            && str_contains((string) ($request['text'] ?? ''), 'no longer available'));
    }

    public function test_custom_quantity_retries_locally_and_valid_input_renders_a_fresh_detail(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 318011, 'locale' => 'en']);
        $product = Product::factory()->create(['name' => 'Custom Product', 'price_amount' => 12000, 'stock' => 4, 'reserved_stock' => 0, 'is_active' => true]);

        $this->openDetail($customer, $product);
        $state = $this->selection($customer);
        $this->postTelegramWebhook($this->callbackUpdate(318012, 'manual', 'detail:qty:manual:'.$product->id.':'.$state['interactionToken'], [], 318011, 'private', 88002))->assertOk();
        $awaiting = $this->selection($customer);
        $this->assertSame('awaiting_custom_quantity', $awaiting['mode']);
        $this->assertSame(1, $awaiting['previousQuantity']);

        $this->postTelegramWebhook($this->messageUpdate(318013, '1.5', [], 318011))->assertOk();
        $this->assertSame('awaiting_custom_quantity', $this->selection($customer)['mode']);
        $this->assertStringContainsString('positive whole number', (string) $this->lastMessageText('Custom Product'));
        $retry = $this->selection($customer);
        $this->assertSame('awaiting_custom_quantity', $retry['mode']);
        $this->assertNotSame($awaiting['interactionToken'], $retry['interactionToken']);

        $this->postTelegramWebhook($this->callbackUpdate(
            3180131,
            'stale-cancel-after-retry',
            'detail:qty:cancel:'.$product->id.':'.$awaiting['interactionToken'],
            [],
            318011,
            'private',
            88002,
        ))->assertOk();
        $this->assertSame('awaiting_custom_quantity', $this->selection($customer)['mode']);
        $this->assertSame($retry['interactionToken'], $this->selection($customer)['interactionToken']);

        $this->postTelegramWebhook($this->messageUpdate(318014, '5', [], 318011))->assertOk();
        $this->assertSame('awaiting_custom_quantity', $this->selection($customer)['mode']);
        $this->assertStringContainsString('exceeds the available stock', (string) $this->lastMessageText('Custom Product'));

        $this->postTelegramWebhook($this->messageUpdate(318015, '3', [], 318011))->assertOk();
        $next = $this->selection($customer);
        $this->assertSame('browsing', $next['mode']);
        $this->assertSame(3, $next['quantity']);
        $this->assertArrayNotHasKey('previousQuantity', $next);
        $this->assertStringContainsString('Subtotal: Rp36.000', (string) $this->lastDetailMessage('Custom Product')['text']);
        $this->assertSame(0, Cart::count());
    }

    public function test_expired_custom_quantity_prompt_rejects_a_late_number(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 318016, 'locale' => 'en']);
        $product = Product::factory()->create([
            'name' => 'Expired Quantity Product',
            'price_amount' => 10000,
            'stock' => 3,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $this->openDetail($customer, $product);
        $state = $this->selection($customer);
        $this->postTelegramWebhook($this->callbackUpdate(
            3180161,
            'expired-manual',
            'detail:qty:manual:'.$product->id.':'.$state['interactionToken'],
            [],
            318016,
            'private',
            88016,
        ))->assertOk();
        $awaiting = $this->selection($customer);
        Cache::put(app(TelegramIntentSession::class)->key($customer), $awaiting, now()->subSecond());

        $this->postTelegramWebhook($this->messageUpdate(3180162, '2', [], 318016))->assertOk();

        $this->assertNull(app(TelegramIntentSession::class)->get($customer));
        $this->assertSame(0, Cart::count());
        $this->assertSame(0, Order::count());
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '318016'
            && str_contains((string) ($request['text'] ?? ''), 'did not understand that command'));
    }

    public function test_cancel_custom_quantity_preserves_previous_quantity(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 318021, 'locale' => 'en']);
        $product = Product::factory()->create(['name' => 'Cancel Product', 'price_amount' => 9000, 'stock' => 3, 'reserved_stock' => 0, 'is_active' => true]);

        $this->openDetail($customer, $product);
        $state = $this->selection($customer);
        $this->postTelegramWebhook($this->callbackUpdate(318022, 'increase', 'detail:qty:+:'.$product->id.':'.$state['interactionToken'], [], 318021, 'private', 88003))->assertOk();
        $state = $this->selection($customer);
        $this->assertSame(2, $state['quantity']);
        $this->postTelegramWebhook($this->callbackUpdate(318023, 'manual', 'detail:qty:manual:'.$product->id.':'.$state['interactionToken'], [], 318021, 'private', 88004))->assertOk();
        $awaiting = $this->selection($customer);

        $this->postTelegramWebhook($this->callbackUpdate(318024, 'cancel', 'detail:qty:cancel:'.$product->id.':'.$awaiting['interactionToken'], [], 318021, 'private', 88005))->assertOk();
        $restored = $this->selection($customer);
        $this->assertSame('browsing', $restored['mode']);
        $this->assertSame(2, $restored['quantity']);
        $this->assertArrayNotHasKey('previousQuantity', $restored);
        $this->assertStringContainsString('Subtotal: Rp18.000', (string) $this->lastDetailMessage('Cancel Product')['text']);
    }

    public function test_back_navigation_clears_selection_and_rejects_the_old_payment_button(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 318025, 'locale' => 'en']);
        $product = Product::factory()->create(['name' => 'Back Product', 'price_amount' => 9000, 'stock' => 1, 'reserved_stock' => 0, 'is_active' => true]);

        $this->openDetail($customer, $product);
        $state = $this->selection($customer);
        $this->postTelegramWebhook($this->callbackUpdate(3180251, 'back-products', 'detail:back:'.$product->id.':'.$state['interactionToken'], [], 318025, 'private', 88025))->assertOk();

        $this->assertNull(app(TelegramIntentSession::class)->get($customer));
        $this->postTelegramWebhook($this->callbackUpdate(3180252, 'old-payment-after-back', 'detail:pay:qris:'.$product->id.':'.$state['interactionToken'], [], 318025, 'private', 88025))->assertOk();
        $this->assertSame(0, Cart::count());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_expired_selection_token_is_rejected_without_checkout_side_effects(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 3180253, 'locale' => 'en']);
        $product = Product::factory()->create(['name' => 'Expired Product', 'price_amount' => 9000, 'stock' => 1, 'reserved_stock' => 0, 'is_active' => true]);

        $this->openDetail($customer, $product);
        $state = $this->selection($customer);
        Cache::put(app(TelegramIntentSession::class)->key($customer), $state, now()->subSecond());

        $this->postTelegramWebhook($this->callbackUpdate(3180254, 'expired-payment', 'detail:pay:qris:'.$product->id.':'.$state['interactionToken'], [], 3180253, 'private', 880253))->assertOk();

        $this->assertNull(app(TelegramIntentSession::class)->get($customer));
        $this->assertSame(0, Cart::count());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_direct_qris_tap_delivers_manual_qris_instruction_without_payment_choice_screen(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_pay_enabled' => false,
            'admin_telegram_chat_id' => '999',
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create(['telegram_id' => 318026, 'locale' => 'en']);
        $product = Product::factory()->create(['name' => 'QRIS Product', 'price_amount' => 11000, 'stock' => 1, 'reserved_stock' => 0, 'is_active' => true]);

        $this->openDetail($customer, $product);
        $state = $this->selection($customer);
        $this->postTelegramWebhook($this->callbackUpdate(
            318027,
            'qris-pay',
            'detail:pay:qris:'.$product->id.':'.$state['interactionToken'],
            [],
            318026,
            'private',
            88026,
        ))->assertOk();

        $this->assertSame(1, Order::count());
        $this->assertSame('QR_MANUAL', Payment::query()->sole()->method);
        $this->assertSame(1, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto'))->count());
        $this->assertSame(0, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'payment_method'))->count());
    }

    public function test_live_stock_change_blocks_increment_without_rotating_current_selection(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 3180271, 'locale' => 'en']);
        $product = Product::factory()->create(['name' => 'Live Stock Product', 'price_amount' => 10000, 'stock' => 2, 'reserved_stock' => 0, 'is_active' => true]);

        $this->openDetail($customer, $product);
        $state = $this->selection($customer);
        $product->forceFill(['stock' => 1])->save();

        $this->postTelegramWebhook($this->callbackUpdate(
            3180272,
            'live-stock-increment',
            'detail:qty:+:'.$product->id.':'.$state['interactionToken'],
            [],
            3180271,
            'private',
            88027,
        ))->assertOk();

        $current = $this->selection($customer);
        $this->assertSame(1, $current['quantity']);
        $this->assertSame($state['interactionToken'], $current['interactionToken']);
        $this->assertStringContainsString('maximum available quantity is 1', (string) Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && (string) ($request['callback_query_id'] ?? '') === 'live-stock-increment')->last()[0]['text']);
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Cart::count());
    }

    public function test_obsolete_buy_and_direct_quantity_callbacks_are_rejected_without_commerce_side_effects(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 318028, 'locale' => 'en']);
        $product = Product::factory()->create(['name' => 'Obsolete Detail Product', 'price_amount' => 10000, 'stock' => 2, 'reserved_stock' => 0, 'is_active' => true]);

        $this->postTelegramWebhook($this->callbackUpdate(3180281, 'old-buy', 'buy:'.$product->id, [], 318028))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(3180282, 'old-qty', 'qty:'.$product->id.':1', [], 318028))->assertOk();

        $this->assertSame(0, Cart::count());
        $this->assertSame(0, Order::count());
        $this->assertGreaterThan(0, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/answerCallbackQuery'))->count());
    }

    public function test_binance_button_is_hidden_and_unavailable_callback_has_no_side_effect_when_disabled(): void
    {
        config()->set('services.binance.pay_id', '987654321');
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_pay_enabled' => false,
            'admin_telegram_chat_id' => '999',
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create(['telegram_id' => 318029, 'locale' => 'en']);
        $product = Product::factory()->create([
            'name' => 'Disabled Binance',
            'price_amount' => 20000,
            'usdt_price_amount' => 2_000_000,
            'stock' => 1,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $this->openDetail($customer, $product);
        $detail = $this->lastDetailMessage('Disabled Binance');
        $buttons = collect($detail['reply_markup']['inline_keyboard'])->flatten(1);
        $this->assertFalse($buttons->contains(fn (array $button): bool => str_starts_with((string) ($button['callback_data'] ?? ''), 'detail:pay:binance:')));
        $state = $this->selection($customer);
        $this->postTelegramWebhook($this->callbackUpdate(
            3180291,
            'disabled-binance',
            'detail:pay:binance:'.$product->id.':'.$state['interactionToken'],
            [],
            318029,
            'private',
            88029,
        ))->assertOk();

        $this->assertSame(0, Cart::count());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_binance_payment_is_only_rendered_when_enabled_and_direct_tap_creates_one_order(): void
    {
        config()->set('services.binance.pay_id', '987654321');
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_pay_enabled' => true,
            'admin_telegram_chat_id' => '999',
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create(['telegram_id' => 318031, 'locale' => 'en']);
        $product = Product::factory()->create([
            'name' => 'Binance Product',
            'price_amount' => 25000,
            'usdt_price_amount' => 2_500_000,
            'stock' => 2,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $this->openDetail($customer, $product);
        $detail = $this->lastDetailMessage('Binance Product');
        $keyboard = $detail['reply_markup']['inline_keyboard'];
        $this->assertCount(5, $keyboard);
        $this->assertCount(3, $keyboard[0]);
        foreach (array_slice($keyboard, 1) as $row) {
            $this->assertCount(1, $row);
        }
        $buttons = collect($keyboard)->flatten(1);
        $binance = $buttons->first(fn (array $button): bool => str_starts_with((string) ($button['callback_data'] ?? ''), 'detail:pay:binance:'));
        $this->assertNotNull($binance);
        $this->assertStringContainsString('Pay with Binance · 2.5 USDT', (string) $binance['text']);

        $state = $this->selection($customer);
        $this->postTelegramWebhook($this->callbackUpdate(318032, 'binance-pay', 'detail:pay:binance:'.$product->id.':'.$state['interactionToken'], [], 318031, 'private', 88006))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(318033, 'binance-duplicate', 'detail:pay:qris:'.$product->id.':'.$state['interactionToken'], [], 318031, 'private', 88006))->assertOk();

        $this->assertSame(1, Cart::count(), 'cart count');
        $this->assertSame(CartStatus::CheckedOut, Cart::query()->sole()->status, 'cart status');
        $this->assertSame(1, Order::count(), 'order count');
        $this->assertSame(1, Payment::count(), 'payment count');
        $this->assertSame('BINANCE_PAY_ID', Payment::query()->sole()->method);
        $this->assertSame(1, $product->fresh()->reserved_stock, 'reserved stock');
        $this->assertSame(1, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto'))->count(), 'direct Binance instruction');
        $this->assertSame(1, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '318031'
            && str_contains((string) ($request['text'] ?? ''), 'Binance Product'))->count(), 'customer detail messages');
    }

    public function test_stale_and_tampered_payment_callbacks_have_no_commerce_side_effect(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 318041, 'locale' => 'en']);
        $product = Product::factory()->create(['name' => 'Secure Product', 'price_amount' => 10000, 'stock' => 1, 'reserved_stock' => 0, 'is_active' => true]);

        $this->openDetail($customer, $product);
        $state = $this->selection($customer);
        $this->postTelegramWebhook($this->callbackUpdate(318042, 'tampered-product', 'detail:pay:qris:999999:'.$state['interactionToken'], [], 318041, 'private', 88007))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(318043, 'tampered-token', 'detail:pay:qris:'.$product->id.':wrong-token', [], 318041, 'private', 88007))->assertOk();

        $this->assertSame(0, Cart::count());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_navigation_and_cancellation_clear_selection_before_late_text(): void
    {
        $this->fakeTelegramHttp();
        $product = Product::factory()->create([
            'name' => 'Navigation Product',
            'price_amount' => 10000,
            'stock' => 3,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $startCustomer = Customer::factory()->create(['telegram_id' => 318051, 'locale' => 'en']);
        $this->openDetail($startCustomer, $product);
        $startState = $this->selection($startCustomer);
        $this->postTelegramWebhook($this->messageUpdate(3180511, '/start', [], 318051))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(3180512, '2', [], 318051))->assertOk();
        $this->assertNull(app(TelegramIntentSession::class)->get($startCustomer));

        $catalogCustomer = Customer::factory()->create(['telegram_id' => 318052, 'locale' => 'en']);
        $this->openDetail($catalogCustomer, $product);
        $catalogState = $this->selection($catalogCustomer);
        $this->postTelegramWebhook($this->messageUpdate(3180521, '/products', [], 318052))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(3180522, '2', [], 318052))->assertOk();
        $this->assertNull(app(TelegramIntentSession::class)->get($catalogCustomer));

        $cancelCustomer = Customer::factory()->create(['telegram_id' => 318053, 'locale' => 'en']);
        $this->openDetail($cancelCustomer, $product);
        $cancelState = $this->selection($cancelCustomer);
        $this->postTelegramWebhook($this->callbackUpdate(
            3180531,
            'manual-before-cancel',
            'detail:qty:manual:'.$product->id.':'.$cancelState['interactionToken'],
            [],
            318053,
            'private',
            88053,
        ))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(3180532, '/batal', [], 318053))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(3180533, '2', [], 318053))->assertOk();
        $this->assertNull(app(TelegramIntentSession::class)->get($cancelCustomer));

        $this->postTelegramWebhook($this->callbackUpdate(
            318054,
            'old-start-payment',
            'detail:pay:qris:'.$product->id.':'.$startState['interactionToken'],
            [],
            318051,
            'private',
            88051,
        ))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(
            318055,
            'old-catalog-payment',
            'detail:pay:qris:'.$product->id.':'.$catalogState['interactionToken'],
            [],
            318052,
            'private',
            88052,
        ))->assertOk();

        $this->assertSame(0, Cart::count());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_maintenance_activation_invalidates_old_quantity_and_manual_controls(): void
    {
        $this->fakeTelegramHttp();
        $product = Product::factory()->create([
            'name' => 'Maintenance Selection Product',
            'price_amount' => 10000,
            'stock' => 3,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        $setting = CheckoutMaintenanceSetting::query()->firstOrFail();

        $quantityCustomer = Customer::factory()->create(['telegram_id' => 318056, 'locale' => 'en']);
        $this->openDetail($quantityCustomer, $product);
        $quantityState = $this->selection($quantityCustomer);
        $setting->update([
            'is_active' => true,
            'public_note' => 'Admin maintenance note.',
            'version' => 1,
            'activated_at' => now(),
        ]);
        $this->postTelegramWebhook($this->callbackUpdate(
            3180561,
            'quantity-during-maintenance',
            'detail:qty:+:'.$product->id.':'.$quantityState['interactionToken'],
            [],
            318056,
            'private',
            88056,
        ))->assertOk();
        $this->assertNull(app(TelegramIntentSession::class)->get($quantityCustomer));

        $setting->update([
            'is_active' => false,
            'public_note' => null,
            'version' => 2,
            'activated_at' => null,
        ]);
        $manualCustomer = Customer::factory()->create(['telegram_id' => 318057, 'locale' => 'en']);
        $this->openDetail($manualCustomer, $product);
        $manualState = $this->selection($manualCustomer);
        $this->postTelegramWebhook($this->callbackUpdate(
            3180571,
            'manual-before-maintenance',
            'detail:qty:manual:'.$product->id.':'.$manualState['interactionToken'],
            [],
            318057,
            'private',
            88057,
        ))->assertOk();
        $awaiting = $this->selection($manualCustomer);
        $paymentCustomer = Customer::factory()->create(['telegram_id' => 318058, 'locale' => 'en']);
        $this->openDetail($paymentCustomer, $product);
        $paymentState = $this->selection($paymentCustomer);
        $setting->update([
            'is_active' => true,
            'public_note' => 'Admin maintenance note.',
            'version' => 3,
            'activated_at' => now(),
        ]);
        $this->postTelegramWebhook($this->callbackUpdate(
            3180581,
            'payment-during-maintenance',
            'detail:pay:qris:'.$product->id.':'.$paymentState['interactionToken'],
            [],
            318058,
            'private',
            88058,
        ))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(3180572, '2', [], 318057))->assertOk();

        $this->assertNull(app(TelegramIntentSession::class)->get($manualCustomer));
        $this->assertNull(app(TelegramIntentSession::class)->get($paymentCustomer));
        $this->assertSame(0, Cart::count());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Admin maintenance note.')
            && str_contains((string) json_encode($request->data()), 'checkout-maintenance:status'));
        $this->assertNotSame('', (string) $awaiting['interactionToken']);
    }

    public function test_payment_revalidates_visibility_prices_and_binance_configuration(): void
    {
        config()->set('services.binance.pay_id', '987654321');
        $this->fakeTelegramHttp();
        $setting = ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_pay_enabled' => true,
            'admin_telegram_chat_id' => '999',
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create(['telegram_id' => 318061, 'locale' => 'en']);
        $product = Product::factory()->create([
            'name' => 'Live Detail Pricing',
            'price_amount' => 10000,
            'usdt_price_amount' => 2_000_000,
            'stock' => 4,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $this->openDetail($customer, $product);
        $state = $this->selection($customer);
        $product->forceFill(['price_amount' => 15000, 'usdt_price_amount' => 3_000_000])->save();
        $this->postTelegramWebhook($this->callbackUpdate(
            3180611,
            'qris-after-price-change',
            'detail:pay:qris:'.$product->id.':'.$state['interactionToken'],
            [],
            318061,
            'private',
            88061,
        ))->assertOk();
        $this->assertSame(15000, Order::query()->sole()->total_amount);
        $this->assertSame(15000, Payment::query()->sole()->amount);

        $this->postTelegramWebhook($this->callbackUpdate(
            3180612,
            'reopen-for-usdt-change',
            'product:'.$product->id,
            [],
            318061,
            'private',
            88062,
        ))->assertOk();
        $state = $this->selection($customer);
        $product->forceFill(['usdt_price_amount' => null])->save();
        $this->postTelegramWebhook($this->callbackUpdate(
            3180613,
            'binance-after-usdt-removal',
            'detail:pay:binance:'.$product->id.':'.$state['interactionToken'],
            [],
            318061,
            'private',
            88062,
        ))->assertOk();
        $this->assertNull(app(TelegramIntentSession::class)->get($customer));
        $this->assertSame(1, Order::count());
        $this->assertSame(1, Payment::count());

        $product->forceFill(['usdt_price_amount' => 3_000_000])->save();
        $this->postTelegramWebhook($this->callbackUpdate(
            3180614,
            'reopen-for-binance-disable',
            'product:'.$product->id,
            [],
            318061,
            'private',
            88064,
        ))->assertOk();
        $state = $this->selection($customer);
        $setting->forceFill(['binance_pay_enabled' => false])->save();
        $this->postTelegramWebhook($this->callbackUpdate(
            3180615,
            'binance-after-disable',
            'detail:pay:binance:'.$product->id.':'.$state['interactionToken'],
            [],
            318061,
            'private',
            88064,
        ))->assertOk();
        $this->assertNull(app(TelegramIntentSession::class)->get($customer));
        $this->assertSame(1, Order::count());

        $visibilityCustomer = Customer::factory()->create(['telegram_id' => 318062, 'locale' => 'en']);
        $this->openDetail($visibilityCustomer, $product);
        $visibilityState = $this->selection($visibilityCustomer);
        $product->forceFill(['is_active' => false])->save();
        $this->postTelegramWebhook($this->callbackUpdate(
            3180621,
            'payment-after-visibility-change',
            'detail:pay:qris:'.$product->id.':'.$visibilityState['interactionToken'],
            [],
            318062,
            'private',
            88065,
        ))->assertOk();

        $this->assertSame(1, Order::count());
        $this->assertSame(1, Payment::count());
        $this->assertNull(app(TelegramIntentSession::class)->get($visibilityCustomer));
    }

    public function test_binance_checkout_uses_live_usdt_price_after_detail_render(): void
    {
        config()->set('services.binance.pay_id', '987654321');
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_pay_enabled' => true,
            'binance_verification_mode' => 'order_id',
            'admin_telegram_chat_id' => '999',
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create(['telegram_id' => 318066, 'locale' => 'en']);
        $product = Product::factory()->create([
            'name' => 'Live Binance Pricing',
            'price_amount' => 10000,
            'usdt_price_amount' => 2_000_000,
            'stock' => 2,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $this->openDetail($customer, $product);
        $state = $this->selection($customer);
        $product->forceFill(['usdt_price_amount' => 3_000_000])->save();
        $this->postTelegramWebhook($this->callbackUpdate(
            3180661,
            'binance-after-usdt-change',
            'detail:pay:binance:'.$product->id.':'.$state['interactionToken'],
            [],
            318066,
            'private',
            88066,
        ))->assertOk();

        $payment = Payment::query()->sole();
        $this->assertSame('BINANCE_PAY_ID', $payment->method);
        $this->assertSame(3_000_000, $payment->amount);
        $this->assertSame('USDT', $payment->currency);
    }

    public function test_malformed_callbacks_and_corrupt_session_state_have_no_side_effects(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 318071, 'locale' => 'en']);
        $product = Product::factory()->create([
            'name' => 'Malformed Callback Product',
            'price_amount' => 10000,
            'stock' => 2,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        $this->openDetail($customer, $product);
        $state = $this->selection($customer);

        foreach ([
            'detail:qty:unknown:'.$product->id.':'.$state['interactionToken'],
            'detail:pay:paypal:'.$product->id.':'.$state['interactionToken'],
            'detail:pay:qris:0:'.$state['interactionToken'],
            'detail:pay:qris:'.$product->id.':'.$state['interactionToken'].':extra',
            'detail:qty:+:'.$product->id.':bad.token',
            'locale:xx',
        ] as $index => $data) {
            $this->postTelegramWebhook($this->callbackUpdate(
                3180710 + $index,
                'malformed-'.$index,
                $data,
                [],
                318071,
                'private',
                88071,
            ))->assertOk();
        }

        Cache::put(app(TelegramIntentSession::class)->key($customer), [
            'productId' => $product->id,
            'quantity' => 0,
            'interactionToken' => 'not-a-token',
            'locale' => 'xx',
            'mode' => 'browsing',
        ], now()->addMinutes(15));
        $this->postTelegramWebhook($this->callbackUpdate(
            3180718,
            'corrupt-session',
            'detail:pay:qris:'.$product->id.':deadbeefdeadbeef',
            [],
            318071,
            'private',
            88071,
        ))->assertOk();

        $this->assertNull(app(TelegramIntentSession::class)->get($customer));
        $this->assertSame(0, Cart::count());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, TelegramOutboundEffect::count());
        $this->assertSame(0, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto'))->count());
    }

    public function test_repeated_qris_taps_reuse_one_checkout_and_one_outbound_effect(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_pay_enabled' => false,
            'admin_telegram_chat_id' => '999',
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create(['telegram_id' => 318081, 'locale' => 'en']);
        $product = Product::factory()->create([
            'name' => 'Repeated QRIS Product',
            'price_amount' => 11000,
            'stock' => 2,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $this->openDetail($customer, $product);
        $state = $this->selection($customer);
        $callback = 'detail:pay:qris:'.$product->id.':'.$state['interactionToken'];
        $this->postTelegramWebhook($this->callbackUpdate(3180811, 'qris-first', $callback, [], 318081, 'private', 88081))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(3180812, 'qris-repeat', $callback, [], 318081, 'private', 88081))->assertOk();

        $this->assertSame(1, Cart::count());
        $this->assertSame(1, Order::count());
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, $product->fresh()->reserved_stock);
        $this->assertSame(1, TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectType::ManualCheckoutInstructions->value)
            ->count());
        $this->assertSame(1, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto'))->count());
    }

    private function openDetail(Customer $customer, Product $product): void
    {
        $this->postTelegramWebhook($this->callbackUpdate(
            318100 + $customer->telegram_id,
            'product-'.$product->id,
            'product:'.$product->id,
            ['id' => $customer->telegram_id],
            $customer->telegram_id,
        ))->assertOk();
    }

    /** @return array<string, mixed> */
    private function selection(Customer $customer): array
    {
        return app(TelegramIntentSession::class)->get($customer) ?? $this->fail('Expected a product selection state.');
    }

    /** @return array<string, mixed> */
    private function lastDetailMessage(string $productName): array
    {
        $record = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), $productName))->last();

        $this->assertNotNull($record);

        return $record[0]->data();
    }

    private function detailMessageCount(string $productName): int
    {
        return Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), $productName))->count();
    }

    private function lastMessageText(string $productName): string
    {
        return (string) $this->lastDetailMessage($productName)['text'];
    }
}
