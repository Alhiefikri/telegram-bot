<?php

namespace Tests\Feature;

use App\Models\BscPaymentReceiver;
use App\Models\InventoryMovement;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentBscDetail;
use App\Models\Product;
use App\Services\Bsc\BscRpcClientInterface;
use App\Services\Checkout\PaymentProviderResolver;
use App\Support\PaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BinancePaymentRailSelectionTest extends TestCase
{
    use RefreshDatabase;

    private const CONTRACT = '0x55d398326f99059ff775485246999027b3197955';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.bsc.rpc_url', 'https://bsc-rpc.test');
        config()->set('services.bsc.chain_id', 56);
        config()->set('services.bsc.usdt_contract', self::CONTRACT);
        config()->set('services.bsc.expected_token_decimals', 18);
        config()->set('services.storefront.telegram_reservation_floor', 0);

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
            'is_active' => true,
            'binance_pay_enabled' => true,
            'bsc_payment_enabled' => true,
        ]);
    }

    public function test_explicit_binance_rails_bypass_the_provider_switch_and_legacy_alias_keeps_pay_id(): void
    {
        $setting = ManualPaymentSetting::current();

        $payId = app(PaymentProviderResolver::class)->resolve('binance_pay_id', $setting);
        $legacy = app(PaymentProviderResolver::class)->resolve('binance_pay', $setting);
        $bsc = app(PaymentProviderResolver::class)->resolve('usdt_bep20', $setting);

        $this->assertSame(PaymentProvider::MANUAL, $payId->provider);
        $this->assertSame('BINANCE_PAY_ID', $payId->paymentIntent->method());
        $this->assertSame('BINANCE_PAY_ID', $legacy->paymentIntent->method());
        $this->assertSame(PaymentProvider::MANUAL, $bsc->provider);
        $this->assertSame('USDT_BEP20', $bsc->paymentIntent->method());
        $this->assertSame(PaymentProvider::PAKASIR, $bsc->requestedProvider);
    }

    public function test_storefront_can_create_explicit_bsc_checkout_with_one_snapshot(): void
    {
        Queue::fake();
        config()->set('services.binance.pay_id', '987654321');
        $this->activeReceiver('0x1111111111111111111111111111111111111111');
        $product = Product::factory()->create([
            'stock' => 2,
            'reserved_stock' => 0,
            'usdt_price_amount' => 2_500_000,
        ]);

        $response = $this->post(route('storefront.shop.checkout'), [
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'bsc_buyer',
            'payment_method' => 'usdt_bep20',
        ]);

        $response->assertRedirect();
        $order = Order::with('payments')->sole();
        $payment = $order->payments->sole();

        $this->assertSame(PaymentProvider::MANUAL, $payment->provider);
        $this->assertSame('USDT_BEP20', $payment->method);
        $this->assertSame(1, PaymentBscDetail::query()->where('payment_id', $payment->id)->count());
        $this->assertSame(1, $payment->bscDetail()->count());
    }

    public function test_disabled_bsc_rail_has_no_checkout_side_effects(): void
    {
        ManualPaymentSetting::current()->update(['bsc_payment_enabled' => false]);
        $this->activeReceiver('0x2222222222222222222222222222222222222222');
        $product = Product::factory()->create([
            'stock' => 1,
            'reserved_stock' => 0,
            'usdt_price_amount' => 2_500_000,
        ]);

        $this->post(route('storefront.shop.checkout'), [
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'bsc_disabled',
            'payment_method' => 'usdt_bep20',
        ])->assertRedirect()
            ->assertSessionHas('storefront_checkout_error', fn (string $message): bool => str_contains($message, 'BSC payment rail is not ready.'));

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(0, $product->fresh()->reserved_stock);
    }

    public function test_storefront_checkout_identity_includes_the_final_rail(): void
    {
        Queue::fake();
        config()->set('services.binance.pay_id', '987654321');
        $this->activeReceiver('0x3333333333333333333333333333333333333333');
        $product = Product::factory()->create([
            'stock' => 2,
            'reserved_stock' => 0,
            'usdt_price_amount' => 2_500_000,
        ]);
        $session = ['storefront.checkout_client_key' => 'same-rail-selector-session'];

        $this->withSession($session)->post(route('storefront.shop.checkout'), [
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'rail_buyer',
            'payment_method' => 'binance_pay_id',
        ])->assertRedirect();

        $this->withSession($session)->post(route('storefront.shop.checkout'), [
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'rail_buyer',
            'payment_method' => 'usdt_bep20',
        ])->assertRedirect();

        $this->assertSame(2, Order::count());
        $this->assertSame(2, Payment::count());
        $this->assertSame(1, Payment::query()->where('method', 'BINANCE_PAY_ID')->count());
        $this->assertSame(1, Payment::query()->where('method', 'USDT_BEP20')->count());
    }

    private function activeReceiver(string $address): BscPaymentReceiver
    {
        return BscPaymentReceiver::factory()->verified()->active()->create([
            'address' => $address,
            'token_contract' => self::CONTRACT,
        ]);
    }
}
