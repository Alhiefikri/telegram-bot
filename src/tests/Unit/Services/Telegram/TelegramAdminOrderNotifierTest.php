<?php

namespace Tests\Unit\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Services\Telegram\TelegramAdminOrderNotifier;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramAdminOrderNotifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_order_notification_stores_message_tracking_for_later_delete(): void
    {
        config()->set('app.url', 'https://admin.example.test');
        config()->set('services.telegram.token', 'bot-token');
        config()->set('services.telegram.api_base_url', 'https://api.telegram.org');
        ManualPaymentSetting::create(['admin_telegram_chat_id' => '999', 'is_active' => true]);
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 654]])]);

        $order = $this->pendingOrderWithItems();

        app(TelegramAdminOrderNotifier::class)->sendPending($order);

        $order->refresh();
        $this->assertNotNull($order->admin_pending_order_notified_at);
        $this->assertSame('999', $order->admin_pending_order_notification_chat_id);
        $this->assertSame(654, $order->admin_pending_order_notification_message_id);
        $this->assertNull($order->admin_pending_order_notification_deleted_at);
    }

    public function test_paid_order_notification_deletes_stored_pending_checkout_message_once(): void
    {
        config()->set('app.url', 'https://admin.example.test');
        config()->set('services.telegram.token', 'bot-token');
        config()->set('services.telegram.api_base_url', 'https://api.telegram.org');
        ManualPaymentSetting::create(['admin_telegram_chat_id' => '999', 'is_active' => true]);
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 777]])]);

        $order = $this->paidOrderWithPendingAdminMessage();

        app(TelegramAdminOrderNotifier::class)->sendPaid($order);
        app(TelegramAdminOrderNotifier::class)->sendPaid($order->fresh());

        $deletes = Http::recorded(fn ($request) => str_ends_with($request->url(), '/deleteMessage'));
        $this->assertCount(1, $deletes);
        $deleteRequest = $deletes->first()[0];
        $this->assertSame('999', (string) $deleteRequest['chat_id']);
        $this->assertSame(321, (int) $deleteRequest['message_id']);
        $this->assertNotNull($order->fresh()->admin_pending_order_notification_deleted_at);
    }

    public function test_expired_order_notification_is_private_to_admin_and_includes_buyer_context(): void
    {
        config()->set('app.timezone', 'Asia/Makassar');
        config()->set('app.url', 'https://admin.example.test');
        config()->set('services.telegram.token', 'bot-token');
        config()->set('services.telegram.api_base_url', 'https://api.telegram.org');
        ManualPaymentSetting::create(['admin_telegram_chat_id' => '999', 'is_active' => true]);
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 321]])]);

        $order = $this->expiredOrderWithItems(1);

        app(TelegramAdminOrderNotifier::class)->sendExpired($order->fresh());

        $sends = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage'));
        $this->assertCount(1, $sends);
        $request = $sends->first()[0];
        $text = $request['text'];

        $this->assertSame('999', (string) $request['chat_id']);
        $this->assertStringContainsString('⌛ Checkout expired belum bayar', $text);
        $this->assertStringContainsString('Invoice: INV-EXP-001', $text);
        $this->assertStringContainsString('Pembeli: @buyer (12345)', $text);
        $this->assertStringContainsString('Sumber: Telegram Bot', $text);
        $this->assertStringContainsString('Status: Expired / belum bayar', $text);
        $this->assertStringContainsString('Metode: QRIS', $text);
        $this->assertStringContainsString('Total: Rp32.340', $text);
        $this->assertStringContainsString('- CHATGPT PLUS x 1', $text);
        $expectedExpiredAt = $order->fresh(['payments'])->payments->first()->expired_at->copy()
            ->timezone((string) config('app.timezone'))
            ->format('d M Y, H:i');
        $this->assertStringContainsString('Expired at: '.$expectedExpiredAt, $text);
        $this->assertStringContainsString('Stok sudah dilepas kembali.', $text);
        $this->assertStringNotContainsString('escrow', strtolower($text));
        $this->assertStringContainsString('Buka Dashboard', json_encode($request['reply_markup'], JSON_THROW_ON_ERROR));
        $this->assertNotNull($order->fresh()->admin_expired_order_notified_at);
    }

    public function test_admin_order_notifications_skip_group_or_channel_chat_ids(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        config()->set('services.telegram.api_base_url', 'https://api.telegram.org');
        ManualPaymentSetting::create(['admin_telegram_chat_id' => '-100123', 'is_active' => true]);
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

        $order = $this->expiredOrderWithItems(1);

        app(TelegramAdminOrderNotifier::class)->sendExpired($order);

        Http::assertNothingSent();
        $this->assertNull($order->fresh()->admin_expired_order_notified_at);
    }

    public function test_expired_admin_notification_query_count_does_not_grow_with_item_count(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        config()->set('services.telegram.api_base_url', 'https://api.telegram.org');
        ManualPaymentSetting::create(['admin_telegram_chat_id' => '999', 'is_active' => true]);
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 321]])]);

        $singleItemQueries = $this->queryCountForExpiredAdminNotification($this->expiredOrderWithItems(1));
        $manyItemQueries = $this->queryCountForExpiredAdminNotification($this->expiredOrderWithItems(8));

        $this->assertSame($singleItemQueries, $manyItemQueries);
    }

    private function queryCountForExpiredAdminNotification(Order $order): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        app(TelegramAdminOrderNotifier::class)->sendExpired($order);

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function pendingOrderWithItems(): Order
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer', 'first_name' => 'Buyer']);
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => 'INV-PENDING-001',
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'total_amount' => 30000,
            'expires_at' => Carbon::parse('2026-07-07 18:16:00', 'Asia/Makassar'),
        ]);

        OrderItem::factory()->for($order)->create([
            'product_name' => 'CHATGPT PLUS PRIVATE',
            'quantity' => 1,
            'unit_price' => 30000,
            'subtotal' => 30000,
        ]);

        Payment::factory()->for($order)->create([
            'provider' => 'pakasir',
            'method' => 'api_qris',
            'amount' => 30000,
            'status' => PaymentStatus::Pending,
        ]);

        return $order;
    }

    private function paidOrderWithPendingAdminMessage(): Order
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer', 'first_name' => 'Buyer']);
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => 'INV-PAID-001',
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => 30000,
            'admin_pending_order_notified_at' => now()->subMinute(),
            'admin_pending_order_notification_chat_id' => '999',
            'admin_pending_order_notification_message_id' => 321,
        ]);

        OrderItem::factory()->for($order)->create([
            'product_name' => 'CHATGPT PLUS PRIVATE',
            'quantity' => 1,
            'unit_price' => 30000,
            'subtotal' => 30000,
        ]);

        Payment::factory()->for($order)->create([
            'provider' => 'pakasir',
            'method' => 'api_qris',
            'amount' => 30000,
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        return $order;
    }

    private function expiredOrderWithItems(int $itemCount): Order
    {
        $expiredAt = Carbon::parse('2026-07-05 18:59:00', 'Asia/Makassar');
        $customer = Customer::factory()->create(['telegram_id' => 12345 + Order::count(), 'username' => 'buyer', 'first_name' => 'Buyer']);
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => 'INV-EXP-'.str_pad((string) (Order::count() + 1), 3, '0', STR_PAD_LEFT),
            'order_status' => OrderStatus::Expired,
            'payment_status' => PaymentStatus::Expired,
            'total_amount' => 32340,
            'expires_at' => $expiredAt,
        ]);

        foreach (range(1, $itemCount) as $itemNumber) {
            OrderItem::factory()->for($order)->create([
                'product_name' => $itemNumber === 1 ? 'CHATGPT PLUS' : 'CHATGPT PLUS '.$itemNumber,
                'quantity' => 1,
                'unit_price' => 32340,
                'subtotal' => 32340,
            ]);
        }

        Payment::factory()->for($order)->create([
            'provider' => 'pakasir',
            'method' => 'api_qris',
            'amount' => 32340,
            'status' => PaymentStatus::Expired,
            'expired_at' => $expiredAt,
        ]);

        return $order;
    }
}
