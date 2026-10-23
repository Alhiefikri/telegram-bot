<?php

namespace Tests\Feature\Telegram;

use App\Enums\TelegramOutboundEffectState;
use App\Jobs\ForwardManualPaymentProof;
use App\Jobs\SendBinanceCheckoutInstructions;
use App\Jobs\SendBscCheckoutInstructions;
use App\Jobs\SendTelegramReceipt;
use App\Jobs\VerifyBscPayment;
use App\Models\BscPaymentReceiver;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentBscDetail;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\TelegramOutboundEffect;
use App\Services\Bsc\BscPaymentVerification;
use App\Services\Bsc\BscRpcClientInterface;
use App\Services\Bsc\BscTransferLogRpcClientInterface;
use App\Services\Bsc\BscVerificationRpcClientInterface;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\Telegram\ReceiptDeliveryPipeline;
use App\Services\Telegram\TelegramCheckoutIntents;
use App\Services\Telegram\TelegramIntentSession;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Support\PaymentProvider;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BscTransferLogRpcStub;
use Tests\Support\TelegramBotTestSupport;

final class BscCheckoutRailTest extends TelegramBotTestSupport
{
    private const CONTRACT = '0x55d398326f99059ff775485246999027b3197955';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.binance.pay_id', '987654321');
        config()->set('services.bsc.rpc_urls', ['https://bsc-rpc.test']);
        config()->set('services.bsc.chain_id', 56);
        config()->set('services.bsc.usdt_contract', self::CONTRACT);
        config()->set('services.bsc.expected_token_decimals', 18);

