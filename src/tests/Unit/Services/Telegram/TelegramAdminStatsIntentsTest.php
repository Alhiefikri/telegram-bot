<?php

namespace Tests\Unit\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Telegram\TelegramAdminStatsIntents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramAdminStatsIntentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_usdt_payment_micro_units_are_not_reported_as_rupiah_millions(): void
    {
        Carbon::setTestNow('2026-07-08 15:00:00');

        config()->set('services.telegram.token', 'bot-token');

        Http::fake([
            'https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 111]]),
        ]);

        $customer = Customer::factory()->create();
        $order = Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => 75000,
            'created_at' => now()->subHour(),
        ]);
        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Paid,
            'amount' => 5_000_000,
            'currency' => 'USDT',
            'provider_fee' => 250_000,
            'paid_at' => now()->subHour(),
        ]);

        app(TelegramAdminStatsIntents::class)->showDailyStats($customer, 'admin-chat-123');

        $sends = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage'));
        $this->assertCount(1, $sends);

        $text = (string) $sends->first()[0]['text'];

        $this->assertStringContainsString('Nilai Penjualan: Rp75.000', $text);
        $this->assertStringContainsString('USDT: 4.75 USDT', $text);
        $this->assertStringNotContainsString('Rp5.000.000', $text);
    }

    public function test_mixed_idr_and_usdt_stats_group_receipts_and_count_order_once(): void
    {
        Carbon::setTestNow('2026-07-08 15:00:00');

        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $idrOrder = Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => 50000,
            'created_at' => now()->subHours(2),
        ]);
        OrderItem::factory()->for($idrOrder)->for($product)->create(['quantity' => 2]);
        Payment::factory()->for($idrOrder)->create([
            'status' => PaymentStatus::Paid,
            'amount' => 50000,
            'provider_fee' => 2500,
            'currency' => 'IDR',
            'paid_at' => now()->subHours(2),
        ]);

        $usdtOrder = Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => 25000,
            'created_at' => now()->subHour(),
        ]);
        OrderItem::factory()->for($usdtOrder)->for($product)->create(['quantity' => 1]);
        Payment::factory()->for($usdtOrder)->create([
            'status' => PaymentStatus::Paid,
            'amount' => 2_500_000,
            'currency' => 'USDT',
            'paid_at' => now()->subHour(),
        ]);

        $stats = app(TelegramAdminStatsIntents::class)->calculateTodayStats();

        $this->assertSame(2, $stats['paid_orders']);
        $this->assertSame(3, $stats['sold_products']);
        $this->assertSame(75000, $stats['sales_value']);
        $this->assertSame(47500, $stats['receipts']['IDR']);
        $this->assertSame(2_500_000, $stats['receipts']['USDT']);
    }

    public function test_duplicate_paid_payment_rows_use_first_paid_payment_as_canonical(): void
    {
        Carbon::setTestNow('2026-07-08 15:00:00');

        $order = Order::factory()->create([
            'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => 50000,
        ]);
        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Paid,
            'amount' => 50000,
            'provider_fee' => 1000,
            'currency' => 'IDR',
            'paid_at' => now()->subMinutes(10),
        ]);
        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Paid,
            'amount' => 5_000_000,
            'currency' => 'USDT',
            'paid_at' => now()->subMinute(),
        ]);

        $stats = app(TelegramAdminStatsIntents::class)->calculateTodayStats();

        $this->assertSame(1, $stats['paid_orders']);
        $this->assertSame(50000, $stats['sales_value']);
        $this->assertSame(49000, $stats['receipts']['IDR']);
        $this->assertArrayNotHasKey('USDT', $stats['receipts']);
    }

    public function test_calculate_today_stats_counts_only_orders_and_payments_from_today(): void
    {
        Carbon::setTestNow('2026-07-08 15:00:00');

        $product = Product::factory()->create(['name' => 'Test Product', 'price_amount' => 10000]);
        $customer = Customer::factory()->create();

        // 1. Paid order just before the reporting window (Should be ignored)
        $oldOrder = Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'created_at' => now()->startOfDay()->subSecond(),
            'updated_at' => now()->startOfDay()->subSecond(),
        ]);
        OrderItem::factory()->for($oldOrder)->for($product)->create(['quantity' => 2]);
        Payment::factory()->for($oldOrder)->create([
            'status' => PaymentStatus::Paid,
            'amount' => 20000,
            'provider_fee' => 1000,
            'paid_at' => now()->startOfDay()->subSecond(),
        ]);

        // 2. Pending order in the reporting window (Counts as new order, but not paid/earning/sold product)
        Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Paid order in the reporting window (Should count towards everything)
        $todayPaidOrder = Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);
        OrderItem::factory()->for($todayPaidOrder)->for($product)->create(['quantity' => 3]);
        Payment::factory()->for($todayPaidOrder)->create([
            'status' => PaymentStatus::Paid,
            'amount' => 30000,
            'provider_fee' => 1500,
            'paid_at' => now()->subHours(2),
        ]);

        // 4. Another paid order in the reporting window (Should add up)
        $todayPaidOrder2 = Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
        OrderItem::factory()->for($todayPaidOrder2)->for($product)->create(['quantity' => 1]);
        Payment::factory()->for($todayPaidOrder2)->create([
            'status' => PaymentStatus::Paid,
            'amount' => 10000,
            'provider_fee' => 500,
            'paid_at' => now()->subHour(),
        ]);

        $intents = app(TelegramAdminStatsIntents::class);
        $stats = $intents->calculateTodayStats();

        // Total orders in the reporting window: 1 pending + 2 paid = 3
        $this->assertSame(3, $stats['new_orders']);
        // Total paid orders today: 2
        $this->assertSame(2, $stats['paid_orders']);
        // Sold products today: 3 + 1 = 4
        $this->assertSame(4, $stats['sold_products']);
        // Earning in the reporting window: (30000 - 1500) + (10000 - 500) = 28500 + 9500 = 38000
        $this->assertSame(38000, $stats['earning']);
    }

    public function test_calculate_today_stats_ignores_orders_from_previous_calendar_day(): void
    {
        Carbon::setTestNow('2026-07-08 22:00:00');

        $product = Product::factory()->create(['name' => 'Test Product', 'price_amount' => 10000]);
        $customer = Customer::factory()->create();

        $beforePreviousReport = Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'created_at' => '2026-07-07 21:59:59',
            'updated_at' => '2026-07-07 21:59:59',
        ]);
        OrderItem::factory()->for($beforePreviousReport)->for($product)->create(['quantity' => 5]);
        Payment::factory()->for($beforePreviousReport)->create([
            'status' => PaymentStatus::Paid,
            'amount' => 50000,
            'provider_fee' => 2500,
            'paid_at' => '2026-07-07 21:59:59',
        ]);

        $previousCalendarDayOrder = Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'created_at' => '2026-07-07 22:10:00',
            'updated_at' => '2026-07-07 22:10:00',
        ]);
        OrderItem::factory()->for($previousCalendarDayOrder)->for($product)->create(['quantity' => 2]);
        Payment::factory()->for($previousCalendarDayOrder)->create([
            'status' => PaymentStatus::Paid,
            'amount' => 20000,
            'provider_fee' => 1000,
            'paid_at' => '2026-07-07 22:10:00',
        ]);

        $intents = app(TelegramAdminStatsIntents::class);
        $stats = $intents->calculateTodayStats();

        $this->assertSame(0, $stats['new_orders']);
        $this->assertSame(0, $stats['paid_orders']);
        $this->assertSame(0, $stats['sold_products']);
        $this->assertSame(0, $stats['earning']);
    }

    public function test_send_automated_daily_stats_formats_and_sends_correctly(): void
    {
        Carbon::setTestNow('2026-07-08 22:00:00');
        config()->set('services.telegram.token', 'bot-token');
        config()->set('app.url', 'https://admin.example.test');

        Http::fake([
            'https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 111]]),
        ]);

        $product = Product::factory()->create(['name' => 'Test Product', 'price_amount' => 50000]);
        $customer = Customer::factory()->create();

        $order = Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'created_at' => '2026-07-07 23:00:00',
            'updated_at' => '2026-07-07 23:00:00',
        ]);
        OrderItem::factory()->for($order)->for($product)->create(['quantity' => 2]);
        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Paid,
            'amount' => 100000,
            'provider_fee' => 5000,
            'paid_at' => '2026-07-07 23:00:00',
        ]);

        $intents = app(TelegramAdminStatsIntents::class);
        $intents->sendAutomatedDailyStats('admin-chat-123');

        $sends = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage'));
        $this->assertCount(1, $sends);
        $request = $sends->first()[0];

        $this->assertSame('admin-chat-123', (string) $request['chat_id']);

        $text = $request['text'];
        $this->assertStringContainsString('📊 Rekap Kemarin', $text);
        $this->assertStringContainsString('Order Baru: 1', $text);
        $this->assertStringContainsString('Order Dibayar: 1', $text);
        $this->assertStringContainsString('Produk Terjual: 2', $text);
        $this->assertStringContainsString('Nilai Penjualan: Rp10.000', $text);
        $this->assertStringContainsString('Pembayaran Diterima:', $text);
        $this->assertStringContainsString('• Rupiah: Rp95.000', $text);

        // Check for mini app dashboard button
        $replyMarkup = json_encode($request['reply_markup'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('🌐 Mini App Dashboard', $replyMarkup);
        $this->assertStringContainsString('web_app', $replyMarkup);
    }
}
