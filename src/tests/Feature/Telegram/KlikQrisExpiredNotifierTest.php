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
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('expiredOrderLocaleCases')]
    public function test_expired_order_copy_uses_each_customer_locale(array $copy): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => $copy['locale']]);
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => 'INV-EXPIRED-'.strtoupper($copy['locale']),
            'order_status' => OrderStatus::Expired,
            'payment_status' => PaymentStatus::Expired,
            'total_amount' => 1000,
        ]);
        OrderItem::factory()->for($order)->create(['product_name' => 'Expired locale item', 'quantity' => 1]);
        Payment::factory()->for($order)->create([
            'provider' => 'klikqris',
            'provider_payment_method' => 'qris',
            'status' => PaymentStatus::Expired,
        ]);

        app(TelegramOrderExpiredNotifier::class)->send($order);

        $request = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage'))->first()[0];
        $text = (string) $request['text'];
        $button = $request['reply_markup']['inline_keyboard'][0][0]['text'] ?? null;
        $this->assertStringContainsString($copy['title'], $text);
        $this->assertStringContainsString($copy['invoice'], $text);
        $this->assertStringContainsString($copy['products'], $text);
        $this->assertStringContainsString($copy['total'], $text);
        $this->assertStringContainsString($copy['cancelled'], $text);
        $this->assertStringContainsString($copy['reorder'], $text);
        $this->assertSame($copy['button'], $button);
        $this->assertStringNotContainsString($copy['otherTitle'], $text);
    }

    public static function expiredOrderLocaleCases(): array
    {
        return [
            'Indonesian' => [[
                'locale' => 'id',
                'title' => '⏳ Order kedaluwarsa',
                'otherTitle' => '⏳ Order expired',
                'invoice' => '🧾 Invoice:',
                'products' => '📦 Produk:',
                'total' => '💰 Total:',
                'cancelled' => 'Pembayaran tidak selesai sampai batas waktu',
                'reorder' => 'Tenang, kamu masih bisa order ulang kapan aja.',
                'button' => '🔄 Buat QRIS Baru',
            ]],
            'English' => [[
                'locale' => 'en',
                'title' => '⏳ Order expired',
                'otherTitle' => '⏳ Order kedaluwarsa',
                'invoice' => '🧾 Invoice:',
                'products' => '📦 Products:',
                'total' => '💰 Total:',
                'cancelled' => 'Payment was not completed before the deadline',
                'reorder' => 'You can place a new order anytime.',
                'button' => '🔄 Create New QRIS',
            ]],
            'Malay' => [[
                'locale' => 'ms',
                'title' => '⏳ Pesanan tamat tempoh',
                'otherTitle' => '⏳ Order expired',
                'invoice' => '🧾 Invois:',
                'products' => '📦 Produk:',
                'total' => '💰 Jumlah:',
                'cancelled' => 'Bayaran tidak selesai sebelum tarikh akhir',
                'reorder' => 'Jangan risau, anda boleh membuat pesanan baharu pada bila-bila masa.',
                'button' => '🔄 Buat QRIS Baharu',
            ]],
        ];
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

    #[DataProvider('binanceExpiredLocaleCases')]
    public function test_expired_binance_guidance_uses_each_customer_locale(array $copy): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => $copy['locale']]);
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => 'INV-BINANCE-'.strtoupper($copy['locale']),
            'order_status' => OrderStatus::Expired,
            'payment_status' => PaymentStatus::Expired,
            'total_amount' => 1000,
        ]);
        OrderItem::factory()->for($order)->create(['product_name' => 'Expired Binance locale item', 'quantity' => 1]);
        Payment::factory()->for($order)->create([
            'provider' => 'manual',
            'method' => 'BINANCE_PAY_ID',
            'status' => PaymentStatus::Expired,
            'binance_order_id' => 'BINANCE-LOCALE-ORDER',
        ]);

        app(TelegramOrderExpiredNotifier::class)->send($order);

        $request = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage'))->first()[0];
        $text = (string) $request['text'];
        $this->assertStringContainsString($copy['title'], $text);
        $this->assertStringContainsString($copy['orderId'], $text);
        $this->assertStringContainsString($copy['prompt'], $text);
        $this->assertStringContainsString($copy['review'], $text);
        $this->assertStringNotContainsString($copy['otherTitle'], $text);
    }

    public static function binanceExpiredLocaleCases(): array
    {
        return [
            'Indonesian' => [[
                'locale' => 'id',
                'title' => '⏳ Verifikasi Binance belum selesai',
                'otherTitle' => '⏳ Binance verification did not finish',
                'orderId' => '💵 Order ID sudah diterima',
                'prompt' => 'Kalau kamu sudah membayar',
                'review' => 'Admin akan mengecek pembayaran terlambat secara manual.',
            ]],
            'English' => [[
                'locale' => 'en',
                'title' => '⏳ Binance verification did not finish',
                'otherTitle' => '⏳ Verifikasi Binance belum selesai',
                'orderId' => '💵 Your Order ID was received',
                'prompt' => 'If you already paid',
                'review' => 'The admin will review the late payment manually.',
            ]],
            'Malay' => [[
                'locale' => 'ms',
                'title' => '⏳ Pengesahan Binance belum selesai',
                'otherTitle' => '⏳ Binance verification did not finish',
                'orderId' => '💵 ID Pesanan telah diterima',
                'prompt' => 'Jika anda sudah membayar',
                'review' => 'Admin akan menyemak bayaran lewat secara manual.',
            ]],
        ];
    }
}
