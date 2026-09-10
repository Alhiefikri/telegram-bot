<?php

namespace Tests\Feature\Telegram;

use App\Models\CartItem;
use App\Models\CheckoutMaintenanceSetting;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\CartService;
use App\Services\Telegram\TelegramIntentSession;
use Illuminate\Support\Facades\Http;
use Tests\Support\TelegramBotTestSupport;

class CheckoutMaintenanceTest extends TelegramBotTestSupport
{
    public function test_active_catalog_and_detail_controls_replace_purchase_actions_without_blocking_cart_management(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        CheckoutMaintenanceSetting::query()->firstOrFail()->update([
            'is_active' => true,
            'public_note' => 'Coba lagi setelah update selesai.',
            'version' => 1,
            'activated_at' => now(),
        ]);
        $product = Product::factory()->create(['name' => 'Telegram Maintenance Product', 'stock' => 5, 'reserved_stock' => 0]);

        $this->postTelegramWebhook($this->callbackUpdate(10, 'detail', 'product:'.$product->id))->assertOk();

        $this->assertSame(0, CartItem::query()->count());
        $this->assertSame(0, Order::query()->count());

        $messages = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage'));
        $messagePayloads = $messages->map(fn (array $record): mixed => $record[0]->data())->all();
        $encodedMessages = json_encode($messagePayloads, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('Telegram Maintenance Product', $encodedMessages);
        $this->assertStringContainsString('checkout-maintenance:status', $encodedMessages);
        $this->assertStringNotContainsString('detail:pay:', $encodedMessages);
        $this->assertStringContainsString('Jumlah: 1', $encodedMessages);
        $this->assertStringContainsString('Cek Status Checkout', $encodedMessages);
        $this->assertStringContainsString('Coba lagi setelah update selesai.', $encodedMessages);
    }

    public function test_main_menu_includes_compact_maintenance_notice(): void
    {
        config()->set('services.telegram.welcome_banner_url', 'banner-file-id');
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        CheckoutMaintenanceSetting::query()->firstOrFail()->update(['is_active' => true, 'version' => 1, 'activated_at' => now()]);

        $this->postTelegramWebhook($this->messageUpdate(30, '/menu'))->assertOk();

        $home = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendPhoto'))->sole()[0];
        $this->assertStringContainsString(
            'Checkout sedang maintenance',
            (string) $this->telegramRequestField($home, 'caption'),
        );
    }

    public function test_checkout_maintenance_status_returns_active_notice_then_reopened_navigation(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        CheckoutMaintenanceSetting::query()->firstOrFail()->update([
            'is_active' => true,
            'public_note' => 'Admin sedang sinkronisasi payment provider.',
            'version' => 1,
            'activated_at' => now(),
        ]);

        $this->postTelegramWebhook($this->callbackUpdate(20, 'status-active', 'checkout-maintenance:status'))->assertOk();

        $activeMessage = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Checkout sedang dalam maintenance'))
            ->last()[0];
        $activePayload = json_encode($activeMessage->data(), JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('Admin sedang sinkronisasi payment provider.', (string) $activeMessage['text']);
        $this->assertStringContainsString('produk, stok, dan mengelola keranjang', (string) $activeMessage['text']);
        $this->assertStringContainsString('Pesanan yang sudah dibuat tetap diproses.', (string) $activeMessage['text']);
        $this->assertStringContainsString('Silakan cek status kembali beberapa saat lagi.', (string) $activeMessage['text']);
        $this->assertStringContainsString('checkout-maintenance:status', $activePayload);

        CheckoutMaintenanceSetting::query()->firstOrFail()->update([
            'is_active' => false,
            'public_note' => null,
            'version' => 2,
            'activated_at' => null,
        ]);

        $this->postTelegramWebhook($this->callbackUpdate(21, 'status-inactive', 'checkout-maintenance:status'))->assertOk();

        $reopenedMessage = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Checkout sudah dibuka kembali'))
            ->last()[0];
        $reopenedPayload = json_encode($reopenedMessage->data(), JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('katalog', $reopenedPayload);
        $this->assertStringContainsString('status', $reopenedPayload);
        $this->assertStringNotContainsString('checkout-maintenance:status', $reopenedPayload);
    }

    public function test_store_bot_creates_a_fresh_order_after_maintenance_reopens(): void
    {
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'admin_telegram_chat_id' => '999',
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create(['telegram_id' => 12347, 'username' => 'reopened-buyer', 'locale' => 'id']);
        $product = Product::factory()->create(['name' => 'Reopened Telegram Product', 'stock' => 1, 'reserved_stock' => 0]);
        CheckoutMaintenanceSetting::query()->firstOrFail()->update([
            'is_active' => true,
            'version' => 1,
            'activated_at' => now(),
        ]);

        $this->postTelegramWebhook($this->callbackUpdate(
            60,
            'detail-during-maintenance',
            'product:'.$product->id,
            ['id' => 12347, 'username' => 'reopened-buyer'],
            12347,
            'private',
            99060,
        ))->assertOk();
        $this->assertSame(0, CartItem::query()->count());
        $this->assertSame(0, Order::query()->count());

        CheckoutMaintenanceSetting::query()->firstOrFail()->update([
            'is_active' => false,
            'public_note' => null,
            'version' => 2,
            'activated_at' => null,
        ]);
        $this->postTelegramWebhook($this->callbackUpdate(
            61,
            'reopened-detail',
            'product:'.$product->id,
            ['id' => 12347, 'username' => 'reopened-buyer'],
            12347,
            'private',
            99061,
        ))->assertOk();
        $selection = app(TelegramIntentSession::class)->get($customer);
        $this->assertNotNull($selection);
        $this->postTelegramWebhook($this->callbackUpdate(
            62,
            'reopened-payment',
            'detail:pay:qris:'.$product->id.':'.$selection['interactionToken'],
            ['id' => 12347, 'username' => 'reopened-buyer'],
            12347,
            'private',
            99062,
        ))->assertOk();

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, $product->fresh()->reserved_stock);
    }

    public function test_stale_checkout_callback_returns_full_maintenance_notice_with_public_note(): void
    {
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'admin_telegram_chat_id' => '999',
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create(['telegram_id' => 12346, 'username' => 'stale-buyer', 'locale' => 'id']);
        $product = Product::factory()->create(['name' => 'Telegram Stale Product', 'stock' => 2, 'reserved_stock' => 0]);
        $carts = app(CartService::class);
        $carts->addItem($carts->activeCartFor($customer), $product, 1);
        CheckoutMaintenanceSetting::query()->firstOrFail()->update([
            'is_active' => true,
            'public_note' => 'Admin sedang sinkronisasi payment provider.',
            'version' => 1,
            'activated_at' => now(),
        ]);

        $this->postTelegramWebhook($this->callbackUpdate(40, 'checkout-current', 'checkout:current', ['id' => 12346, 'username' => 'stale-buyer'], 12346, 'private', 99041))->assertOk();

        $this->assertSame(0, Order::query()->count());
        $message = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Pesanan yang sudah dibuat tetap diproses.'))
            ->last()[0];
        $this->assertStringContainsString('Admin sedang sinkronisasi payment provider.', (string) $message['text']);
    }

    public function test_stale_direct_payment_callback_returns_maintenance_notice_before_availability_validation(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $product = Product::factory()->create(['name' => 'Sold Out Stale Product', 'stock' => 1, 'reserved_stock' => 0]);

        $this->postTelegramWebhook($this->callbackUpdate(41, 'detail-before-maintenance', 'product:'.$product->id))->assertOk();
        $selection = app(TelegramIntentSession::class)->get($customer);
        $this->assertNotNull($selection);
        $product->forceFill(['stock' => 0, 'reserved_stock' => 0])->save();
        CheckoutMaintenanceSetting::query()->firstOrFail()->update([
            'is_active' => true,
            'public_note' => 'Admin sedang sinkronisasi payment provider.',
            'version' => 1,
            'activated_at' => now(),
        ]);

        $this->postTelegramWebhook($this->callbackUpdate(
            42,
            'stale-payment',
            'detail:pay:qris:'.$product->id.':'.$selection['interactionToken'],
            ['id' => 12345],
            12345,
            'private',
            99042,
        ))->assertOk();

        $this->assertSame(0, CartItem::query()->count());
        $message = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Pesanan yang sudah dibuat tetap diproses.'))
            ->last()[0];
        $this->assertStringContainsString('Admin sedang sinkronisasi payment provider.', (string) $message['text']);
    }

    public function test_repeated_base_checkout_callback_reuses_existing_order_during_maintenance(): void
    {
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'admin_telegram_chat_id' => '999',
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer', 'locale' => 'id']);
        $product = Product::factory()->create(['name' => 'Telegram Idempotent Product', 'stock' => 2, 'reserved_stock' => 0]);
        $carts = app(CartService::class);
        $carts->addItem($carts->activeCartFor($customer), $product, 1);

        $this->postTelegramWebhook($this->callbackUpdate(40, 'checkout-first', 'checkout', ['id' => 12345, 'username' => 'buyer'], 12345, 'private', 99040))->assertOk();
        $this->assertSame(1, Order::query()->count());

        CheckoutMaintenanceSetting::query()->firstOrFail()->update([
            'is_active' => true,
            'version' => 1,
            'activated_at' => now(),
        ]);

        $this->postTelegramWebhook($this->callbackUpdate(41, 'checkout-repeat', 'checkout', ['id' => 12345, 'username' => 'buyer'], 12345, 'private', 99040))->assertOk();

        $this->assertSame(1, Order::query()->count());
        $maintenanceAnswers = Http::recorded(fn ($request) => str_ends_with($request->url(), '/answerCallbackQuery')
            && str_contains((string) ($request['text'] ?? ''), 'maintenance'));
        $this->assertCount(0, $maintenanceAnswers);
    }

    public function test_obsolete_purchase_callbacks_do_not_mutate_cart_or_order(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $product = Product::factory()->create(['name' => 'Obsolete Purchase', 'stock' => 0, 'reserved_stock' => 0]);

        $this->postTelegramWebhook($this->callbackUpdate(50, 'stale-qris-buy', 'buy:qris:'.$product->id))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(51, 'stale-buy', 'buy:'.$product->id))->assertOk();

        $this->assertSame(0, CartItem::query()->count());
        $this->assertSame(0, Order::query()->count());
        $messages = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage'));
        $payload = json_encode($messages->map(fn (array $record): mixed => $record[0]->data())->all(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('buy:qris:', $payload);
        $this->assertStringNotContainsString('detail:pay:', $payload);
    }

    public function test_stale_checkout_choice_returns_maintenance_notice_without_an_active_cart(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        CheckoutMaintenanceSetting::query()->firstOrFail()->update([
            'is_active' => true,
            'public_note' => 'Admin sedang sinkronisasi payment provider.',
            'version' => 1,
            'activated_at' => now(),
        ]);

        $this->postTelegramWebhook($this->callbackUpdate(52, 'stale-qris-choice', 'checkout:current'))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(53, 'stale-binance-choice', 'checkout:binance'))->assertOk();

        $this->assertSame(0, Order::query()->count());
        $messages = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage'));
        $payload = json_encode($messages->map(fn (array $record): mixed => $record[0]->data())->all(), JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('Checkout sedang dalam maintenance', $payload);
        $this->assertStringContainsString('checkout-maintenance:status', $payload);
        $this->assertStringNotContainsString('Checkout belum berhasil', $payload);
    }

    public function test_sold_out_product_detail_keeps_maintenance_status_action_visible(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $product = Product::factory()->create(['name' => 'Sold Out Detail', 'stock' => 0, 'reserved_stock' => 0]);
        CheckoutMaintenanceSetting::query()->firstOrFail()->update([
            'is_active' => true,
            'version' => 1,
            'activated_at' => now(),
        ]);

        $this->postTelegramWebhook($this->callbackUpdate(54, 'sold-out-detail', 'product:'.$product->id))->assertOk();

        $message = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Sold Out Detail'))
            ->last()[0];
        $payload = json_encode($message->data(), JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('Checkout sedang dalam maintenance', (string) $message['text']);
        $this->assertStringContainsString('checkout-maintenance:status', $payload);
    }

    public function test_english_status_copy_keeps_plain_admin_note(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'en']);
        CheckoutMaintenanceSetting::query()->firstOrFail()->update([
            'is_active' => true,
            'public_note' => '<b>Admin note</b>',
            'version' => 1,
            'activated_at' => now(),
        ]);

        $this->postTelegramWebhook($this->callbackUpdate(55, 'english-status', 'checkout-maintenance:status'))->assertOk();

        $message = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Checkout is under maintenance'))
            ->last()[0];
        $this->assertStringContainsString('Check status again in a little while.', (string) $message['text']);
        $this->assertStringContainsString('<b>Admin note</b>', (string) $message['text']);
        $this->assertArrayNotHasKey('parse_mode', $message->data());
    }
}
