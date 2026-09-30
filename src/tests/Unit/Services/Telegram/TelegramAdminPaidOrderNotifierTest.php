<?php

namespace Tests\Unit\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Telegram\TelegramAdminPaidOrderNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramAdminPaidOrderNotifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_order_notification_sends_produk_terjual_message_to_admin(): void
    {
        config()->set('app.timezone', 'Asia/Makassar');
        config()->set('app.url', 'https://admin.example.test');
        config()->set('services.telegram.token', 'bot-token');
        config()->set('services.telegram.api_base_url', 'https://api.telegram.org');
        ManualPaymentSetting::create(['admin_telegram_chat_id' => '999', 'is_active' => true]);
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 321]])]);

        $order = $this->paidOrderWithItems(1);

        app(TelegramAdminPaidOrderNotifier::class)->send($order);

        $sends = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage'));
        $this->assertCount(1, $sends);
        $request = $sends->first()[0];
        $text = $request['text'];

        $this->assertSame('999', (string) $request['chat_id']);
        $this->assertStringContainsString('🔔 Produk terjual!', $text);
        $this->assertStringContainsString('Invoice: INV-PAID-001', $text);
        $this->assertStringContainsString('Pembeli: @buyer (12345)', $text);
        $this->assertStringContainsString('Sumber: Telegram Bot', $text);
        $this->assertStringContainsString('Status: Pembayaran selesai', $text);
        $this->assertStringContainsString('Metode: QRIS', $text);
        $this->assertStringContainsString('Earning: Rp30.000', $text);
        $this->assertStringContainsString('Produk:', $text);
        $this->assertStringContainsString('- 🛍️ CHATGPT PLUS x 1', $text);

        $expectedPaidAt = $order->fresh(['payments'])->payments->first()->paid_at->copy()
            ->timezone((string) config('app.timezone'))
            ->format('d M Y, H:i');
        $this->assertStringContainsString('Paid at: '.$expectedPaidAt, $text);
        $this->assertStringContainsString('Cek dashboard untuk proses order ini.', $text);
        $this->assertStringContainsString('Buka Dashboard', json_encode($request['reply_markup'], JSON_THROW_ON_ERROR));
        $this->assertNotNull($order->fresh()->admin_paid_order_notified_at);
    }

    public function test_paid_order_notification_includes_remaining_stock_per_product(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        ManualPaymentSetting::create(['admin_telegram_chat_id' => '999', 'is_active' => true]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 321]])]);

        $product = Product::factory()->create([
            'name' => 'Premium Account',
            'stock' => 5,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $order = $this->paidOrderWithSpecificProduct($product, 2);

        app(TelegramAdminPaidOrderNotifier::class)->send($order);

        $sends = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage'));
        $text = $sends->first()[0]['text'];

        $this->assertStringContainsString('📦 Sisa Stok:', $text);
        $this->assertStringContainsString('- 🛍️ Premium Account: 5', $text);

    }

    public function test_paid_order_message_uses_the_order_item_icon_snapshot_as_a_custom_emoji_entity(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        ManualPaymentSetting::create(['admin_telegram_chat_id' => '999', 'is_active' => true]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 321]])]);

        $order = $this->paidOrderWithItems(1);
        $item = $order->orderItems()->firstOrFail();
        $item->update([
            'product_icon' => '🧩',
            'product_custom_emoji_id' => '5368324170671202286',
        ]);
        Product::query()->whereKey($item->product_id)->update(['icon' => '🧾', 'custom_emoji_id' => '5368324170671202287']);
        app(\App\Services\Telegram\CustomEmojiCapability::class)->markAvailable();

        app(TelegramAdminPaidOrderNotifier::class)->send($order);

        $request = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage'))->first()[0];
        $this->assertStringContainsString('🧩 CHATGPT PLUS', (string) $request['text']);
        $this->assertContains('5368324170671202286', array_column($request['entities'] ?? [], 'custom_emoji_id'));
        $this->assertNotContains('5368324170671202287', array_column($request['entities'] ?? [], 'custom_emoji_id'));
    }

    public function test_paid_order_notification_is_idempotent(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        ManualPaymentSetting::create(['admin_telegram_chat_id' => '999', 'is_active' => true]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 321]])]);

        $order = $this->paidOrderWithItems(1);
        $order->update(['admin_paid_order_notified_at' => now()]);

        app(TelegramAdminPaidOrderNotifier::class)->send($order);

        Http::assertNothingSent();
    }

    public function test_paid_order_notification_shows_earning_after_merchant_paid_fee(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        config()->set('services.pakasir.qris_fee.payer', 'merchant');
        ManualPaymentSetting::create(['admin_telegram_chat_id' => '999', 'is_active' => true]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 321]])]);

        $order = $this->paidOrderWithItems(1);
        $order->payments()->first()->update([
            'amount' => 32000,
            'provider_fee' => 2000,
            'provider_payment_method' => 'qris',
        ]);

        $order->update(['total_amount' => 32000]);

        app(TelegramAdminPaidOrderNotifier::class)->send($order);

        $sends = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage'));
        $text = $sends->first()[0]['text'];

        $this->assertStringContainsString('Earning: Rp30.000 (setelah fee)', $text);
    }

    public function test_paid_order_notification_does_not_reduce_earning_for_buyer_paid_qris_fee(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        config()->set('services.pakasir.qris_fee.payer', 'customer');
        ManualPaymentSetting::create(['admin_telegram_chat_id' => '999', 'is_active' => true]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 321]])]);

        $order = $this->paidOrderWithItems(1);
        $order->payments()->first()->update([
            'provider' => 'klikqris',
            'method' => 'QRIS',
            'provider_payment_method' => 'qris',
            'amount' => 35000,
            'provider_fee' => 227,
            'provider_total_payment' => 35227,
        ]);
        $order->update(['total_amount' => 35000]);

        app(TelegramAdminPaidOrderNotifier::class)->send($order);

        $text = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage'))->first()[0]['text'];

        $this->assertStringContainsString('Earning: Rp35.000', $text);
        $this->assertStringNotContainsString('Earning: Rp34.773', $text);
        $this->assertStringNotContainsString('(setelah fee)', $text);
    }

    public function test_paid_binance_order_notification_keeps_earning_in_usdt(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        ManualPaymentSetting::create(['admin_telegram_chat_id' => '999', 'is_active' => true]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 321]])]);

        $order = $this->paidOrderWithItems(1);
        $order->payments()->first()->update([
            'method' => 'BINANCE_PAY_ID',
            'amount' => 500000,
            'currency' => 'USDT',
            'provider_fee' => 0,
        ]);

        app(TelegramAdminPaidOrderNotifier::class)->send($order);

        $text = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage'))->first()[0]['text'];
        $this->assertStringContainsString('Earning: 0.5 USDT', $text);
        $this->assertStringNotContainsString('Earning: Rp500.000', $text);
    }

    private function paidOrderWithItems(int $itemCount): Order
    {
        $paidAt = Carbon::parse('2026-07-07 18:16:00', 'Asia/Makassar');
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer', 'first_name' => 'Buyer']);
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => 'INV-PAID-001',
            'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => 30000,
        ]);

        foreach (range(1, $itemCount) as $itemNumber) {
            $product = Product::factory()->create([
                'name' => $itemNumber === 1 ? 'CHATGPT PLUS' : 'CHATGPT PLUS '.$itemNumber,
                'stock' => 10,
                'reserved_stock' => 0,
                'is_active' => true,
            ]);

            OrderItem::factory()->for($order)->for($product)->create([
                'product_name' => $product->name,
                'quantity' => 1,
                'unit_price' => 30000,
                'subtotal' => 30000,
            ]);
        }

        Payment::factory()->for($order)->create([
            'provider' => 'pakasir',
            'method' => 'api_qris',
            'amount' => 30000,
            'provider_fee' => 0,
            'status' => PaymentStatus::Paid,
            'paid_at' => $paidAt,
        ]);

        return $order;
    }

    private function paidOrderWithSpecificProduct(Product $product, int $quantity): Order
    {
        $paidAt = Carbon::parse('2026-07-07 18:16:00', 'Asia/Makassar');
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer', 'first_name' => 'Buyer']);
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => 'INV-PAID-002',
            'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => 30000,
        ]);

        OrderItem::factory()->for($order)->for($product)->create([
            'product_name' => $product->name,
            'quantity' => $quantity,
            'unit_price' => 30000,
            'subtotal' => 30000,
        ]);

        Payment::factory()->for($order)->create([
            'provider' => 'pakasir',
            'method' => 'api_qris',
            'amount' => 30000,
            'provider_fee' => 0,
            'status' => PaymentStatus::Paid,
            'paid_at' => $paidAt,
        ]);

        return $order;
    }
}
