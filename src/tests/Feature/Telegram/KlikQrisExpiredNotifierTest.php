<?php

namespace Tests\Feature\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Services\Telegram\TelegramOrderExpiredNotifier;
use Illuminate\Support\Facades\Http;
use Tests\Support\TelegramBotTestSupport;

class KlikQrisExpiredNotifierTest extends TelegramBotTestSupport
{
    public function test_expired_klikqris_order_uses_create_new_qris_copy_and_catalog_callback(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'en']);
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => 'INV-KLIK-EXPIRED',
            'order_status' => OrderStatus::Expired,
            'total_amount' => 1000,
        ]);
        OrderItem::factory()->for($order)->create(['product_name' => 'Expired QRIS Item', 'quantity' => 1]);
        Payment::factory()->for($order)->create(['provider' => 'klikqris', 'provider_payment_method' => 'qris']);

        app(TelegramOrderExpiredNotifier::class)->send($order);

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && ($request['reply_markup']['inline_keyboard'][0][0]['text'] ?? null) === '🔄 Create New QRIS'
            && ($request['reply_markup']['inline_keyboard'][0][0]['callback_data'] ?? null) === 'katalog'
            && ! str_contains(json_encode($request->data(), JSON_THROW_ON_ERROR), 'buyer:cancel-pending')
            && ! str_contains(json_encode($request->data(), JSON_THROW_ON_ERROR), 'order:INV-KLIK-EXPIRED'));
    }

    public function test_expired_malay_payment_guidance_uses_customer_locale_for_binance_late_payment(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'ms']);
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => 'INV-BINANCE-LATE-MS',
            'order_status' => OrderStatus::Expired,
            'payment_status' => PaymentStatus::Expired,
            'total_amount' => 1000,
        ]);
        OrderItem::factory()->for($order)->create(['product_name' => 'Late Binance Item', 'quantity' => 1]);
        Payment::factory()->for($order)->create([
            'provider' => 'manual',
            'method' => 'BINANCE_PAY_ID',
            'status' => PaymentStatus::Expired,
            'binance_order_id' => 'BINANCE-LATE-ORDER',
        ]);

        app(TelegramOrderExpiredNotifier::class)->send($order);

        $request = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage'))->first()[0];
        $text = (string) $request['text'];
        $this->assertStringContainsString('Pengesahan Binance belum selesai', $text);
        $this->assertStringContainsString('ID Pesanan telah diterima', $text);
        $this->assertStringContainsString('bayaran lewat', $text);
        $this->assertStringNotContainsString('Binance verification did not finish', $text);
        $this->assertStringContainsString($order->invoice_number, $text);
    }
}
