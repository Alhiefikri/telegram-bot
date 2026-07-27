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
use App\Services\Telegram\TelegramStoreStatsReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TelegramAdminStatsIntentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_payment_queries_have_window_and_canonical_lookup_indexes(): void
    {
        $indexes = collect(Schema::getIndexes('payments'))->keyBy('name');

        $this->assertSame(['status', 'paid_at'], $indexes->get('payments_status_paid_at_index')['columns'] ?? null);
        $this->assertSame(['order_id', 'status', 'paid_at', 'id'], $indexes->get('payments_order_status_paid_at_id_index')['columns'] ?? null);
    }

    public function test_growth_current_periods_use_asia_jakarta_day_monday_week_and_month_ranges(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 15:00:00', 'Asia/Jakarta'));
        config()->set('app.timezone', 'Asia/Jakarta');

        // Wednesday today: appears in today, week, and month.
        $this->createPaidOrderAt('2026-07-08 10:00:00', 10000, 1);
        // Tuesday in the current week: appears in week and month only.
        $this->createPaidOrderAt('2026-07-07 10:00:00', 20000, 2);
        // Earlier in July before this Monday: appears in month only.
        $this->createPaidOrderAt('2026-07-02 10:00:00', 30000, 3);
        // June: excluded from all current periods.
        $this->createPaidOrderAt('2026-06-20 10:00:00', 40000, 4);

        $report = app(TelegramStoreStatsReport::class)->growth();

        $this->assertSame(1, $report['periods']['today']['current']['paid_orders']);
        $this->assertSame(3, $report['periods']['week']['current']['sold_products']);
        $this->assertSame(6, $report['periods']['month']['current']['sold_products']);
        $this->assertSame(60000, $report['periods']['month']['current']['sales_value']);
    }

    public function test_growth_previous_periods_compare_equivalent_elapsed_time_windows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 15:00:00', 'Asia/Jakarta'));
        config()->set('app.timezone', 'Asia/Jakarta');

        $this->createPaidOrderAt('2026-07-07 14:59:59', 11000, 1); // previous-day window included
        $this->createPaidOrderAt('2026-06-30 15:00:00', 12000, 1); // previous-week window included
        $this->createPaidOrderAt('2026-06-08 15:00:00', 13000, 1); // previous-month window included
        $this->createPaidOrderAt('2026-06-08 15:00:01', 14000, 1); // just outside previous-month window

        $report = app(TelegramStoreStatsReport::class)->growth();

        $this->assertSame(1, $report['periods']['today']['previous']['paid_orders']);
        $this->assertSame(1, $report['periods']['week']['previous']['paid_orders']);
        $this->assertSame(1, $report['periods']['month']['previous']['paid_orders']);
    }

    public function test_growth_previous_month_end_clamps_to_shorter_previous_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-31 15:00:00', 'Asia/Jakarta'));
        config()->set('app.timezone', 'Asia/Jakarta');

        $this->createPaidOrderAt('2026-02-28 14:59:59', 21000, 1);
        $this->createPaidOrderAt('2026-03-01 00:00:00', 22000, 1);

        $report = app(TelegramStoreStatsReport::class)->growth();

        $this->assertSame(1, $report['periods']['month']['previous']['paid_orders']);
        $this->assertSame(21000, $report['periods']['month']['previous']['sales_value']);
        $this->assertSame(1, $report['periods']['month']['current']['paid_orders']);
        $this->assertSame(22000, $report['periods']['month']['current']['sales_value']);
    }

    public function test_growth_uses_canonical_paid_payment_and_keeps_genuine_currencies_separate(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 15:00:00', 'Asia/Jakarta'));
        config()->set('app.timezone', 'Asia/Jakarta');

        $duplicateOrder = $this->createPaidOrderAt('2026-07-08 10:00:00', 50000, 1, 'IDR', 1000);
        Payment::factory()->for($duplicateOrder)->create([
            'status' => PaymentStatus::Paid,
            'amount' => 5_000_000,
            'currency' => 'USDT',
            'provider_fee' => 0,
            'paid_at' => Carbon::parse('2026-07-08 10:05:00', 'Asia/Jakarta'),
        ]);
        $this->createPaidOrderAt('2026-07-08 11:00:00', 2_500_000, 1, 'USDT', 0);

        $report = app(TelegramStoreStatsReport::class)->growth();
        $current = $report['periods']['month']['current'];

        $this->assertSame(2, $current['paid_orders']);
        $this->assertSame(49000, $current['receipts']['IDR']);
        $this->assertSame(2_500_000, $current['receipts']['USDT']);
    }

    public function test_show_store_growth_sends_one_readable_report_with_sections_timestamp_and_dashboard_button(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 15:00:00', 'Asia/Jakarta'));
        config()->set('app.timezone', 'Asia/Jakarta');
        config()->set('services.telegram.token', 'bot-token');
        config()->set('app.url', 'https://admin.example.test');

        Http::fake([
            'https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 111]]),
        ]);

        $customer = Customer::factory()->create();
        $this->createPaidOrderAt('2026-07-08 10:00:00', 10000, 1);
        $this->createPaidOrderAt('2026-07-07 10:00:00', 10000, 1);

        app(TelegramAdminStatsIntents::class)->showStoreGrowth($customer, 'admin-chat-123');

        $sends = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage'));
        $this->assertCount(1, $sends);

        $request = $sends->first()[0];
        $text = (string) $request['text'];

        $this->assertStringContainsString('📊 Pertumbuhan Store', $text);
        $this->assertStringContainsString('Diperbarui 08 Jul 2026 15:00 WIB', $text);
        $this->assertStringContainsString('☀️ Hari Ini', $text);
        $this->assertStringContainsString('🗓 Minggu Ini', $text);
        $this->assertStringContainsString('📅 Bulan Ini', $text);
        $this->assertLessThanOrEqual(4096, mb_strlen($text));

        $replyMarkup = json_encode($request['reply_markup'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('🌐 Mini App Dashboard', $replyMarkup);
        $this->assertStringContainsString('web_app', $replyMarkup);
    }

    public function test_store_growth_output_formats_all_change_indicator_baseline_states_truthfully(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 15:00:00', 'Asia/Jakarta'));
        config()->set('app.timezone', 'Asia/Jakarta');
        config()->set('services.telegram.token', 'bot-token');

        Http::fake([
            'https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 111]]),
        ]);

        $customer = Customer::factory()->create();
        $this->createPaidOrderAt('2026-07-08 09:00:00', 10000, 1);
        $this->createPaidOrderAt('2026-07-08 10:00:00', 10000, 1);
        $this->createPaidOrderAt('2026-07-08 11:00:00', 10000, 1, 'USDT');
        $this->createPaidOrderAt('2026-07-07 09:00:00', 15000, 2);
        $this->createPaidOrderAt('2026-07-07 10:00:00', 15000, 2);

        app(TelegramAdminStatsIntents::class)->showStoreGrowth($customer, 'admin-chat-123');

        $text = (string) Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage'))->first()[0]['text'];

        $this->assertStringContainsString('Order Dibayar: 3 (📈 +50%)', $text);
        $this->assertStringContainsString('Produk Terjual: 3 (📉 -25%)', $text);
        $this->assertStringContainsString('Nilai Penjualan: Rp30.000 (➖ 0%)', $text);
        $this->assertStringContainsString('• USDT: 0.01 USDT (📈 Baru)', $text);
        $this->assertStringNotContainsString('INF', $text);
        $this->assertStringNotContainsString('NAN', $text);
        $this->assertStringNotContainsString('Division by zero', $text);
    }

    public function test_store_growth_output_compares_each_receipt_currency_independently(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 15:00:00', 'Asia/Jakarta'));
        config()->set('app.timezone', 'Asia/Jakarta');
        config()->set('services.telegram.token', 'bot-token');

        Http::fake([
            'https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 111]]),
        ]);

        $customer = Customer::factory()->create();
        $this->createPaidOrderAt('2026-07-08 10:00:00', 20000, 1, 'IDR', 1000);
        $this->createPaidOrderAt('2026-07-08 11:00:00', 2_000_000, 1, 'USDT');
        $this->createPaidOrderAt('2026-07-07 10:00:00', 10000, 1, 'IDR', 500);

        app(TelegramAdminStatsIntents::class)->showStoreGrowth($customer, 'admin-chat-123');

        $text = (string) Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage'))->first()[0]['text'];

        $this->assertMatchesRegularExpression('/• Rupiah: Rp19\.000 \(📈 \+100%\)/', $text);
        $this->assertMatchesRegularExpression('/• USDT: 2 USDT \(📈 Baru\)/', $text);
        $this->assertStringNotContainsString('Total Pembayaran:', $text);
        $this->assertStringNotContainsString('Pembayaran Diterima: Rp', $text);
        $this->assertStringNotContainsString('Pembayaran Diterima: 2 USDT', $text);
    }

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

    public function test_duplicate_paid_payment_in_today_window_is_ignored_when_canonical_payment_was_yesterday(): void
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
            'paid_at' => now()->subDay(),
        ]);
        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Paid,
            'amount' => 5_000_000,
            'currency' => 'USDT',
            'paid_at' => now()->subMinute(),
        ]);

        $stats = app(TelegramAdminStatsIntents::class)->calculateTodayStats();

        $this->assertSame(0, $stats['paid_orders']);
        $this->assertSame(0, $stats['sales_value']);
        $this->assertSame([], $stats['receipts']);
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

    private function createPaidOrderAt(
        string $paidAt,
        int $amount,
        int $quantity,
        string $currency = 'IDR',
        int $providerFee = 0,
    ): Order {
        $timestamp = Carbon::parse($paidAt, 'Asia/Jakarta');
        $customer = Customer::factory()->create();
        $product = Product::factory()->create([
            'price_amount' => $amount,
            'currency' => $currency,
        ]);
        $order = Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => $amount,
            'currency' => $currency,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        OrderItem::factory()->for($order)->for($product)->create([
            'quantity' => $quantity,
            'unit_price' => $amount,
            'subtotal' => $amount * $quantity,
        ]);
        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Paid,
            'amount' => $amount,
            'currency' => $currency,
            'provider_fee' => $providerFee,
            'paid_at' => $timestamp,
        ]);

        return $order;
    }
}
