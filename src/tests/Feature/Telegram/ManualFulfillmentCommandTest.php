<?php

namespace Tests\Feature\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\ManualFulfillmentReview;
use App\Models\Order;
use App\Models\Product;
use App\Models\TelegramUpdate;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Support\TelegramBotTestSupport;

final class ManualFulfillmentCommandTest extends TelegramBotTestSupport
{
    public function test_only_the_configured_admin_in_a_private_chat_receives_the_link(): void
    {
        config(['app.url' => 'https://shop.example.com', 'services.telegram.admin_user_id' => '222']);
        $this->fakeTelegramHttp();
        $order = Order::factory()->create(['payment_status' => PaymentStatus::Paid, 'order_status' => OrderStatus::Processing]);
        $payment = $order->payments()->create([
            'status' => PaymentStatus::Paid, 'provider' => 'manual', 'method' => 'QRIS', 'amount' => 10000,
            'merchant_ref' => 'PAY-SHORTCUT', 'currency' => 'IDR', 'paid_at' => now(),
        ]);
        $order->orderItems()->create([
            'product_id' => Product::factory()->create()->id, 'product_name' => 'Paket manual',
            'quantity' => 1, 'unit_price' => 10000, 'subtotal' => 10000,
        ]);
        $review = ManualFulfillmentReview::create([
            'order_id' => $order->id, 'payment_id' => $payment->id, 'amount' => 10000, 'currency' => 'IDR',
            'reason' => ManualFulfillmentReview::REASON_LOCAL_CREDENTIALS_MISSING, 'state' => ManualFulfillmentReview::STATE_PENDING,
        ]);

        $command = '/fulfill '.$order->invoice_number;
        $this->postTelegramWebhook($this->messageUpdate(1, $command, chatId: 333))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(2, $command, ['id' => 222], chatId: -777, chatType: 'supergroup'))->assertOk();
        Http::assertNothingSent();

        $this->postTelegramWebhook($this->messageUpdate(3, $command, chatId: 222))->assertOk();
        Http::assertSentCount(1);
        $path = route('admin.manual-fulfillment-reviews.show', $review, absolute: false);
        $this->assertSame('https://shop.example.com'.$path.'#local-credentials', Http::recorded()->sole()[0]['reply_markup']['inline_keyboard'][0][0]['url']);

        $this->get($path)->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]))
            ->get($path)->assertOk()->assertSee('id="local-credentials"', false)->assertSee('Paket manual');
    }

    public function test_trailing_credentials_are_not_retained_or_echoed(): void
    {
        config(['services.telegram.admin_user_id' => '222']);
        $this->fakeTelegramHttp();
        $secret = 'account@example.com | fixture-password';
        $update = $this->messageUpdate(1, "/fulfill@StoreBot INV-EXAMPLE\n".$secret, chatId: 222);
        $this->postTelegramWebhook($update)->assertOk();

        $this->assertSame('/fulfill', TelegramUpdate::query()->sole()->payload['message']['text']);
        $this->assertStringNotContainsString($secret, json_encode(Http::recorded()->sole()[0]->data()));
        $this->assertStringContainsString('Jangan kirim password', Http::recorded()->sole()[0]['text']);
    }
}
