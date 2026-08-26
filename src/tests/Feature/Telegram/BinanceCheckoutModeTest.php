<?php

namespace Tests\Feature\Telegram;

use App\Enums\CartStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Product;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Support\PakasirCheckoutFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BinanceCheckoutModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_configured_binance_credentials_never_change_a_pakasir_qris_payment(): void
    {
        config()->set('services.binance.api_key', 'key');
        config()->set('services.binance.api_secret', 'secret');
        config()->set('services.pakasir.slug', 'lhie-test');
        config()->set('services.pakasir.api_key', 'pakasir-key');
        config()->set('services.pakasir.base_url', 'https://app.pakasir.test');
        ManualPaymentSetting::create([
            'payment_mode' => 'pakasir',
            'is_active' => true,
        ]);

        $product = Product::factory()->create([
            'stock' => 1,
            'reserved_stock' => 0,
            'price_amount' => 37_000,
        ]);
        $cart = Cart::factory()->create(['status' => CartStatus::Active, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id]);

        $order = $this->legacyPakasirOrder($cart, PakasirCheckoutFlow::API_QRIS);

        $this->assertSame(37_000, $order->total_amount);
        $this->assertSame(37_000, $order->payments()->sole()->amount);
    }

    public function test_non_admin_order_id_checkout_does_not_require_readiness_marker(): void
    {
        config()->set('services.binance.pay_id', '987654321');
        config()->set('services.binance.api_key', 'key');
        config()->set('services.binance.api_secret', 'secret');
        ManualPaymentSetting::create([
            'binance_pay_enabled' => true,
            'is_active' => true,
            'binance_verification_mode' => 'order_id',
            'binance_order_id_compatibility_ready_at' => null,
        ]);

        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 0, 'usdt_price_amount' => 3_250_000]);
        $buyer = Customer::factory()->create(['telegram_id' => 778]);
        $cart = Cart::factory()->for($buyer)->create(['status' => CartStatus::Active, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id]);

        $payment = app(OrderCheckoutService::class)
            ->checkout($cart, CheckoutPaymentIntents::binancePay())
            ->payments()
            ->sole();

        $this->assertSame('order_id', $payment->binance_verification_mode);
        $this->assertSame(3_250_000, $payment->amount);
        $this->assertSame('awaiting_submission', $payment->binance_verification_status);
    }

    public function test_legacy_checkout_persists_sequential_unique_amounts(): void
    {
        config()->set('services.binance.pay_id', '987654321');
        config()->set('services.binance.api_key', 'key');
        config()->set('services.binance.api_secret', 'secret');
        config()->set('services.binance.verification_mode', 'amount_unique');
        ManualPaymentSetting::create([
            'binance_pay_enabled' => true,
            'is_active' => true,
            'binance_verification_mode' => 'amount_unique',
        ]);

        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 0, 'usdt_price_amount' => 1_000_000]);
        $firstCustomer = Customer::factory()->create();
        $firstCart = Cart::factory()->for($firstCustomer)->create(['status' => CartStatus::Active, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $firstCart->id, 'product_id' => $product->id]);

        $firstOrder = app(OrderCheckoutService::class)->checkout($firstCart, CheckoutPaymentIntents::binancePay());
        $firstPayment = $firstOrder->payments()->sole();

        $this->assertSame('BINANCE_PAY_ID', $firstPayment->method);
        $this->assertSame('amount_unique', $firstPayment->binance_verification_mode);
        $this->assertSame(1_000_000, $firstPayment->amount);

        $secondCustomer = Customer::factory()->create();
        $secondCart = Cart::factory()->for($secondCustomer)->create(['status' => CartStatus::Active, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $secondCart->id, 'product_id' => $product->id]);

        $secondOrder = app(OrderCheckoutService::class)->checkout($secondCart, CheckoutPaymentIntents::binancePay());

        $this->assertSame(1_001_000, $secondOrder->payments()->sole()->amount);
    }

    public function test_checkout_accepts_a_configured_binance_uid_when_pay_id_is_not_available(): void
    {
        config()->set('services.binance.pay_id', null);
        config()->set('services.binance.pay_uid', '123456789');
        config()->set('services.binance.verification_mode', 'order_id');
        ManualPaymentSetting::create([
            'binance_pay_enabled' => true,
            'is_active' => true,
            'binance_verification_mode' => 'order_id',
            'binance_order_id_compatibility_ready_at' => now(),
        ]);

        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0, 'usdt_price_amount' => 3_250_000]);
        $cart = Cart::factory()->create(['status' => CartStatus::Active, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id]);

        $payment = app(OrderCheckoutService::class)
            ->checkout($cart, CheckoutPaymentIntents::binancePay())
            ->payments()
            ->sole();

        $this->assertSame('order_id', $payment->binance_verification_mode);
    }

    public function test_binance_verification_mode_has_no_environment_configuration_contract(): void
    {
        $this->assertNull(config('services.binance.verification_mode'));
    }
}
