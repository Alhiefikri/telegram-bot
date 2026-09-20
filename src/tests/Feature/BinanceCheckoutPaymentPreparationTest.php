<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Cart;
use App\Models\ManualPaymentSetting;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Checkout\BinanceCheckoutPaymentPreparation;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\Checkout\CheckoutPaymentPlan;
use App\Services\Checkout\CheckoutPaymentPreflightContext;
use App\Services\Checkout\CheckoutPaymentPreparationContext;
use App\Services\Checkout\CheckoutPaymentPreparationResolver;
use App\Services\Checkout\CheckoutPricingSnapshot;
use App\Services\Checkout\PaymentPlan\BinancePayCheckoutPaymentSnapshot;
use App\Services\Pricing\ProductQuantityPricing;
use App\Support\PaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

final class BinanceCheckoutPaymentPreparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolver_registers_the_binance_pay_id_preparation_adapter(): void
    {
        $intent = CheckoutPaymentIntents::binancePayId();
        $resolver = app(CheckoutPaymentPreparationResolver::class);

        $this->assertInstanceOf(BinanceCheckoutPaymentPreparation::class, $resolver->for($intent));
    }

    public function test_amount_unique_plan_allocates_a_sequential_amount_and_snapshots_the_receiver(): void
    {
        config()->set('services.binance.pay_id', '987654321');
        config()->set('services.binance.pay_uid', null);
        config()->set('services.binance.api_key', 'read-only-key');
        config()->set('services.binance.api_secret', 'read-only-secret');

        $setting = ManualPaymentSetting::create([
            'payment_mode' => PaymentProvider::MANUAL,
            'is_active' => true,
            'binance_pay_enabled' => true,
            'binance_verification_mode' => 'amount_unique',
        ]);
        $product = Product::factory()->create(['usdt_price_amount' => 3_250_000]);
        Payment::factory()->create([
            'provider' => PaymentProvider::MANUAL,
            'method' => BinanceCheckoutPaymentPreparation::METHOD,
            'amount' => 3_250_000,
            'currency' => BinanceCheckoutPaymentPreparation::PAYMENT_CURRENCY,
            'status' => PaymentStatus::Pending,
            'binance_auto_verification_enabled' => true,
        ]);

        $plan = $this->prepare($setting, $product);
        $snapshot = $plan->rail();

        $this->assertInstanceOf(CheckoutPaymentPlan::class, $plan);
        $this->assertInstanceOf(BinancePayCheckoutPaymentSnapshot::class, $snapshot);
        $this->assertSame(3_251_000, $plan->amount);
        $this->assertSame('amount_unique', $snapshot->verificationMode);
        $this->assertTrue($snapshot->autoVerificationEnabled);
        $this->assertSame('pay_id', $snapshot->receiverType);
        $this->assertSame('987654321', $snapshot->receiverValue);
        $this->assertSame('checking', $snapshot->verificationStatus);
    }

    public function test_order_id_plan_keeps_the_base_amount_and_awaits_submission(): void
    {
        config()->set('services.binance.pay_id', null);
        config()->set('services.binance.pay_uid', '123456789');
        config()->set('services.binance.api_key', 'read-only-key');
        config()->set('services.binance.api_secret', 'read-only-secret');

        $setting = ManualPaymentSetting::create([
            'payment_mode' => PaymentProvider::MANUAL,
            'is_active' => true,
            'binance_pay_enabled' => true,
            'binance_verification_mode' => 'order_id',
        ]);
        $product = Product::factory()->create(['usdt_price_amount' => 3_250_000]);

        $plan = $this->prepare($setting, $product);
        $snapshot = $plan->rail();

        $this->assertInstanceOf(CheckoutPaymentPlan::class, $plan);
        $this->assertInstanceOf(BinancePayCheckoutPaymentSnapshot::class, $snapshot);
        $this->assertSame(3_250_000, $plan->amount);
        $this->assertSame('order_id', $snapshot->verificationMode);
        $this->assertTrue($snapshot->autoVerificationEnabled);
        $this->assertSame('uid', $snapshot->receiverType);
        $this->assertSame('123456789', $snapshot->receiverValue);
        $this->assertSame('awaiting_submission', $snapshot->verificationStatus);
    }

    private function prepare(
        ManualPaymentSetting $setting,
        Product $product,
    ): CheckoutPaymentPlan {
        $preparation = app(BinanceCheckoutPaymentPreparation::class);
        $preflightContext = new CheckoutPaymentPreflightContext(
            currentSetting: $setting,
            requestedProvider: PaymentProvider::MANUAL,
            requestedMethod: BinanceCheckoutPaymentPreparation::METHOD,
        );

        $items = new Collection([(object) ['product_id' => $product->id, 'quantity' => 1]]);
        $products = new Collection([$product->id => $product]);

        return $preparation->prepare(new CheckoutPaymentPreparationContext(
            cart: Cart::factory()->create(),
            items: $items,
            products: $products,
            lockedSetting: $setting,
            preflight: $preparation->preflight($preflightContext),
            pricing: CheckoutPricingSnapshot::fromCartItems($items, $products, app(ProductQuantityPricing::class)),
        ));
    }
}
