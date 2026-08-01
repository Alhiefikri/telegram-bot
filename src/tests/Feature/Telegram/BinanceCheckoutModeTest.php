<?php

namespace Tests\Feature\Telegram;

use App\Enums\CartStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ManualPaymentSetting;
use App\Models\Product;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BinanceCheckoutModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_persists_order_id_mode_and_exact_usdt_amount_when_activated(): void
    {
        config()->set('services.binance.pay_id', '987654321');
        config()->set('services.binance.api_key', 'key');
        config()->set('services.binance.api_secret', 'secret');
        config()->set('services.binance.verification_mode', 'order_id');
        ManualPaymentSetting::create([
            'binance_pay_enabled' => true,
            'is_active' => true,
            'binance_verification_mode' => 'order_id',
            'binance_order_id_compatibility_ready_at' => now(),
        ]);

        $product = Product::factory()->create([
            'stock' => 2,
            'reserved_stock' => 0,
            'price_amount' => 50_000,
            'usdt_price_amount' => 3_250_000,
        ]);
        $cart = Cart::factory()->create(['status' => CartStatus::Active, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1]);

        $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::binancePay());
        $payment = $order->payments()->sole();

        $this->assertSame('order_id', $payment->binance_verification_mode);
        $this->assertSame(3_250_000, $payment->amount);
        $this->assertSame(0, $payment->binance_order_id_submission_version);
        $this->assertSame('awaiting_submission', $payment->binance_verification_status);
    }

    public function test_checkout_keeps_release_a_amount_unique_mode_when_configured(): void
    {
        config()->set('services.binance.pay_id', '987654321');
        config()->set('services.binance.verification_mode', 'amount_unique');
        ManualPaymentSetting::create([
            'binance_pay_enabled' => true,
            'is_active' => true,
            'binance_verification_mode' => 'amount_unique',
        ]);

        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 0, 'usdt_price_amount' => 3_250_000]);
        $cart = Cart::factory()->create(['status' => CartStatus::Active, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id]);

        $payment = app(OrderCheckoutService::class)
            ->checkout($cart, CheckoutPaymentIntents::binancePay())
            ->payments()
            ->sole();

        $this->assertSame('amount_unique', $payment->binance_verification_mode);
        $this->assertSame(3_250_000, $payment->amount);
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
}
