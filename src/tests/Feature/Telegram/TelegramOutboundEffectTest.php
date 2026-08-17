<?php

namespace Tests\Feature\Telegram;

use App\Enums\TelegramOutboundEffectState;
use App\Jobs\SendManualCheckoutNotifications;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\TelegramOutboundEffect;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\Telegram\TelegramCheckoutIntents;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\TelegramBotTestSupport;

#[Group('concurrency')]
class TelegramOutboundEffectTest extends TelegramBotTestSupport
{
    public function test_manual_telegram_checkout_records_one_effect_in_the_checkout_transaction(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 0]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->addItem($cart, $product, 1);

        $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::manualQris(), 'checkout-effect-key');
        $sameOrder = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::manualQris(), 'checkout-effect-key');

        $this->assertSame($order->id, $sameOrder->id);
        $this->assertDatabaseCount('telegram_outbound_effects', 1);
        $effect = TelegramOutboundEffect::query()->sole();
        $this->assertSame(TelegramOutboundEffectService::TYPE_MANUAL_CHECKOUT, $effect->effect_type);
        $this->assertSame(TelegramOutboundEffectState::Pending, $effect->state);
        $this->assertSame(TelegramOutboundEffect::CHECKOUT_QUEUE, $effect->queue);
        $this->assertSame('sendPhoto', $effect->telegram_method);
        $this->assertSame('12345', $effect->telegram_chat_id);
        $this->assertSame($order->id, $effect->source_id);
        $this->assertStringNotContainsString('qr-lhie', json_encode($effect->getAttributes(), JSON_THROW_ON_ERROR));
    }

    public function test_successful_manual_effect_marks_sent_and_updates_the_legacy_projection(): void
    {
        $order = $this->manualTelegramOrder();
        $this->fakeTelegramHttp();

        (new SendManualCheckoutNotifications($order->id))->handle(app(TelegramCheckoutIntents::class));

        $effect = TelegramOutboundEffect::query()->sole();
        $freshOrder = $order->fresh();
        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->state);
        $this->assertSame('123456', $effect->telegram_message_id);
        $this->assertNotNull($effect->sent_at);
        $this->assertNotNull($freshOrder->manual_checkout_customer_notified_at);
        $this->assertNull($freshOrder->manual_checkout_customer_notification_started_at);
        $this->assertSame('12345', $freshOrder->manual_checkout_customer_notification_chat_id);
        $this->assertSame(123456, $freshOrder->manual_checkout_customer_notification_message_id);
    }

    public function test_ambiguous_manual_effect_becomes_unknown_and_is_not_automatically_sent_again(): void
    {
        $order = $this->manualTelegramOrder();
        $sendCount = 0;
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => function () use (&$sendCount): never {
                $sendCount++;
                throw new ConnectionException('connection closed after Telegram accepted the request');
            },
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $job = new SendManualCheckoutNotifications($order->id);
        $job->handle(app(TelegramCheckoutIntents::class));
        $job->handle(app(TelegramCheckoutIntents::class));

        $effect = TelegramOutboundEffect::query()->sole();
        $this->assertSame(TelegramOutboundEffectState::Unknown, $effect->state);
        $this->assertNull($effect->claim_token);
        $this->assertNull($effect->failed_at);
        $this->assertNotNull($effect->unknown_at);
        $this->assertSame('telegram_transport_ambiguous', $effect->last_error_code);
        $this->assertSame(1, $sendCount);
        $this->assertNotNull($order->fresh()->manual_checkout_customer_notification_started_at);
    }

    public function test_recovery_rediscovers_a_pending_effect_without_a_legacy_claim(): void
    {
        Queue::fake();
        $order = $this->manualTelegramOrder();
        $order->forceFill(['manual_checkout_customer_notification_started_at' => null])->save();

        Artisan::call('telegram:recover-outbound', [
            '--chunk' => 100,
            '--queue' => 'notifications',
        ]);

        Queue::assertPushedOn(
            TelegramOutboundEffect::CHECKOUT_QUEUE,
            SendManualCheckoutNotifications::class,
            fn (SendManualCheckoutNotifications $job): bool => $job->orderId === $order->id,
        );
    }

    public function test_old_claim_token_cannot_finalize_a_reclaimed_effect_and_expired_lease_becomes_unknown(): void
    {
        $order = $this->manualTelegramOrder();
        $effects = app(TelegramOutboundEffectService::class);
        $effect = TelegramOutboundEffect::query()->sole();
        $claimed = $effects->claim($effect->id);

        $this->assertNotNull($claimed);
        $oldToken = (string) $claimed?->claim_token;
        $effect->forceFill([
            'claim_token' => 'new-owner-token',
            'lease_until' => now()->addMinute(),
        ])->save();

        $this->assertFalse($effects->finalizeSent($effect->id, $oldToken, '100001'));
        $this->assertSame(TelegramOutboundEffectState::Sending, $effect->fresh()->state);

        $effect->fresh()->forceFill(['lease_until' => now()->subSecond()])->save();
        $this->assertSame(1, $effects->recoverExpiredLeases(now()));
        $this->assertSame(TelegramOutboundEffectState::Unknown, $effect->fresh()->state);
    }

    private function manualTelegramOrder(): Order
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 0]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->addItem($cart, $product, 1);

        return app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::manualQris());
    }
}
