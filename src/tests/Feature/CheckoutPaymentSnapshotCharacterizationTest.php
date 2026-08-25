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
use App\Services\Checkout\CheckoutPaymentIntent;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\Checkout\PaymentProviderResolver;
use App\Services\OrderCheckoutService;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Support\PakasirCheckoutFlow;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
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

        $order = app(OrderCheckoutService::class)->checkout(
            $cart,
            $this->idrIntent($rail, $deadline),
        );

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

    public static function idrRails(): array
    {
        return [
            'manual QRIS' => [
                'manual',
                'manual',
                'QR_MANUAL',
                TelegramOutboundEffectService::TYPE_MANUAL_CHECKOUT,
            ],
            'Pakasir hosted' => [
                'pakasir_hosted',
                'pakasir',
                PakasirCheckoutFlow::HOSTED,
                TelegramOutboundEffectService::TYPE_PAKASIR_CHECKOUT,
            ],
            'Pakasir native QRIS' => [
                'pakasir_native_qris',
                'pakasir',
                PakasirCheckoutFlow::API_QRIS,
                TelegramOutboundEffectService::TYPE_PAKASIR_CHECKOUT,
            ],
            'KlikQRIS' => [
                'klikqris',
                'klikqris',
                'QRIS',
                TelegramOutboundEffectService::TYPE_KLIKQRIS_CHECKOUT,
            ],
        ];
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

        $order = app(OrderCheckoutService::class)->checkout(
            $cart,
            CheckoutPaymentIntents::binancePayId($deadline),
        );

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

    public static function binanceModes(): array
    {
        return [
            'amount unique' => ['amount_unique', 'checking'],
            'order id' => ['order_id', 'awaiting_submission'],
        ];
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

        $order = app(OrderCheckoutService::class)->checkout(
            $this->cartForProduct($product),
            CheckoutPaymentIntents::usdtBep20($deadline),
        );

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

    public function test_bsc_allocation_failure_rolls_back_receiver_payment_and_effect_state(): void
    {
        $this->configureBscRail();
        config()->set('services.bsc.max_unique_amount_candidates', 1);
        $receiver = $this->activeBscReceiver('0x3333333333333333333333333333333333333333');
        Payment::factory()->create([
            'provider' => 'manual',
            'method' => 'USDT_BEP20',
            'amount' => 2_500_000,
            'currency' => 'USDT',
            'status' => PaymentStatus::Pending,
        ]);
        $product = Product::factory()->create([
            'price_amount' => 25_000,
            'usdt_price_amount' => 2_500_000,
            'stock' => 2,
            'reserved_stock' => 0,
        ]);
        $cart = $this->cartForProduct($product);
        $ordersBefore = Order::query()->count();
        $paymentsBefore = Payment::query()->count();

        try {
            app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::usdtBep20());
            $this->fail('Expected the exhausted BSC amount window to reject checkout.');
        } catch (InvalidCheckout $exception) {
            $this->assertStringContainsString('No unique BSC payment amount', $exception->getMessage());
        }

        $this->assertSame($ordersBefore, Order::query()->count());
        $this->assertSame($paymentsBefore, Payment::query()->count());
        $this->assertSame(0, InventoryMovement::query()->count());
        $this->assertSame(0, TelegramOutboundEffect::query()->count());
        $this->assertNull($receiver->fresh()->last_assigned_at);
        $this->assertSame(0, Order::query()->where('cart_id', $cart->id)->count());
        $this->assertSame(0, $product->fresh()->reserved_stock);
    }

    public function test_duplicate_checkout_key_reuses_one_order_payment_reservation_and_effect(): void
    {
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'is_active' => true]);
        $deadline = CarbonImmutable::parse('2030-04-05 06:07:08', config('app.timezone'));
        $product = Product::factory()->create([
            'price_amount' => 18_000,
            'stock' => 2,
            'reserved_stock' => 0,
        ]);
        $cart = $this->cartForProduct($product);
        $service = app(OrderCheckoutService::class);

        $first = $service->checkout($cart, CheckoutPaymentIntents::manualQris($deadline), 'characterization-key');
        $second = $service->checkout($cart->fresh(), CheckoutPaymentIntents::manualQris($deadline), 'characterization-key');

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
            'pakasir_hosted', 'pakasir_native_qris' => $this->configurePakasir($rail),
            'klikqris' => $this->configureKlikQris(),
            default => throw new \InvalidArgumentException('Unknown characterization rail: '.$rail),
        };
    }

    private function configurePakasir(string $rail): void
    {
        config()->set('services.pakasir.slug', 'characterization-store');
        config()->set('services.pakasir.api_key', 'characterization-key');
        config()->set('services.pakasir.base_url', 'https://app.pakasir.test');
        ManualPaymentSetting::create([
            'payment_mode' => 'pakasir',
            'pakasir_checkout_flow' => $rail === 'pakasir_native_qris'
                ? PakasirCheckoutFlow::API_QRIS
                : PakasirCheckoutFlow::HOSTED,
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

    private function idrIntent(string $rail, CarbonInterface $deadline): CheckoutPaymentIntent
    {
        return match ($rail) {
            'manual' => CheckoutPaymentIntents::manualQris($deadline),
            'pakasir_hosted' => CheckoutPaymentIntents::pakasir(PakasirCheckoutFlow::HOSTED, $deadline),
            'pakasir_native_qris' => CheckoutPaymentIntents::pakasir(PakasirCheckoutFlow::API_QRIS, $deadline),
            'klikqris' => CheckoutPaymentIntents::klikQris($deadline),
            default => throw new \InvalidArgumentException('Unknown characterization rail: '.$rail),
        };
    }

    private function configureBscRail(): object
    {
        config()->set('services.bsc.rpc_url', 'https://bsc-rpc.test');
        config()->set('services.bsc.chain_id', 56);
        config()->set('services.bsc.usdt_contract', self::BSC_CONTRACT);
        config()->set('services.bsc.expected_token_decimals', 18);
        config()->set('services.bsc.max_unique_amount_candidates', 1_000);

        $rpc = new class implements BscRpcClientInterface
        {
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
            'bsc_unique_amount_increment_micro' => 100,
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
