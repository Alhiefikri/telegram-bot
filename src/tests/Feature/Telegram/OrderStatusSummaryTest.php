<?php

namespace Tests\Feature\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\TelegramNavigationRoot;
use App\Services\Telegram\TelegramOrderIntents;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\TelegramBotTestSupport;

class OrderStatusSummaryTest extends TelegramBotTestSupport
{
    public function test_summary_includes_latest_items_and_paid_spending_by_creation_month_only_for_customer(): void
    {
        $this->travelTo(now()->setDate(2026, 1, 1)->startOfDay());
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $latest = Order::factory()->create(['customer_id' => $customer->id, 'invoice_number' => 'INV-LATEST', 'total_amount' => 35000, 'payment_status' => PaymentStatus::Paid, 'order_status' => OrderStatus::Processing]);
        OrderItem::factory()->create(['order_id' => $latest->id, 'product_name' => "Netflix\nPremium", 'quantity' => 2]);
        Order::factory()->create(['customer_id' => $customer->id, 'created_at' => '2025-12-01 00:00:00', 'total_amount' => 140000, 'payment_status' => PaymentStatus::Paid]);
        Order::factory()->create(['customer_id' => $customer->id, 'created_at' => '2025-12-31 23:59:59', 'total_amount' => 999, 'payment_status' => PaymentStatus::Refunded]);
        Order::factory()->create(['customer_id' => $customer->id, 'created_at' => '2025-12-15 00:00:00', 'total_amount' => 999, 'payment_status' => PaymentStatus::Pending]);
        Order::factory()->create(['customer_id' => $customer->id, 'created_at' => '2025-11-30 23:59:59', 'total_amount' => 999, 'payment_status' => PaymentStatus::Paid]);
        Order::factory()->create(['invoice_number' => 'INV-OTHER-CUSTOMER', 'total_amount' => 999, 'payment_status' => PaymentStatus::Paid]);

        $this->postTelegramWebhook($this->messageUpdate(801, '/status'))->assertOk();
        $request = $this->messageContaining('INV-LATEST');
        $this->assertStringContainsString('Netflix Premium × 2', $request['text']);
        $this->assertStringContainsString('Jan 2026 · bulan ini', $request['text']);
        $this->assertStringContainsString('Des 2025 · bulan lalu', $request['text']);
        $this->assertStringContainsString('1 pesanan lunas · Rp 35.000', $request['text']);
        $this->assertStringContainsString('1 pesanan lunas · Rp 140.000', $request['text']);
        $this->assertStringNotContainsString('999', $request['text']);
        $keyboard = json_encode($request['reply_markup'], JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('om:202512', $keyboard);
        $this->assertStringNotContainsString('INV-OTHER-CUSTOMER', $keyboard);
        $this->assertStringNotContainsString('Bayar', $keyboard);
    }

    public function test_pending_order_has_deadline_and_signed_payment_link_without_credentials(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'order_status' => OrderStatus::PendingPayment, 'payment_status' => PaymentStatus::Pending]);
        Payment::factory()->create(['order_id' => $order->id, 'status' => PaymentStatus::Pending, 'expired_at' => now()->addMinutes(20)]);
        for ($i = 0; $i < 5; $i++) {
            OrderItem::factory()->create(['order_id' => $order->id, 'product_name' => str_repeat('Produk ', 100), 'quantity' => 1]);
        }
        $this->postTelegramWebhook($this->messageUpdate(802, '/status'))->assertOk();
        $request = $this->messageContaining($order->invoice_number);
        $this->assertStringContainsString('Batas pembayaran:', $request['text']);
        $this->assertStringContainsString('+ 2 produk lainnya', $request['text']);
        $this->assertLessThan(2000, mb_strlen($request['text']));
        $button = collect($request['reply_markup']['inline_keyboard'])->flatten(1)->firstWhere('text', '💳 Bayar');
        $this->assertStringContainsString('/shop/orders/'.$order->id, $button['url']);
        $this->assertStringContainsString('signature=', $button['url']);
        $this->get($button['url'])->assertOk();
    }

    public function test_empty_status_shows_both_months_and_month_button_opens_empty_screen(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 1));
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $this->postTelegramWebhook($this->messageUpdate(803, '/status'))->assertOk();
        $request = $this->messageContaining('Ringkasan belanja');
        $this->assertSame(2, substr_count($request['text'], '0 pesanan lunas · Rp 0'));
        $this->postTelegramWebhook($this->callbackUpdate(804, 'cb-empty-month', 'om:202609'))->assertOk();
        $this->messageContaining('Belum ada pesanan di bulan ini.');
    }

    public function test_month_navigation_preserves_filter_and_adjacent_pages_in_both_directions(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 1));
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        for ($i = 1; $i <= 31; $i++) {
            Order::factory()->create(['customer_id' => $customer->id, 'invoice_number' => 'INV-SEP-'.$i, 'created_at' => now()->startOfMonth()->subMinutes($i), 'payment_status' => PaymentStatus::Pending, 'order_status' => OrderStatus::PendingPayment]);
        }
        $otherMonth = Order::factory()->create(['customer_id' => $customer->id, 'invoice_number' => 'INV-OCT', 'payment_status' => PaymentStatus::Paid]);
        $otherCustomer = Order::factory()->create(['invoice_number' => 'INV-PRIVATE', 'created_at' => now()->subDay()]);
        $this->postTelegramWebhook($this->callbackUpdate(805, 'cb-september', 'om:202609'))->assertOk();
        $first = $this->messageContaining('📅 Pesanan · Sep 2026');
        $buttons = collect($first['reply_markup']['inline_keyboard'])->flatten(1);
        $this->assertSame('order:INV-SEP-1', $buttons[0]['callback_data']);
        $this->assertStringNotContainsString($otherMonth->invoice_number, json_encode($buttons));
        $this->assertStringNotContainsString($otherCustomer->invoice_number, json_encode($buttons));
        $root = TelegramNavigationRoot::query()->where('customer_id', $customer->id)->firstOrFail();
        $cursor = $buttons->first(fn ($button) => str_starts_with($button['callback_data'], 'om:'))['callback_data'];
        $this->assertLessThanOrEqual(64, strlen($cursor));
        $this->postTelegramWebhook($this->callbackUpdate(806, 'cb-page-two', $cursor, messageId: $root->message_id))->assertOk();
        $second = $this->lastEdit();
        $this->assertSame('order:INV-SEP-11', $second['reply_markup']['inline_keyboard'][0][0]['callback_data']);
        $pageTwoButtons = collect($second['reply_markup']['inline_keyboard'])->flatten(1);
        $next = $pageTwoButtons->first(fn ($b) => str_starts_with($b['callback_data'], 'om:202609:o:'))['callback_data'];
        $this->postTelegramWebhook($this->callbackUpdate(807, 'cb-page-three', $next, messageId: $root->message_id))->assertOk();
        $third = $this->lastEdit();
        $this->assertSame('order:INV-SEP-21', $third['reply_markup']['inline_keyboard'][0][0]['callback_data']);
        $previous = collect($third['reply_markup']['inline_keyboard'])->flatten(1)->first(fn ($b) => str_starts_with($b['callback_data'], 'om:202609:n:'))['callback_data'];
        $this->postTelegramWebhook($this->callbackUpdate(808, 'cb-return-two', $previous, messageId: $root->message_id))->assertOk();
        $this->assertSame($second['reply_markup'], $this->lastEdit()['reply_markup']);
    }

    public function test_month_buttons_and_pagination_are_private_and_status_home_opens_menu(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        $cursor = 'om:'.now()->format('Ym').':o:'.$order->created_at->format('YmdHis').':'.$order->id;
        $this->postTelegramWebhook($this->callbackUpdate(809, 'cb-group-month', 'om:'.now()->format('Ym'), chatType: 'group'))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(810, 'cb-group-page', $cursor, chatType: 'group'))->assertOk();
        Http::assertNotSent(fn (Request $r) => str_contains((string) ($r['text'] ?? ''), $order->invoice_number));
        $this->assertDatabaseCount('telegram_navigation_roots', 0);
        $this->postTelegramWebhook($this->callbackUpdate(811, 'cb-status-home', 'status:home'))->assertOk();
        $this->assertDatabaseHas('telegram_navigation_roots', ['customer_id' => $customer->id, 'screen' => 'home']);
        $this->assertNull(app(TelegramOrderIntents::class)->monthFromCallback('om:202613'));
    }

    private function messageContaining(string $text): Request
    {
        $requests = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && str_contains((string) ($r['text'] ?? ''), $text));
        $this->assertCount(1, $requests);

        return $requests->first()[0];
    }

    private function lastEdit(): Request
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/editMessageText'))->last()[0];
    }
}
