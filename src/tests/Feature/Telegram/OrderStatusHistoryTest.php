<?php

namespace Tests\Feature\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Services\CartService;
use Illuminate\Support\Facades\Http;
use Tests\Support\TelegramBotTestSupport;

class OrderStatusHistoryTest extends TelegramBotTestSupport
{
    public function test_batal_abandons_cart_and_status_reports_latest_order(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        app(CartService::class)->activeCartFor($customer);
        $order = Order::factory()->create(['customer_id' => $customer->id]);

        $this->postTelegramWebhook($this->messageUpdate(115, '/batal'))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(116, '/status'))->assertOk();

        $this->assertDatabaseHas('carts', ['customer_id' => $customer->id, 'status' => 'abandoned']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') && str_contains($r['text'], $order->invoice_number));
    }

    public function test_riwayat_lists_latest_customer_orders_with_detail_buttons(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        $old = Order::factory()->create(['customer_id' => $customer->id, 'invoice_number' => 'INV-OLD', 'created_at' => now()->subDay()]);
        $new = Order::factory()->create(['customer_id' => $customer->id, 'invoice_number' => 'INV-NEW', 'payment_status' => PaymentStatus::Paid, 'created_at' => now()]);

        $this->postTelegramWebhook($this->messageUpdate(201, '/riwayat'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Riwayat Pesanan')
            && strpos($r['text'], $new->invoice_number) < strpos($r['text'], $old->invoice_number)
            && str_contains($r['text'], 'paid')
            && str_contains(json_encode($r->data()), 'order:'.$new->invoice_number));
    }

    public function test_riwayat_fallback_when_no_orders(): void
    {
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(202, '/riwayat'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Riwayat masih kosong')
            && str_contains($r['text'], 'Yuk lihat produk dulu'));
    }

    public function test_riwayat_hides_other_customer_orders(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        $own = Order::factory()->create(['customer_id' => $customer->id, 'invoice_number' => 'INV-OWN']);
        Order::factory()->create(['invoice_number' => 'INV-OTHER']);

        $this->postTelegramWebhook($this->messageUpdate(203, '/riwayat'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], $own->invoice_number)
            && ! str_contains($r['text'], 'INV-OTHER'));
    }

    public function test_order_without_invoice_sends_usage_instruction(): void
    {
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(204, '/order'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Ketik nomor invoice')
            && str_contains($r['text'], 'INV-'));
    }

    public function test_order_valid_detail_includes_items_payment_and_pending_instruction(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'invoice_number' => 'INV-DETAIL', 'order_status' => OrderStatus::PendingPayment, 'payment_status' => PaymentStatus::Pending]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_name' => 'Canva Pro', 'quantity' => 2, 'subtotal' => 20000]);
        Payment::factory()->create(['order_id' => $order->id, 'method' => 'hosted', 'provider' => 'pakasir', 'status' => PaymentStatus::Pending]);

        $this->postTelegramWebhook($this->messageUpdate(205, '/order INV-DETAIL'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Detail Pesanan')
            && str_contains($r['text'], 'Canva Pro x 2')
            && str_contains($r['text'], 'hosted')
            && str_contains($r['text'], 'Selesaikan pembayaran'));
    }

    public function test_order_cannot_access_other_customer_order(): void
    {
        $this->fakeTelegramHttp();
        Order::factory()->create(['invoice_number' => 'INV-SECRET']);

        $this->postTelegramWebhook($this->messageUpdate(206, '/order INV-SECRET'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Pesanan tidak ditemukan')
            && ! str_contains($r['text'], 'INV-SECRET'));
    }

    public function test_order_callback_valid_detail_and_manipulated_callback_cannot_leak_other_order(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        $own = Order::factory()->create(['customer_id' => $customer->id, 'invoice_number' => 'INV-CB', 'order_status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Paid]);
        OrderItem::factory()->create(['order_id' => $own->id, 'product_name' => 'Netflix']);
        Order::factory()->create(['invoice_number' => 'INV-OTHER-CB']);

        $this->postTelegramWebhook($this->callbackUpdate(207, 'cb-order', 'order:INV-CB'))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(208, 'cb-order-bad', 'order:INV-OTHER-CB'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'INV-CB')
            && str_contains($r['text'], 'Pembayaran sudah diterima'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Pesanan tidak ditemukan')
            && ! str_contains($r['text'], 'INV-OTHER-CB'));
    }

    public function test_order_detail_expired_or_cancelled_instructs_checkout_again(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        Order::factory()->create(['customer_id' => $customer->id, 'invoice_number' => 'INV-EXP', 'order_status' => OrderStatus::Expired, 'payment_status' => PaymentStatus::Expired]);
        Order::factory()->create(['customer_id' => $customer->id, 'invoice_number' => 'INV-CAN', 'order_status' => OrderStatus::Cancelled, 'payment_status' => PaymentStatus::Failed]);

        $this->postTelegramWebhook($this->messageUpdate(209, '/order INV-EXP'))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(210, '/order INV-CAN'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') && str_contains($r['text'], 'checkout ulang lewat /produk'));
    }
}
