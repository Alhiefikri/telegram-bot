<?php

namespace Tests\Feature\Telegram;

use App\Models\CartItem;
use App\Models\CheckoutMaintenanceSetting;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Support\Facades\Http;
use Tests\Support\TelegramBotTestSupport;

class CheckoutMaintenanceTest extends TelegramBotTestSupport
{
    public function test_active_catalog_and_quantity_controls_replace_purchase_actions_without_blocking_cart_management(): void
    {
        $this->fakeTelegramHttp();
        CheckoutMaintenanceSetting::query()->firstOrFail()->update([
            'is_active' => true,
            'public_note' => 'Coba lagi setelah update selesai.',
            'version' => 1,
            'activated_at' => now(),
        ]);
        $product = Product::factory()->create(['name' => 'Telegram Maintenance Product', 'stock' => 5, 'reserved_stock' => 0]);

        $this->postTelegramWebhook($this->callbackUpdate(10, 'detail', 'product:'.$product->id))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(11, 'buy', 'buy:'.$product->id))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(12, 'quantity', 'qty:'.$product->id.':2'))->assertOk();

        $this->assertSame(1, CartItem::query()->count());
        $this->assertSame(0, Order::query()->count());

        $messages = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage'));
        $messagePayloads = $messages->map(fn (array $record): mixed => $record[0]->data())->all();
        $encodedMessages = json_encode($messagePayloads, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('Telegram Maintenance Product', $encodedMessages);
        $this->assertStringContainsString('checkout-maintenance:status', $encodedMessages);
        $this->assertStringNotContainsString('buy:qris:'.$product->id, $encodedMessages);
        $this->assertStringContainsString('Pilih jumlah', $encodedMessages);
        $this->assertStringContainsString('Cek Status Checkout', $encodedMessages);
        $this->assertStringContainsString('Coba lagi setelah update selesai.', $encodedMessages);
    }

    public function test_main_menu_includes_compact_maintenance_notice(): void
    {
        $this->fakeTelegramHttp();
        CheckoutMaintenanceSetting::query()->firstOrFail()->update(['is_active' => true, 'version' => 1, 'activated_at' => now()]);

        $this->postTelegramWebhook($this->messageUpdate(30, '/menu'))->assertOk();

        $menuMessage = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Menu utama'))
            ->last()[0];
        $this->assertStringContainsString('Checkout sedang maintenance', (string) $menuMessage['text']);
    }

    public function test_checkout_maintenance_status_returns_active_notice_then_reopened_navigation(): void
    {
        $this->fakeTelegramHttp();
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

    public function test_stale_checkout_callback_returns_full_maintenance_notice_with_public_note(): void
    {
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'admin_telegram_chat_id' => '999',
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create(['telegram_id' => 12346, 'username' => 'stale-buyer']);
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

    public function test_stale_direct_quantity_callback_returns_maintenance_notice_before_availability_validation(): void
    {
        $this->fakeTelegramHttp();
        $product = Product::factory()->create(['name' => 'Sold Out Stale Product', 'stock' => 0, 'reserved_stock' => 0]);
        CheckoutMaintenanceSetting::query()->firstOrFail()->update([
            'is_active' => true,
            'public_note' => 'Admin sedang sinkronisasi payment provider.',
            'version' => 1,
            'activated_at' => now(),
        ]);

        $this->postTelegramWebhook($this->callbackUpdate(41, 'stale-quantity', 'qty:qris:'.$product->id.':1'))->assertOk();

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
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer']);
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
}
