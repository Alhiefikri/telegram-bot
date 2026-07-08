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

    public function test_calculate_today_stats_counts_only_todays_real_orders_and_payments(): void
    {
        Carbon::setTestNow('2026-07-08 15:00:00');

        $product = Product::factory()->create(['name' => 'Test Product', 'price_amount' => 10000]);
        $customer = Customer::factory()->create();

        // 1. Yesterday's paid order (Should be ignored)
        $yesterdayOrder = Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        OrderItem::factory()->for($yesterdayOrder)->for($product)->create(['quantity' => 2]);
        Payment::factory()->for($yesterdayOrder)->create([
            'status' => PaymentStatus::Paid,
            'amount' => 20000,
            'provider_fee' => 1000,
            'paid_at' => now()->subDay(),
        ]);

        // 2. Today's pending order (Counts as new order, but not paid/earning/sold product)
        Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Today's paid order (Should count towards everything)
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

        // 4. Another today's paid order (Should add up)
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

        // Total orders today: 1 pending + 2 paid = 3
        $this->assertSame(3, $stats['new_orders']);
        // Total paid orders today: 2
        $this->assertSame(2, $stats['paid_orders']);
        // Sold products today: 3 + 1 = 4
        $this->assertSame(4, $stats['sold_products']);
        // Earning today: (30000 - 1500) + (10000 - 500) = 28500 + 9500 = 38000
        $this->assertSame(38000, $stats['earning']);
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
        ]);
        OrderItem::factory()->for($order)->for($product)->create(['quantity' => 2]);
        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Paid,
            'amount' => 100000,
            'provider_fee' => 5000,
            'paid_at' => now(),
        ]);

        $intents = app(TelegramAdminStatsIntents::class);
        $intents->sendAutomatedDailyStats('admin-chat-123');

        $sends = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage'));
        $this->assertCount(1, $sends);
        $request = $sends->first()[0];

        $this->assertSame('admin-chat-123', (string) $request['chat_id']);

        $text = $request['text'];
        $this->assertStringContainsString('📊 Statistik Hari Ini', $text);
        $this->assertStringContainsString('Order Baru: 1', $text);
        $this->assertStringContainsString('Order Dibayar: 1', $text);
        $this->assertStringContainsString('Produk Terjual: 2', $text);
        $this->assertStringContainsString('Earning: Rp95.000', $text);

        // Check for mini app dashboard button
        $replyMarkup = json_encode($request['reply_markup'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('🌐 Mini App Dashboard', $replyMarkup);
        $this->assertStringContainsString('web_app', $replyMarkup);
    }
}
