<?php

namespace Tests\Feature\Telegram;

use App\Enums\TelegramOutboundEffectState;
use App\Jobs\SendBinanceCheckoutInstructions;
use App\Jobs\SendBscCheckoutInstructions;
use App\Jobs\VerifyBscPayment;
use App\Models\BscPaymentReceiver;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\TelegramOutboundEffect;
use App\Services\Bsc\BscRpcClientInterface;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\Telegram\TelegramCheckoutIntents;
use App\Services\Telegram\TelegramIntentSession;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Support\PaymentProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TelegramBotTestSupport;

final class BscCheckoutRailTest extends TelegramBotTestSupport
{
    private const CONTRACT = '0x55d398326f99059ff775485246999027b3197955';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.binance.pay_id', '987654321');
        config()->set('services.bsc.rpc_url', 'https://bsc-rpc.test');
        config()->set('services.bsc.chain_id', 56);
        config()->set('services.bsc.usdt_contract', self::CONTRACT);
        config()->set('services.bsc.expected_token_decimals', 18);

        app()->instance(BscRpcClientInterface::class, new class implements BscRpcClientInterface
        {
            public function chainId(): int
            {
                return 56;
            }

            public function contractCode(string $contract): string
            {
                return '0x60016001';
            }

            public function tokenDecimals(string $contract): int
            {
                return 18;
            }

            public function isValidErc1271Signature(string $contract, string $messageHashHex, string $signature): bool
            {
                return true;
            }
        });

        ManualPaymentSetting::create([
            'payment_mode' => PaymentProvider::PAKASIR,
            'binance_pay_enabled' => true,
            'bsc_payment_enabled' => true,
            'is_active' => true,
        ]);
        BscPaymentReceiver::factory()->verified()->active()->create([
            'address' => '0x1111111111111111111111111111111111111111',
            'token_contract' => self::CONTRACT,
        ]);
    }

    public function test_cart_binance_callback_opens_a_bound_selector_and_bsc_creates_a_distinct_effect(): void
    {
        Queue::fake([SendBscCheckoutInstructions::class]);
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 44501, 'locale' => 'en']);
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0, 'usdt_price_amount' => 2_500_000]);
        app(CartService::class)->addItem(
            app(CartService::class)->activeCartFor($customer),
            $product,
            1,
        );

        $this->postTelegramWebhook($this->callbackUpdate(9001, 'selector', 'checkout:binance', ['id' => 44501], 44501, 'private', 7001))->assertOk();
        $selector = $this->latestSelectorMarkup();
        $this->assertNotNull($selector['nonce']);
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());

        $this->postTelegramWebhook($this->callbackUpdate(
            9002,
            'bsc-choice',
            'checkout:binance:rail:usdt_bep20:'.$selector['nonce'],
            ['id' => 44501],
            44501,
            'private',
            123457,
        ))->assertOk();

        $payment = Payment::with('bscDetail')->sole();
        $this->assertSame('USDT_BEP20', $payment->method);
        $this->assertSame(TelegramOutboundEffectService::TYPE_BSC_CHECKOUT, TelegramOutboundEffect::query()->sole()->effect_type);
        $this->assertSame(TelegramOutboundEffectState::Pending, TelegramOutboundEffect::query()->sole()->state);
        Queue::assertPushed(SendBscCheckoutInstructions::class);
        Queue::assertNotPushed(SendBinanceCheckoutInstructions::class);
        $this->assertSame('0x1111111111111111111111111111111111111111', $payment->bscDetail->receiver_address);

        $order = $payment->order;
        $effect = TelegramOutboundEffect::query()->sole();
        (new SendBscCheckoutInstructions($order->id, null, $effect->id))->handle(app(TelegramCheckoutIntents::class));
        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->fresh()->state);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'USDT BEP20 Payment')
            && str_contains((string) ($request['text'] ?? ''), '0x1111111111111111111111111111111111111111')
            && str_contains((string) ($request['text'] ?? ''), 'BNB Smart Chain (BEP20) ONLY')
            && str_contains((string) json_encode($request->data()), 'Submit TX Hash'));
    }

    public function test_detail_selector_cancel_keeps_product_choices_and_creates_no_checkout_rows(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 44502, 'locale' => 'id']);
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0, 'usdt_price_amount' => 2_500_000]);

        $this->postTelegramWebhook($this->callbackUpdate(9010, 'product', 'product:'.$product->id, ['id' => 44502], 44502, 'private', 7002))->assertOk();
        $state = app(TelegramIntentSession::class)->get($customer);
        $this->assertNotNull($state);

        $this->postTelegramWebhook($this->callbackUpdate(
            9011,
            'open-detail-selector',
            'detail:pay:binance:'.$product->id.':'.$state['interactionToken'],
            ['id' => 44502],
            44502,
            'private',
            7002,
        ))->assertOk();
        $selector = $this->latestSelectorMarkup();

        $this->postTelegramWebhook($this->callbackUpdate(
            9012,
            'cancel-selector',
            'checkout:binance:cancel:'.$selector['nonce'],
            ['id' => 44502],
            44502,
            'private',
            123457,
        ))->assertOk();

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, Cart::count());
    }

    public function test_bsc_hash_prompt_rejects_invalid_format_without_an_attempt_and_queues_valid_hashes(): void
    {
        Queue::fake([VerifyBscPayment::class]);
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 44503, 'locale' => 'en']);
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0, 'usdt_price_amount' => 2_500_000]);
        $cartService = app(CartService::class);
        $cart = $cartService->activeCartFor($customer);
        $cartService->addItem($cart, $product, 1);
        $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::usdtBep20());
        $payment = $order->payments()->with('bscDetail')->sole();

        $this->postTelegramWebhook($this->callbackUpdate(9020, 'bsc-submit', 'bsc:submit:'.$order->id, ['id' => 44503], 44503, 'private', 9200))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(9021, '0x1234', ['id' => 44503], 44503))->assertOk();
        $this->assertSame(0, (int) $payment->bscDetail->fresh()->tx_attempts);

        $hash = '0x'.str_repeat('d', 64);
        $this->postTelegramWebhook($this->messageUpdate(9022, $hash, ['id' => 44503], 44503))->assertOk();
        $this->assertSame(1, (int) $payment->bscDetail->fresh()->tx_attempts);
        Queue::assertPushed(VerifyBscPayment::class, fn (VerifyBscPayment $job): bool => $job->paymentId === $payment->id && $job->submissionVersion === 1);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'transaction hash is invalid'));
    }

    /** @return array{nonce: ?string, callbacks: list<string>} */
    private function latestSelectorMarkup(): array
    {
        $latest = null;
        foreach (Http::recorded() as [$request]) {
            if (! str_ends_with($request->url(), '/sendMessage')) {
                continue;
            }
            $data = $request->data();
            if (str_contains((string) ($data['text'] ?? ''), 'Binance')) {
                $latest = $data['reply_markup'] ?? null;
            }
        }

        if (is_string($latest)) {
            $latest = json_decode($latest, true);
        }
        $callbacks = collect($latest['inline_keyboard'] ?? [])
            ->flatten(1)
            ->pluck('callback_data')
            ->filter()
            ->values()
            ->all();
        $nonce = null;
        foreach ($callbacks as $callback) {
            if (preg_match('/:(?:binance_pay_id|usdt_bep20):([a-f0-9]{16})$/', $callback, $matches) === 1) {
                $nonce = $matches[1];

                break;
            }
        }

        return ['nonce' => $nonce, 'callbacks' => $callbacks];
    }
}
