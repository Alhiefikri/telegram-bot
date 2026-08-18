<?php

namespace Tests\Feature\Telegram;

use App\Enums\TelegramOutboundEffectState;
use App\Jobs\ProcessTelegramUpdate;
use App\Jobs\SendBinanceCheckoutInstructions;
use App\Jobs\SendKlikQrisCheckoutInstructions;
use App\Jobs\SendManualCheckoutNotifications;
use App\Jobs\SendPakasirCheckoutInstructions;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Product;
use App\Models\TelegramOutboundEffect;
use App\Models\TelegramUpdate;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPaymentIntent;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\Telegram\TelegramCheckoutIntents;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\Telegram\TelegramUpdateProcessor;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\Support\TelegramBotTestSupport;

#[Group('concurrency')]
class TelegramCheckoutOutboundEffectTest extends TelegramBotTestSupport
{
    public function test_each_telegram_checkout_rail_records_one_idempotent_effect_in_the_checkout_transaction(): void
    {
        config()->set('services.binance.pay_id', '987654321');
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_pay_enabled' => true,
            'is_active' => true,
        ]);
        KlikQrisSandboxScenarios::configureReadySandbox();

        $orders = [
            'manual' => $this->checkout(CheckoutPaymentIntents::manualQris()),
            'klikqris' => $this->checkout(CheckoutPaymentIntents::klikQris()),
            'pakasir' => $this->checkout(CheckoutPaymentIntents::pakasir()),
            'binance' => $this->checkout(CheckoutPaymentIntents::binancePay(), usdt: true),
        ];

        $effects = TelegramOutboundEffect::query()->get()->keyBy('effect_type');
        $expected = [
            'manual' => TelegramOutboundEffectService::TYPE_MANUAL_CHECKOUT,
            'klikqris' => TelegramOutboundEffectService::TYPE_KLIKQRIS_CHECKOUT,
            'pakasir' => TelegramOutboundEffectService::TYPE_PAKASIR_CHECKOUT,
            'binance' => TelegramOutboundEffectService::TYPE_BINANCE_CHECKOUT,
        ];

        $this->assertCount(4, $effects);
        foreach ($expected as $rail => $effectType) {
            $effect = $effects->get($effectType);
            $this->assertNotNull($effect, $rail);
            $this->assertSame(
                TelegramOutboundEffectService::checkoutEffectKey($orders[$rail]->id, $effectType),
                $effect->effect_key,
            );
            $this->assertSame(TelegramOutboundEffectState::Pending, $effect->state);
            $this->assertSame($orders[$rail]->id, $effect->source_id);
        }

        $this->assertSame(1, TelegramOutboundEffect::query()
            ->where('effect_key', TelegramOutboundEffectService::checkoutEffectKey(
                $orders['klikqris']->id,
                TelegramOutboundEffectService::TYPE_KLIKQRIS_CHECKOUT,
            ))
            ->count());
    }

    public function test_public_klikqris_checkout_dispatches_the_recorded_effect_id(): void
    {
        Queue::fake();
        $this->fakeTelegramHttp();
        KlikQrisSandboxScenarios::configureReadySandbox();
        config()->set('services.binance.pay_id', null);
        config()->set('services.binance.pay_uid', null);
        ManualPaymentSetting::create([
            'payment_mode' => 'klikqris',
            'pakasir_checkout_flow' => 'api_qris',
            'binance_pay_enabled' => false,
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 0]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->addItem($cart, $product, 1);

        $this->postTelegramWebhook($this->callbackUpdate(2681, 'cb-268-klik', 'checkout:current'))->assertOk();
        $update = TelegramUpdate::query()->sole();
        (new ProcessTelegramUpdate($update->id))->handle(app(TelegramUpdateProcessor::class));

        $this->assertSame(1, Order::query()->count());
        $order = Order::query()->sole();
        $effect = TelegramOutboundEffect::query()->sole();
        Queue::assertPushed(
            SendKlikQrisCheckoutInstructions::class,
            fn (SendKlikQrisCheckoutInstructions $job): bool => $job->orderId === $order->id
                && $job->effectId === $effect->id,
        );
    }

    public function test_recovery_redispatches_a_pending_provider_effect_by_effect_id(): void
    {
        Queue::fake([SendBinanceCheckoutInstructions::class]);
        config()->set('services.binance.pay_id', '987654321');
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_pay_enabled' => true,
            'is_active' => true,
        ]);
        $order = $this->checkout(CheckoutPaymentIntents::binancePay(), usdt: true);
        $effect = TelegramOutboundEffect::query()->sole();

        Artisan::call('telegram:recover-outbound', ['--chunk' => 100, '--queue' => 'notifications']);

        Queue::assertPushed(
            SendBinanceCheckoutInstructions::class,
            fn (SendBinanceCheckoutInstructions $job): bool => $job->orderId === $order->id
                && $job->effectId === $effect->id,
        );
    }

    public function test_binance_effect_uses_the_payment_snapshot_and_does_not_retry_ambiguous_telegram_delivery(): void
    {
        config()->set('services.binance.pay_id', '987654321');
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_pay_enabled' => true,
            'is_active' => true,
        ]);
        $order = $this->checkout(CheckoutPaymentIntents::binancePay(), usdt: true);
        config()->set('services.binance.pay_id', 'changed-after-checkout');

        $sendCount = 0;
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => function () use (&$sendCount): never {
                $sendCount++;
                throw new ConnectionException('connection closed after Telegram accepted the request');
            },
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $job = new SendBinanceCheckoutInstructions($order->id);
        $job->handle(app(TelegramCheckoutIntents::class));
        $job->handle(app(TelegramCheckoutIntents::class));

        $effect = TelegramOutboundEffect::query()->sole();
        $this->assertSame(TelegramOutboundEffectState::Unknown, $effect->state);
        $this->assertSame(1, $sendCount);
        $this->assertNotNull($effect->unknown_at);
        $this->assertSame('987654321', $order->payments()->sole()->binance_receiver_value);
    }

    public function test_checkout_delivery_pipeline_uses_the_shared_effect_protocol_for_each_payment_rail(): void
    {
        config()->set('services.binance.pay_id', '987654321');
        KlikQrisSandboxScenarios::configureReadySandbox();
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_pay_enabled' => true,
            'is_active' => true,
        ]);

        $orders = [
            'manual' => $this->checkout(CheckoutPaymentIntents::manualQris()),
            'klikqris' => $this->checkout(CheckoutPaymentIntents::klikQris()),
            'pakasir' => $this->checkout(CheckoutPaymentIntents::pakasir()),
            'binance' => $this->checkout(CheckoutPaymentIntents::binancePay(), usdt: true),
        ];
        $intents = $this->mock(TelegramCheckoutIntents::class);
        $response = ['ok' => true, 'result' => ['message_id' => 654321]];
        $intents->shouldReceive('sendPaymentInstructions')->once()->andReturn($response);
        $intents->shouldReceive('sendKlikQrisPaymentInstructions')->once()->andReturn($response);
        $intents->shouldReceive('sendPakasirPaymentInstructions')->once()->andReturn($response);
        $intents->shouldReceive('sendBinancePaymentInstructions')->once()->andReturn($response);

        (new SendManualCheckoutNotifications($orders['manual']->id))->handle($intents);
        (new SendKlikQrisCheckoutInstructions($orders['klikqris']->id))->handle($intents);
        (new SendPakasirCheckoutInstructions($orders['pakasir']->id))->handle($intents);
        (new SendBinanceCheckoutInstructions($orders['binance']->id))->handle($intents);

        $this->assertCount(4, TelegramOutboundEffect::query()->where('state', TelegramOutboundEffectState::Sent->value)->get());
        $this->assertSame(4, TelegramOutboundEffect::query()->whereNotNull('telegram_message_id')->count());
        foreach ($orders as $order) {
            $this->assertNotNull($order->fresh()->manual_checkout_customer_notified_at);
        }
    }

    public function test_checkout_delivery_without_a_successful_message_id_stays_failed_and_retryable(): void
    {
        $order = $this->checkout(CheckoutPaymentIntents::manualQris());
        $intents = $this->mock(TelegramCheckoutIntents::class);
        $intents->shouldReceive('sendPaymentInstructions')
            ->once()
            ->andReturn(['ok' => true]);

        try {
            (new SendManualCheckoutNotifications($order->id))->handle($intents);
            $this->fail('A checkout response without a message identifier must fail the effect.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('successful message ID', $exception->getMessage());
        }

        $effect = TelegramOutboundEffect::query()->sole();
        $this->assertSame(TelegramOutboundEffectState::Failed, $effect->state);
        $this->assertSame('telegram_response_missing_message_id', $effect->last_error_code);
        $this->assertNull($effect->telegram_message_id);
        $this->assertNull($effect->claim_token);
        $this->assertNotNull($effect->next_attempt_at);
        $this->assertNull($order->fresh()->manual_checkout_customer_notified_at);
    }

    public function test_checkout_delivery_with_a_non_positive_message_id_stays_failed_and_retryable(): void
    {
        $order = $this->checkout(CheckoutPaymentIntents::manualQris());
        $intents = $this->mock(TelegramCheckoutIntents::class);
        $intents->shouldReceive('sendPaymentInstructions')
            ->once()
            ->andReturn(['ok' => true, 'result' => ['message_id' => 0]]);

        try {
            (new SendManualCheckoutNotifications($order->id))->handle($intents);
            $this->fail('A checkout response with a non-positive message identifier must fail the effect.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('successful message ID', $exception->getMessage());
        }

        $effect = TelegramOutboundEffect::query()->sole();
        $this->assertSame(TelegramOutboundEffectState::Failed, $effect->state);
        $this->assertSame('telegram_response_missing_message_id', $effect->last_error_code);
        $this->assertNull($effect->telegram_message_id);
        $this->assertNotNull($effect->next_attempt_at);
        $this->assertNull($order->fresh()->manual_checkout_customer_notified_at);
    }

    public function test_checkout_worker_rejects_an_effect_id_owned_by_another_order(): void
    {
        $firstOrder = $this->checkout(CheckoutPaymentIntents::manualQris());
        $secondOrder = $this->checkout(CheckoutPaymentIntents::manualQris());
        $secondEffect = TelegramOutboundEffect::query()
            ->where('source_id', $secondOrder->id)
            ->sole();
        $intents = $this->mock(TelegramCheckoutIntents::class);
        $intents->shouldReceive('sendPaymentInstructions')->never();

        try {
            (new SendManualCheckoutNotifications($firstOrder->id, null, $secondEffect->id))->handle($intents);
            $this->fail('A checkout worker must not deliver another order\'s effect.');
        } catch (ModelNotFoundException) {
            $this->assertSame(TelegramOutboundEffectState::Pending, $secondEffect->fresh()->state);
            $this->assertNull($secondEffect->fresh()->claim_token);
        }
    }

    private function checkout(CheckoutPaymentIntent $intent, bool $usdt = false): Order
    {
        $customer = Customer::factory()->create(['telegram_id' => fake()->unique()->numberBetween(10000, 99999)]);
        $product = Product::factory()->create([
            'stock' => 2,
            'reserved_stock' => 0,
            'price_amount' => 10000,
            'usdt_price_amount' => $usdt ? 2_500_000 : null,
        ]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->addItem($cart, $product, 1);

        return app(OrderCheckoutService::class)->checkout($cart, $intent);
    }
}
