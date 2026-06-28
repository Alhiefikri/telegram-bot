<?php

namespace Tests\Feature\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\CartService;
use App\Services\OrderCheckoutService;
use App\Services\Telegram\TelegramReceiptNotifier;
use Illuminate\Support\Facades\Http;
use Tests\Support\TelegramBotTestSupport;

class PaymentProofAdminCallbackTest extends TelegramBotTestSupport
{
    public function test_payment_photo_is_forwarded_to_admin_and_admin_approval_sends_receipt(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer']);
        $product = Product::factory()->create(['name' => 'Email Account', 'stock' => 5]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);

        $this->postTelegramWebhook($this->callbackUpdate(211, 'cb-manual', 'checkout'))->assertOk();
        $order = Order::firstOrFail();

        $this->postTelegramWebhook($this->paymentPhotoUpdate(212, $order->invoice_number, ['username' => 'buyer']))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendPhoto')
            && $r['chat_id'] === '999'
            && $r['photo'] === 'large-photo'
            && str_contains((string) ($r['caption'] ?? ''), 'Bukti pembayaran masuk'));

        $this->postTelegramWebhook($this->callbackUpdate(213, 'approve-payment', 'admin:approve-payment:'.$order->id, ['id' => 999, 'first_name' => 'Admin'], 999))->assertOk();

        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && $r['chat_id'] === 12345
            && str_contains($r['text'], 'Pembayaran diterima')
            && str_contains($r['text'], 'Email Account'));
    }

    public function test_receipt_notifier_sends_paid_order_receipt(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 777]);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'payment_status' => PaymentStatus::Paid]);
        Payment::factory()->create(['order_id' => $order->id, 'status' => PaymentStatus::Paid]);

        app(TelegramReceiptNotifier::class)->send($order);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') && $r['chat_id'] === 777 && str_contains($r['text'], 'Pembayaran diterima'));
    }

    public function test_pakasir_paid_callback_sends_telegram_receipt_once_on_duplicate_callback(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 888]);
        $product = Product::factory()->create(['stock' => 5]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);
        $order = app(OrderCheckoutService::class)->checkout($customer->carts()->first(), 'hosted', 'pakasir');
        $payment = $order->payments()->first();
        $payload = ['project' => 'sedulur-digital', 'order_id' => $payment->merchant_ref, 'amount' => $payment->amount, 'status' => 'completed'];
        $this->fakeTelegramHttp([
            'https://app.pakasir.test/api/transactiondetail*' => Http::response(['transaction' => $payload]),
        ]);

        $this->postJson('/api/webhook/pakasir', $payload)->assertOk();
        $this->postJson('/api/webhook/pakasir', $payload)->assertUnprocessable();

        $sent = 0;
        Http::assertSent(function ($r) use (&$sent) {
            if (str_ends_with($r->url(), '/sendMessage') && ($r['chat_id'] ?? null) === 888) {
                $sent++;
            }

            return true;
        });
        $this->assertSame(1, $sent);
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
    }
}
