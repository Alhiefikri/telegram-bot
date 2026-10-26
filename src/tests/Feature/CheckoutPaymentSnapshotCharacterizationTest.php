<?php

namespace Tests\Feature;

use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Exceptions\InvalidCheckout;
use App\Models\BscPaymentReceiver;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentBscDetail;
use App\Models\Product;
use App\Models\TelegramOutboundEffect;
use App\Services\Bsc\BscRpcClientInterface;
use App\Services\Bsc\BscTransferLogRpcClientInterface;
use App\Services\Checkout\CheckoutPaymentIntent;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\Checkout\PaymentProviderResolver;
use App\Services\OrderCheckoutService;
use App\Services\Telegram\TelegramOutboundEffectService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BscTransferLogRpcStub;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\TestCase;

final class CheckoutPaymentSnapshotCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private const BSC_CONTRACT = '0x55d398326f99059ff775485246999027b3197955';

    #[DataProvider('idrRails')]
    public function test_idr_checkout_rails_keep_the_shared_snapshot_contract(
        string $rail,
        string $provider,
        string $method,
        string $effectType,
    ): void {
        $deadline = CarbonImmutable::parse('2030-01-02 03:04:05', config('app.timezone'));
        $this->configureIdrRail($rail);

        $product = Product::factory()->create([
            'price_amount' => 15_000,
            'stock' => 5,
            'reserved_stock' => 0,
        ]);
        $cart = $this->cartForProduct($product, quantity: 2);
        $minutesBeforeDeadline = match ($rail) {
            'klikqris' => 15,
            'sakurupiah' => 60,
            default => 10,
        };
        $this->travelTo($deadline->copy()->subMinutes($minutesBeforeDeadline));
        $cart->update(['expires_at' => $deadline->copy()->addHour()]);

        try {
            $order = app(OrderCheckoutService::class)->checkout(
                $cart,
                $this->idrIntent($rail),
            );
        } finally {
            $this->travelBack();
        }

        $this->assertCheckoutSnapshot(
            order: $order,
            product: $product,
            quantity: 2,
            provider: $provider,
            method: $method,
            paymentCurrency: 'IDR',
            paymentAmount: 30_000,
            orderTotal: 30_000,
            deadline: $deadline,
            effectType: $effectType,
        );
    }

    #[DataProvider('binanceModes')]
    public function test_binance_pay_id_modes_keep_the_payment_snapshot_contract(
        string $mode,
        string $verificationStatus,
    ): void {
        config()->set('services.binance.pay_id', '987654321');
        config()->set('services.binance.api_key', 'read-only-key');
        config()->set('services.binance.api_secret', 'read-only-secret');
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_pay_enabled' => true,
            'binance_verification_mode' => $mode,
            'is_active' => true,
        ]);

        $deadline = CarbonImmutable::parse('2030-02-03 04:05:06', config('app.timezone'));
        $product = Product::factory()->create([
            'price_amount' => 20_000,
            'usdt_price_amount' => 3_250_000,
            'stock' => 3,
            'reserved_stock' => 0,
        ]);
        $cart = $this->cartForProduct($product);
        $this->travelTo($deadline->copy()->subMinutes(20));
        $cart->update(['expires_at' => $deadline->copy()->addHour()]);

        try {
            $order = app(OrderCheckoutService::class)->checkout(
                $cart,
                CheckoutPaymentIntents::binancePayId(),
            );
        } finally {
            $this->travelBack();
        }

        $payment = $this->assertCheckoutSnapshot(
            order: $order,
            product: $product,
            quantity: 1,
            provider: 'manual',
            method: 'BINANCE_PAY_ID',
            paymentCurrency: 'USDT',
            paymentAmount: 3_250_000,
            orderTotal: 20_000,
            deadline: $deadline,
            effectType: TelegramOutboundEffectService::TYPE_BINANCE_CHECKOUT,
        );

        $this->assertTrue($payment->binance_auto_verification_enabled);
        $this->assertSame($mode, $payment->binance_verification_mode);
        $this->assertSame('pay_id', $payment->binance_receiver_type);
        $this->assertSame('987654321', $payment->binance_receiver_value);
        $this->assertSame($verificationStatus, $payment->binance_verification_status);
    }

    public function test_bsc_checkout_keeps_the_receiver_amount_detail_and_effect_snapshots(): void
    {
        $this->configureBscRail();
        $receiver = $this->activeBscReceiver('0x1111111111111111111111111111111111111111');
        $deadline = CarbonImmutable::parse('2030-03-04 05:06:07', config('app.timezone'));
        $product = Product::factory()->create([
            'price_amount' => 25_000,
            'usdt_price_amount' => 2_500_000,
            'stock' => 2,
            'reserved_stock' => 0,
        ]);
        $this->travelTo($deadline->copy()->subMinutes(30));

        try {
            $order = app(OrderCheckoutService::class)->checkout(
                $this->cartForProduct($product),
                CheckoutPaymentIntents::usdtBep20(),
            );
        } finally {
            $this->travelBack();
        }

        $payment = $this->assertCheckoutSnapshot(
            order: $order,
            product: $product,
            quantity: 1,
            provider: 'manual',
            method: 'USDT_BEP20',
            paymentCurrency: 'USDT',
            paymentAmount: 2_500_000,
            orderTotal: 25_000,
            deadline: $deadline,
            effectType: TelegramOutboundEffectService::TYPE_BSC_CHECKOUT,
        );
        $detail = $payment->bscDetail()->sole();

        $this->assertSame($receiver->id, $detail->receiver_id);
        $this->assertSame($receiver->address_normalized, $detail->receiver_address);
        $this->assertSame(56, $detail->chain_id);
        $this->assertSame(self::BSC_CONTRACT, $detail->token_contract);
        $this->assertSame(18, $detail->token_decimals);
        $this->assertSame(2_500_000, $detail->base_amount_micro);
        $this->assertSame(2_500_000, $detail->payable_amount_micro);
        $this->assertSame('2500000000000000000', (string) $detail->expected_token_units);
        $this->assertSame(PaymentBscDetail::STATUS_AWAITING_TRANSFER, $detail->verification_status);
    }

    #[DataProvider('idrRails')]
    public function test_tier_pricing_is_shared_by_every_qris_checkout_rail(
        string $rail,
        string $provider,
        string $method,
        string $effectType,
    ): void {
        $deadline = CarbonImmutable::parse('2030-04-05 06:07:08', config('app.timezone'));
        $this->configureIdrRail($rail);

        $product = Product::factory()->withQuantityPriceTiers([
            ['minimum_quantity' => 2, 'price_amount' => 12_000, 'usdt_price_amount' => null],
        ])->create([
            'price_amount' => 15_000,
            'stock' => 5,
            'reserved_stock' => 0,
        ]);
        $cart = $this->cartForProduct($product, quantity: 2);
        $minutesBeforeDeadline = match ($rail) {
            'klikqris' => 15,
            'sakurupiah' => 60,
            default => 10,
        };
        $this->travelTo($deadline->copy()->subMinutes($minutesBeforeDeadline));
        $cart->update(['expires_at' => $deadline->copy()->addHour()]);

        try {
            $order = app(OrderCheckoutService::class)->checkout($cart, $this->idrIntent($rail));
        } finally {
            $this->travelBack();
        }

        $this->assertCheckoutSnapshot(
            order: $order,
            product: $product,
            quantity: 2,
            provider: $provider,
            method: $method,
            paymentCurrency: 'IDR',
            paymentAmount: 24_000,
            orderTotal: 24_000,
            deadline: $deadline,
            effectType: $effectType,
        );
        $item = $order->orderItems->sole();
        $this->assertSame(2, $item->quantity_price_tier_minimum);
        $this->assertSame(12_000, $item->unit_price);
        $this->assertSame(24_000, $item->subtotal);
        $this->assertNull($item->usdt_unit_price);
        $this->assertNull($item->usdt_subtotal);
    }

    public function test_binance_pay_uses_the_tiered_usdt_total(): void
    {
        config()->set('services.binance.pay_id', '987654321');
        config()->set('services.binance.api_key', 'read-only-key');
        config()->set('services.binance.api_secret', 'read-only-secret');
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_pay_enabled' => true,
            'binance_verification_mode' => 'amount_unique',
            'is_active' => true,
        ]);

        $deadline = CarbonImmutable::parse('2030-05-06 07:08:09', config('app.timezone'));
        $product = Product::factory()->withQuantityPriceTiers([
            ['minimum_quantity' => 2, 'price_amount' => 18_000, 'usdt_price_amount' => 3_000_000],
        ])->create([
            'price_amount' => 20_000,
            'usdt_price_amount' => 3_250_000,
            'stock' => 3,
            'reserved_stock' => 0,
        ]);
        $cart = $this->cartForProduct($product, quantity: 2);
        $this->travelTo($deadline->copy()->subMinutes(20));
        $cart->update(['expires_at' => $deadline->copy()->addHour()]);

        try {
            $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::binancePayId());
        } finally {
            $this->travelBack();
        }

        $payment = $this->assertCheckoutSnapshot(
            order: $order,
            product: $product,
            quantity: 2,
            provider: 'manual',
            method: 'BINANCE_PAY_ID',
            paymentCurrency: 'USDT',
            paymentAmount: 6_000_000,
            orderTotal: 36_000,
            deadline: $deadline,
            effectType: TelegramOutboundEffectService::TYPE_BINANCE_CHECKOUT,
        );
        $this->assertSame(2, $order->orderItems->sole()->quantity_price_tier_minimum);
        $this->assertSame(18_000, $order->orderItems->sole()->unit_price);
        $this->assertSame(36_000, $order->orderItems->sole()->subtotal);
        $this->assertSame(3_000_000, $order->orderItems->sole()->usdt_unit_price);
        $this->assertSame(6_000_000, $order->orderItems->sole()->usdt_subtotal);
        $this->assertSame(6_000_000, $payment->amount);
    }

    public function test_bsc_uses_the_tiered_usdt_total_and_token_units(): void
    {
        $this->configureBscRail();
        $receiver = $this->activeBscReceiver('0x3333333333333333333333333333333333333333');
        $deadline = CarbonImmutable::parse('2030-06-07 08:09:10', config('app.timezone'));
        $product = Product::factory()->withQuantityPriceTiers([
            ['minimum_quantity' => 2, 'price_amount' => 22_000, 'usdt_price_amount' => 2_300_000],
        ])->create([
            'price_amount' => 25_000,
            'usdt_price_amount' => 2_500_000,
            'stock' => 2,
            'reserved_stock' => 0,
        ]);
        $cart = $this->cartForProduct($product, quantity: 2);
        $this->travelTo($deadline->copy()->subMinutes(30));
        $cart->update(['expires_at' => $deadline->copy()->addHour()]);

        try {
            $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::usdtBep20());
        } finally {
            $this->travelBack();
        }

        $this->assertCheckoutSnapshot(
            order: $order,
            product: $product,
            quantity: 2,
            provider: 'manual',
            method: 'USDT_BEP20',
            paymentCurrency: 'USDT',
            paymentAmount: 4_600_000,
            orderTotal: 44_000,
            deadline: $deadline,
            effectType: TelegramOutboundEffectService::TYPE_BSC_CHECKOUT,
        );
        $detail = $order->payments()->sole()->bscDetail()->sole();
        $this->assertSame($receiver->id, $detail->receiver_id);
        $this->assertSame(4_600_000, $detail->payable_amount_micro);
        $this->assertSame('4600000000000000000', (string) $detail->expected_token_units);
        $this->assertSame(2, $order->orderItems->sole()->quantity_price_tier_minimum);
        $this->assertSame(2_300_000, $order->orderItems->sole()->usdt_unit_price);
        $this->assertSame(4_600_000, $order->orderItems->sole()->usdt_subtotal);
    }

    public function test_mixed_cart_quotes_each_product_independently(): void
    {
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'is_active' => true]);
        $first = Product::factory()->withQuantityPriceTiers([
            ['minimum_quantity' => 10, 'price_amount' => 23_000, 'usdt_price_amount' => null],
        ])->create(['price_amount' => 25_000, 'stock' => 10, 'reserved_stock' => 0]);
        $second = Product::factory()->withQuantityPriceTiers([
            ['minimum_quantity' => 2, 'price_amount' => 9_000, 'usdt_price_amount' => null],
        ])->create(['price_amount' => 10_000, 'stock' => 2, 'reserved_stock' => 0]);
        $customer = Customer::factory()->create(['telegram_id' => 123456]);
        $cart = Cart::factory()->for($customer)->create();
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $first->id, 'quantity' => 10]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $second->id, 'quantity' => 2]);

        $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::manualQris());
        $items = $order->orderItems()->orderBy('product_id')->get();

        $this->assertSame(248_000, $order->total_amount);
        $this->assertSame(248_000, $order->payments()->sole()->amount);
        $this->assertSame([23_000, 9_000], $items->pluck('unit_price')->all());
        $this->assertSame([230_000, 18_000], $items->pluck('subtotal')->all());
        $this->assertSame([10, 2], $items->pluck('quantity_price_tier_minimum')->all());
    }

    public function test_corrupt_tier_schedule_aborts_checkout_before_order_creation(): void
    {
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'is_active' => true]);
        $product = Product::factory()->create([
            'price_amount' => 100_000,
            'stock' => 2,
            'reserved_stock' => 0,
        ]);
        $now = now();
        $rows = [];
        foreach (range(2, 7) as $minimumQuantity) {
            $rows[] = [
                'product_id' => $product->id,
                'minimum_quantity' => $minimumQuantity,
                'price_amount' => 100_000 - ($minimumQuantity * 1_000),
                'usdt_price_amount' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('product_quantity_price_tiers')->insert($rows);

        try {
            app(OrderCheckoutService::class)->checkout(
                $this->cartForProduct($product, quantity: 2),
                CheckoutPaymentIntents::manualQris(),
            );
            $this->fail('Corrupt quantity pricing must abort checkout.');
        } catch (InvalidCheckout $exception) {
            $this->assertSame(
                'Checkout pricing is not available. Please try again later or contact support.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_existing_order_snapshots_ignore_later_product_and_tier_edits(): void
    {
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'is_active' => true]);
        $product = Product::factory()->withQuantityPriceTiers([
            ['minimum_quantity' => 2, 'price_amount' => 12_000, 'usdt_price_amount' => null],
        ])->create([
            'price_amount' => 15_000,
            'icon' => '🧩',
            'custom_emoji_id' => '5368324170671202286',
            'stock' => 2,
            'reserved_stock' => 0,
        ]);
        $order = app(OrderCheckoutService::class)->checkout(
            $this->cartForProduct($product, quantity: 2),
            CheckoutPaymentIntents::manualQris(),
        );

        $product->forceFill([
            'price_amount' => 30_000,
            'icon' => '🧾',
            'custom_emoji_id' => '5368324170671202287',
        ])->save();
        $product->quantityPriceTiers()->delete();
        $product->quantityPriceTiers()->create([
            'minimum_quantity' => 2,
            'price_amount' => 25_000,
            'usdt_price_amount' => null,
        ]);

        $fresh = $order->fresh(['orderItems', 'payments']);
        $this->assertSame(24_000, $fresh->total_amount);
        $this->assertSame(24_000, $fresh->payments->sole()->amount);
        $this->assertSame(12_000, $fresh->orderItems->sole()->unit_price);
        $this->assertSame(24_000, $fresh->orderItems->sole()->subtotal);
        $this->assertSame(2, $fresh->orderItems->sole()->quantity_price_tier_minimum);
        $this->assertSame('🧩', $fresh->orderItems->sole()->product_icon);
        $this->assertSame('5368324170671202286', $fresh->orderItems->sole()->product_custom_emoji_id);
    }

    public static function idrRails(): array
    {
        return [
            'manual QRIS' => [
                'manual',
                'manual',
                'QR_MANUAL',
                TelegramOutboundEffectService::TYPE_MANUAL_CHECKOUT,
            ],
            'KlikQRIS' => [
                'klikqris',
                'klikqris',
                'QRIS',
                TelegramOutboundEffectService::TYPE_KLIKQRIS_CHECKOUT,
            ],
            'Sakurupiah QRIS' => [
                'sakurupiah',
                'sakurupiah',
                'QRIS',
                TelegramOutboundEffectService::TYPE_SAKURUPIAH_CHECKOUT,
            ],
        ];
    }

    public static function binanceModes(): array
    {
        return [
            'amount unique' => ['amount_unique', 'checking'],
            'order id' => ['order_id', 'awaiting_submission'],
        ];
    }

    public function test_bsc_readiness_finishes_before_the_checkout_transaction_begins(): void
    {
        $rpc = $this->configureBscRail();
        $this->activeBscReceiver('0x2222222222222222222222222222222222222222');
        $product = Product::factory()->create([
            'price_amount' => 25_000,
            'usdt_price_amount' => 2_500_000,
            'stock' => 2,
            'reserved_stock' => 0,
        ]);
        $rpc->transactionBaseline = DB::transactionLevel();

        $order = app(OrderCheckoutService::class)->checkout(
            $this->cartForProduct($product),
            CheckoutPaymentIntents::usdtBep20(),
        );

        $this->assertSame('USDT_BEP20', $order->payments()->sole()->method);
        $this->assertSame(0, $rpc->callsUnderCheckoutTransaction);
    }

    public function test_duplicate_checkout_key_reuses_one_order_payment_reservation_and_effect(): void
    {
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'is_active' => true]);
        $product = Product::factory()->create([
            'price_amount' => 18_000,
            'stock' => 2,
            'reserved_stock' => 0,
        ]);
        $cart = $this->cartForProduct($product);
        $service = app(OrderCheckoutService::class);

        $first = $service->checkout($cart, CheckoutPaymentIntents::manualQris(), 'characterization-key');
        $second = $service->checkout($cart->fresh(), CheckoutPaymentIntents::manualQris(), 'characterization-key');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, InventoryMovement::query()->where('type', InventoryMovementType::Reservation)->count());
        $this->assertSame(1, TelegramOutboundEffect::query()->count());
        $this->assertSame(1, $product->fresh()->reserved_stock);
        $this->assertSame(
            TelegramOutboundEffectService::TYPE_MANUAL_CHECKOUT,
            TelegramOutboundEffect::query()->sole()->effect_type,
        );
    }

    public function test_checkout_rechecks_the_provider_setting_before_persisting_the_payment_snapshot(): void
    {
        $setting = ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'payment_provider_version' => 0,
            'is_active' => true,
        ]);
        $selected = app(PaymentProviderResolver::class)->resolve()->paymentIntent;
        KlikQrisSandboxScenarios::configureReadySandbox();
        $setting->forceFill([
            'payment_mode' => 'klikqris',
            'payment_provider_version' => 1,
        ])->save();
        $product = Product::factory()->create([
            'price_amount' => 19_000,
            'stock' => 2,
            'reserved_stock' => 0,
        ]);

        $order = app(OrderCheckoutService::class)->checkout(
            $this->cartForProduct($product),
            $selected,
        );
        $payment = $order->payments()->sole();

        $this->assertSame('klikqris', $payment->provider);
        $this->assertSame('QRIS', $payment->method);
        $this->assertSame('IDR', $payment->currency);
        $this->assertSame(19_000, $payment->amount);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, InventoryMovement::query()->where('type', InventoryMovementType::Reservation)->count());
        $this->assertSame(
            TelegramOutboundEffectService::TYPE_KLIKQRIS_CHECKOUT,
            TelegramOutboundEffect::query()->sole()->effect_type,
        );
    }

    private function configureIdrRail(string $rail): void
    {
        match ($rail) {
            'manual' => ManualPaymentSetting::create([
                'payment_mode' => 'manual',
                'is_active' => true,
            ]),
            'klikqris' => $this->configureKlikQris(),
            'sakurupiah' => $this->configureSakurupiah(),
            default => throw new \InvalidArgumentException('Unknown characterization rail: '.$rail),
        };
    }

    private function configureSakurupiah(): void
    {
        config()->set('services.sakurupiah', array_replace(config('services.sakurupiah', []), [
            'sandbox_enabled' => true,
            'sandbox_base_url' => 'https://sakurupiah.id/api-sanbox/',
            'sandbox_api_id' => 'sandbox-id',
            'sandbox_api_key' => 'sandbox-key',
            'qris_contract' => [
                'currency' => 'IDR',
                'currency_confirmed' => true,
                'fee_payer' => 'merchant',
                'merchant_fee' => 1,
                'fee_confirmed' => true,
                'expiry_hours' => 1,
                'expiry_confirmed' => true,
            ],
        ]));
        Http::fake([
            'https://sakurupiah.id/api-sanbox/list-payment.php' => Http::response([
                'status' => '200',
                'data' => [[
                    'kode' => 'QRISC',
                    'nama' => 'QRISC Custom Merchant',
                    'minimal' => 200,
                    'maksimal' => 20_000_000,
                    'tipe' => 'DIRECT',
                    'status' => 'Aktif',
                    'addition' => ['default_expired' => 1],
                ]],
            ]),
        ]);
        ManualPaymentSetting::create([
            'payment_mode' => 'sakurupiah',
            'sakurupiah_channel' => 'QRISC',
            'is_active' => true,
        ]);
    }

    private function configureKlikQris(): void
    {
        KlikQrisSandboxScenarios::configureReadySandbox();
        ManualPaymentSetting::create([
            'payment_mode' => 'klikqris',
            'is_active' => true,
        ]);
    }

    private function idrIntent(string $rail): CheckoutPaymentIntent
    {
        return match ($rail) {
            'manual' => CheckoutPaymentIntents::manualQris(),
            'klikqris' => CheckoutPaymentIntents::klikQris(),
            'sakurupiah' => CheckoutPaymentIntents::sakurupiah(),
            default => throw new \InvalidArgumentException('Unknown characterization rail: '.$rail),
        };
    }

    private function configureBscRail(): object
    {
        config()->set('services.bsc.rpc_urls', ['https://bsc-rpc.test']);
        config()->set('services.bsc.chain_id', 56);
        config()->set('services.bsc.usdt_contract', self::BSC_CONTRACT);
        config()->set('services.bsc.expected_token_decimals', 18);

        $rpc = new class implements BscRpcClientInterface, BscTransferLogRpcClientInterface
        {
            use BscTransferLogRpcStub;

            public int $transactionBaseline = 0;

            public int $callsUnderCheckoutTransaction = 0;

            public function chainId(): int
            {
                $this->recordCall();

                return 56;
            }

            public function contractCode(string $contract): string
            {
                $this->recordCall();

                return '0x60016001';
            }

            public function tokenDecimals(string $contract): int
            {
                $this->recordCall();

                return 18;
            }

            public function isValidErc1271Signature(string $contract, string $messageHashHex, string $signature): bool
            {
                return true;
            }

            private function recordCall(): void
            {
                if (DB::transactionLevel() > $this->transactionBaseline) {
                    $this->callsUnderCheckoutTransaction++;
                }
            }
        };
        app()->instance(BscRpcClientInterface::class, $rpc);
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'bsc_payment_enabled' => true,
            'is_active' => true,
        ]);

        return $rpc;
    }

    private function activeBscReceiver(string $address): BscPaymentReceiver
    {
        return BscPaymentReceiver::factory()->verified()->active()->create([
            'address' => $address,
            'token_contract' => self::BSC_CONTRACT,
        ]);
    }

    private function cartForProduct(Product $product, int $quantity = 1): Cart
    {
        $customer = Customer::factory()->create(['telegram_id' => 123456]);
        $cart = Cart::factory()->for($customer)->create();
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
        ]);

        return $cart;
    }

    private function assertCheckoutSnapshot(
        Order $order,
        Product $product,
        int $quantity,
        string $provider,
        string $method,
        string $paymentCurrency,
        int $paymentAmount,
        int $orderTotal,
        CarbonInterface $deadline,
        string $effectType,
    ): Payment {
        $payment = $order->payments()->sole();
        $movement = InventoryMovement::query()->where('order_id', $order->id)->sole();
        $effect = TelegramOutboundEffect::query()->where('source_id', $order->id)->sole();

        $this->assertSame(OrderStatus::PendingPayment, $order->order_status);
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
        $this->assertSame($orderTotal, $order->total_amount);
        $this->assertSame('IDR', $order->currency);
        $this->assertSame($provider, $payment->provider);
        $this->assertSame($method, $payment->method);
        $this->assertSame($paymentCurrency, $payment->currency);
        $this->assertSame($paymentAmount, $payment->amount);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame($deadline->timestamp, $order->expires_at->timestamp);
        $this->assertSame($deadline->timestamp, $payment->expired_at->timestamp);
        $this->assertSame(InventoryMovementType::Reservation, $movement->type);
        $this->assertSame($quantity, $movement->quantity);
        $this->assertSame($quantity, $product->fresh()->reserved_stock);
        $this->assertSame($effectType, $effect->effect_type);
        $this->assertSame(TelegramOutboundEffectState::Pending, $effect->state);
        $this->assertSame($order->id, $effect->source_id);
        $this->assertSame('123456', $effect->telegram_chat_id);
        $this->assertSame(
            TelegramOutboundEffectService::checkoutEffectKey($order->id, $effectType),
            $effect->effect_key,
        );
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, InventoryMovement::query()->count());
        $this->assertSame(1, TelegramOutboundEffect::query()->count());

        return $payment;
    }
}
