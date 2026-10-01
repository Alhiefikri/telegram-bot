<?php

namespace Tests\Feature\Telegram;

use App\Enums\TelegramOutboundEffectState;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentBscDetail;
use App\Models\Product;
use App\Models\TelegramOutboundEffect;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\Telegram\TelegramBinanceRailSelectionSession;
use App\Services\Telegram\TelegramCheckoutIntents;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Support\Facades\Http;
use Tests\Support\TelegramBotTestSupport;

/**
 * The customer Binance Pay instruction is a durable outbound effect. These
 * tests drive the whole Telegram path (selector -> rail -> order -> effect ->
 * inline sync job) so a silent drop cannot hide behind a mocked intent layer.
 */
class BinanceCheckoutInstructionDeliveryTest extends TelegramBotTestSupport
{
    public function test_binance_pay_checkout_delivers_the_instructions_to_the_customer(): void
    {
        $this->enableBinanceWithAutoVerification();
        $this->fakeTelegramHttp();
        $customer = $this->customerWithCart();

        $this->selectBinanceRail($customer);

        $order = Order::query()->sole();
        $payment = $order->payments()->sole();
        $this->assertSame('BINANCE_PAY_ID', $payment->method);

        $effect = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_BINANCE_CHECKOUT)
            ->sole();
        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->state);

        $instruction = Http::recorded(fn ($request): bool => str_contains($request->url(), '/sendPhoto'));
        $this->assertCount(1, $instruction, 'The customer Binance instruction was never delivered.');

        $caption = (string) $this->telegramRequestField($instruction->first()[0], 'caption');
        $this->assertStringContainsString('987654321', $caption);
        $this->assertStringContainsString('2.5 USDT', $caption);
    }

    public function test_binance_pay_checkout_delivers_when_the_product_has_a_custom_emoji_icon(): void
    {
        $this->enableBinanceWithAutoVerification();
        $this->fakeTelegramHttp();
        $customer = $this->customerWithCart(customEmojiIcon: true);

        $this->selectBinanceRail($customer);

        $effect = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_BINANCE_CHECKOUT)
            ->sole();
        $this->assertSame(
            TelegramOutboundEffectState::Sent,
            $effect->state,
            'The customer Binance instruction effect did not reach a sent state.',
        );

        $sent = Http::recorded(fn ($request): bool => str_contains($request->url(), '/sendPhoto')
            || str_contains($request->url(), '/sendMessage'));
        $this->assertNotSame([], $sent->all(), 'The customer received no Binance instruction at all.');

        $photo = Http::recorded(fn ($request): bool => str_contains($request->url(), '/sendPhoto'))->first();
        $this->assertNotNull($photo);
        $caption = (string) $this->telegramRequestField($photo[0], 'caption');
        $entities = json_decode(
            (string) $this->telegramRequestField($photo[0], 'caption_entities'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $codeEntities = array_values(array_filter($entities, fn (array $entity): bool => $entity['type'] === 'code'));
        $this->assertCount(2, $codeEntities);
        $resolved = array_map(
            fn (array $entity): string => $this->telegramEntityText($caption, $entity),
            $codeEntities,
        );
        $this->assertSame(
            ['2.5', '987654321'],
            $resolved,
            'The copyable Binance entities no longer point at the amount and receiver.',
        );
    }

    public function test_binance_instruction_still_delivers_when_the_receiver_is_not_copyable(): void
    {
        $this->enableBinanceWithAutoVerification();
        $this->fakeTelegramHttp();
        [$order, $payment, $customer] = $this->checkoutBinancePay();
        config()->set('services.binance.pay_id', null);
        $payment->forceFill(['binance_receiver_value' => '', 'binance_receiver_type' => 'pay_id'])->save();

        $response = app(TelegramCheckoutIntents::class)->sendBinancePaymentInstructions(
            (string) $customer->telegram_id,
            $order,
            $payment->fresh(),
            $customer,
        );

        $this->assertSame(123456, data_get($response, 'result.message_id'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/sendPhoto'));
    }

    public function test_bsc_instruction_keeps_the_amount_and_address_copyable_with_a_custom_emoji_icon(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_name' => 'AI Premium',
            'product_icon' => '🪙',
            'product_custom_emoji_id' => '5368324170671202286',
        ]);
        $payment = Payment::factory()->for($order)->create([
            'provider' => 'manual',
            'method' => 'USDT_BEP20',
            'amount' => 2_500_000,
            'currency' => 'USDT',
        ]);
        $detail = PaymentBscDetail::factory()->forPayment($payment)->create();

        app(TelegramCheckoutIntents::class)->sendBscPaymentInstructions(
            '12345',
            $order->fresh(),
            $payment->fresh(),
            $customer,
        );

        $request = Http::recorded(fn ($request): bool => str_contains($request->url(), '/sendMessage'))->last()[0];
        $text = (string) ($request['text'] ?? '');
        $codeEntities = array_values(array_filter(
            $request['entities'] ?? [],
            fn (array $entity): bool => ($entity['type'] ?? null) === 'code',
        ));
        $this->assertCount(2, $codeEntities);
        $this->assertSame(
            ['2.5', $detail->receiver_address],
            array_map(fn (array $entity): string => $this->telegramEntityText($text, $entity), $codeEntities),
            'The copyable BSC entities no longer point at the amount and address.',
        );
    }

    public function test_binance_text_fallback_keeps_the_amount_and_receiver_copyable_with_a_custom_emoji_icon(): void
    {
        $this->enableBinanceWithAutoVerification();
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => false,
                'description' => 'Bad Request: photo rejected',
            ], 400),
        ]);
        $customer = $this->customerWithCart(customEmojiIcon: true);

        $this->selectBinanceRail($customer);

        $fallback = Http::recorded(fn ($request): bool => str_contains($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), '2.5 USDT')
            && str_contains((string) ($request['text'] ?? ''), '987654321'))
            ->last();
        $this->assertNotNull($fallback, 'The Binance text fallback was not delivered.');

        $text = (string) ($fallback[0]['text'] ?? '');
        $codeEntities = array_values(array_filter(
            $fallback[0]['entities'] ?? [],
            fn (array $entity): bool => ($entity['type'] ?? null) === 'code',
        ));
        $this->assertCount(2, $codeEntities);
        $this->assertSame(
            ['2.5', '987654321'],
            array_map(fn (array $entity): string => $this->telegramEntityText($text, $entity), $codeEntities),
            'The fallback copyable entities no longer point at the amount and receiver.',
        );
    }

    private function enableBinanceWithAutoVerification(): void
    {
        config()->set('services.binance.pay_id', '987654321');
        config()->set('services.binance.api_key', 'binance-key');
        config()->set('services.binance.api_secret', 'binance-secret');
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_pay_enabled' => true,
            'admin_telegram_chat_id' => '999',
            'binance_verification_mode' => 'amount_unique',
            'is_active' => true,
        ]);
    }

    /** @return array{0: Order, 1: Payment, 2: Customer} */
    private function checkoutBinancePay(): array
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $product = Product::factory()->create([
            'name' => 'AI Premium',
            'stock' => 5,
            'reserved_stock' => 0,
            'price_amount' => 50_000,
            'usdt_price_amount' => 2_500_000,
        ]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->addItem($cart, $product, 1);
        $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::binancePay());

        return [$order, $order->payments()->sole(), $customer];
    }

    private function customerWithCart(bool $customEmojiIcon = false): Customer
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $product = Product::factory()->create([
            'name' => 'AI Premium',
            'stock' => 5,
            'reserved_stock' => 0,
            'price_amount' => 50_000,
            'usdt_price_amount' => 2_500_000,
            'icon' => $customEmojiIcon ? '🪙' : null,
            'custom_emoji_id' => $customEmojiIcon ? '5368324170671202286' : null,
        ]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->addItem($cart, $product, 1);

        return $customer;
    }

    private function selectBinanceRail(Customer $customer): void
    {
        $this->postTelegramWebhook($this->callbackUpdate(174, 'cb-binance-selector', 'checkout:binance', [], 12345, 'private', 8100))->assertOk();
        $selection = app(TelegramBinanceRailSelectionSession::class)->get($customer);
        $this->assertNotNull($selection);

        $this->postTelegramWebhook($this->callbackUpdate(
            175,
            'cb-binance-queued',
            'checkout:binance:rail:binance_pay_id:'.$selection['nonce'],
            [],
            12345,
            'private',
            123457,
        ))->assertOk();
    }
}