        app()->instance(BscRpcClientInterface::class, new class implements BscRpcClientInterface, BscTransferLogRpcClientInterface
        {
            use BscTransferLogRpcStub;

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
        $instructionRequest = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage'))->last()[0];
        $instructionPayload = $instructionRequest->data();
        $entities = $instructionPayload['entities'] ?? [];
        $this->assertSame(['code', 'code'], array_column($entities, 'type'));
        $this->assertSame(
            ['2.5', '0x1111111111111111111111111111111111111111'],
            array_map(
                fn (array $entity): string => $this->telegramEntityText((string) $instructionPayload['text'], $entity),
                $entities,
            ),
        );

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'USDT BEP20 Payment')
            && str_contains((string) ($request['text'] ?? ''), '0x1111111111111111111111111111111111111111')
            && str_contains((string) ($request['text'] ?? ''), 'BNB Smart Chain (BEP20) ONLY')
            && str_contains((string) ($request['text'] ?? ''), 'system detects the transfer automatically')
            && ! str_contains((string) json_encode($request->data()), 'Submit TX Hash')
            && str_contains((string) json_encode($request->data()), 'Check Payment Status'));
    }

    public function test_valid_bsc_transfer_claims_payment_and_delivers_product_receipt(): void
    {
        Queue::fake();
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 930001],
            ]),
        ]);

        $rpc = new class implements BscTransferLogRpcClientInterface, BscVerificationRpcClientInterface
        {
            /** @var array<string, array<string, mixed>|null> */
            public array $receipts = [];

            /** @var array<string, array<string, mixed>|null> */
            /** @var list<array<string, mixed>> */
            public array $logs = [];

            public array $blocks = [];

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

            public function transactionReceipt(string $transactionHash): ?array
            {
                return $this->receipts[$transactionHash] ?? null;
            }

            public function blockByNumber(string|int $blockNumber): ?array
            {
                return $this->blocks[(string) $blockNumber] ?? null;
            }

            public function latestBlockNumber(): int
            {
                return 102;
            }

            /** @return list<array<string, mixed>> */
            public function transferLogs(
                string $tokenContract,
                string $receiverAddress,
                int $fromBlock,
                int $toBlock,
            ): array {
                return $this->logs;
            }
        };
        app()->instance(BscVerificationRpcClientInterface::class, $rpc);

        $customer = Customer::factory()->create(['telegram_id' => 44505, 'locale' => 'en']);
        $product = Product::factory()->create([
            'name' => 'BSC Delivery Product',
            'stock' => 1,
            'reserved_stock' => 0,
            'usdt_price_amount' => 2_500_000,
        ]);
        ProductStockItem::create([
            'product_id' => $product->id,
            'secret' => 'bsc-buyer@example.test|Bsc-password-123',
        ]);

        $cartService = app(CartService::class);
        $cart = $cartService->activeCartFor($customer);
        $cartService->addItem($cart, $product, 1);
        $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::usdtBep20());
        $payment = $order->payments()->with('bscDetail')->sole();
        $detail = $payment->bscDetail;
        $this->assertInstanceOf(PaymentBscDetail::class, $detail);

        // Keep the synthetic chain timestamp safely inside the payment window.
        $payment->forceFill(['created_at' => now()->subMinutes(2)])->saveQuietly();
        $payment->refresh();

        $hash = '0x'.str_repeat('f', 64);
        $blockHash = '0x'.str_repeat('e', 64);
        $expectedUnits = (string) $detail->expected_token_units;
        $rpc->receipts[$hash] = [
            'transactionHash' => $hash,
            'status' => '0x1',
            'blockNumber' => '0x64',
            'blockHash' => $blockHash,
            'logs' => [[
                'address' => self::CONTRACT,
                'topics' => [
                    '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
                    $this->topicAddress('0x2222222222222222222222222222222222222222'),
                    $this->topicAddress((string) $detail->receiver_address),
                ],
                'data' => '0x'.str_pad(BigInteger::of($expectedUnits)->toBase(16), 64, '0', STR_PAD_LEFT),
                'logIndex' => '0x0',
                'blockHash' => $blockHash,
            ]],
        ];
        $log = $rpc->receipts[$hash]['logs'][0];
        $log['transactionHash'] = $hash;
        $log['blockNumber'] = '0x64';
        $rpc->logs = [$log];
        $blockTimestamp = $payment->created_at->copy()->addSeconds(20);
        $rpc->blocks['100'] = [
            'number' => '0x64',
            'timestamp' => '0x'.dechex($blockTimestamp->timestamp),
            'hash' => $blockHash,
        ];
        $rpc->blocks['latest'] = [
            'number' => '0x66',
            'timestamp' => '0x'.dechex($blockTimestamp->copy()->addSeconds(20)->timestamp),
            'hash' => '0x'.str_repeat('d', 64),
        ];

        $verificationJob = null;
        $this->assertTrue(app(BscPaymentVerification::class)->queueForStatusRefresh($payment->fresh()));
        Queue::assertPushed(VerifyBscPayment::class, function (VerifyBscPayment $job) use (&$verificationJob, $payment): bool {
            $verificationJob = $job;

            return $job->paymentId === $payment->id && $job->submissionVersion === 0;
        });
        $this->assertNotNull($verificationJob);
        $verificationJob->handle(app(BscPaymentVerification::class));

        $this->assertSame('paid', $payment->fresh()->status->value);
        $this->assertSame('processing', $payment->fresh()->order->order_status->value);

        $receiptJob = null;
        Queue::assertPushed(SendTelegramReceipt::class, function (SendTelegramReceipt $job) use (&$receiptJob, $order): bool {
            $receiptJob = $job;

            return $job->orderId === (string) $order->id;
        });
        Queue::assertPushed(SendTelegramReceipt::class, 1);
        $this->assertNotNull($receiptJob);
        $receiptJob->handle(
            app(TelegramOutboundEffectService::class),
            app(ReceiptDeliveryPipeline::class),
        );

        $receiptEffect = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS)
            ->sole();

        $this->assertSame('sent', $receiptEffect->fresh()->state->value);
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
        $receiptRequest = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto'))->sole()[0];
        $caption = (string) $this->telegramRequestField($receiptRequest, 'caption');
        $this->assertStringContainsString('bsc-buyer@example.test', $caption);
        $this->assertStringContainsString('Bsc-password-123', $caption);
    }

    public function test_recovery_redispatches_a_pending_bsc_checkout_effect_by_effect_id(): void
    {
        Queue::fake();
        $customer = Customer::factory()->create(['telegram_id' => 44504, 'locale' => 'en']);
        $product = Product::factory()->create([
            'stock' => 1,
            'reserved_stock' => 0,
            'usdt_price_amount' => 2_500_000,
        ]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->addItem($cart, $product, 1);

        $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::usdtBep20());
        $effect = TelegramOutboundEffect::query()->sole();

        $this->artisan('telegram:recover-outbound')
            ->assertSuccessful();

        Queue::assertPushed(
            SendBscCheckoutInstructions::class,
            fn (SendBscCheckoutInstructions $job): bool => $job->orderId === $order->id
                && $job->effectId === $effect->id,
        );
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

    public function test_bsc_amount_unique_checkout_does_not_prompt_for_or_accept_a_hash(): void
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
        $this->postTelegramWebhook($this->messageUpdate(9021, '0x'.str_repeat('d', 64), ['id' => 44503], 44503))->assertOk();

        $this->assertSame(0, (int) $payment->bscDetail->fresh()->tx_attempts);
        Queue::assertNotPushed(VerifyBscPayment::class);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'no transaction hash is needed'));
    }

    public function test_payment_screenshot_triggers_chain_verification_not_manual_approval(): void
    {
        Queue::fake([
            VerifyBscPayment::class,
            SendBscCheckoutInstructions::class,
            ForwardManualPaymentProof::class,
        ]);
        $this->fakeTelegramHttp();
        ManualPaymentSetting::current()->update(['admin_telegram_chat_id' => '999']);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'en']);
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0, 'usdt_price_amount' => 3_600_100]);
        $cartService = app(CartService::class);
        $cart = $cartService->activeCartFor($customer);
        $cartService->addItem($cart, $product, 1);
        $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::usdtBep20());
        $payment = $order->payments()->sole();

        $this->postTelegramWebhook($this->paymentPhotoUpdate(9030, $order->invoice_number))->assertOk();

        $this->assertNull($payment->fresh()->payment_proof_submitted_at);
        $this->assertFalse(TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_MANUAL_PAYMENT_PROOF)->exists());
        Queue::assertPushed(VerifyBscPayment::class, fn (VerifyBscPayment $job): bool => $job->paymentId === $payment->id);
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
            $markup = $data['reply_markup'] ?? null;
            if (str_contains((string) json_encode($markup), 'checkout:binance:rail:')) {
                $latest = $markup;
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

    private function topicAddress(string $address): string
    {
        return '0x'.str_pad(substr(strtolower($address), 2), 64, '0', STR_PAD_LEFT);
    }
}
