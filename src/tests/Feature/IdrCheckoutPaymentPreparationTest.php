<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\ManualPaymentSetting;
use App\Models\Product;
use App\Services\Checkout\CheckoutPaymentIntent;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\Checkout\CheckoutPaymentPlan;
use App\Services\Checkout\CheckoutPaymentPreflightContext;
use App\Services\Checkout\CheckoutPaymentPreparationContext;
use App\Services\Checkout\CheckoutPaymentPreparationResolver;
use App\Services\Checkout\CheckoutPostCreationBehavior;
use App\Services\Checkout\CheckoutPricingSnapshot;
use App\Services\Checkout\KlikQrisCheckoutPaymentPreparation;
use App\Services\Checkout\ManualQrisCheckoutPaymentPreparation;
use App\Services\Checkout\PaymentPlan\KlikQrisCheckoutPaymentSnapshot;
use App\Services\Checkout\PaymentPlan\ManualQrisCheckoutPaymentSnapshot;
use App\Services\Pricing\ProductQuantityPricing;
use App\Support\PaymentProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\TestCase;

final class IdrCheckoutPaymentPreparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolver_registers_the_manual_and_klikqris_adapters(): void
    {
        $resolver = app(CheckoutPaymentPreparationResolver::class);

        $this->assertInstanceOf(
            ManualQrisCheckoutPaymentPreparation::class,
            $resolver->for(CheckoutPaymentIntents::manualQris()),
        );
        $this->assertInstanceOf(
            KlikQrisCheckoutPaymentPreparation::class,
            $resolver->for(CheckoutPaymentIntents::klikQris()),
        );
    }

    public function test_manual_qris_plan_uses_the_product_total_and_configured_expiry(): void
    {
        $setting = ManualPaymentSetting::create([
            'payment_mode' => PaymentProvider::MANUAL,
            'is_active' => true,
            'qris_payment_ttl_minutes' => 11,
        ]);
        $deadline = CarbonImmutable::parse('2030-02-03 04:05:06');
        $product = Product::factory()->create(['price_amount' => 15_000]);

        $plan = $this->prepare(
            CheckoutPaymentIntents::manualQris(),
            $setting,
            $product,
            $deadline,
        );

        $this->assertPlan($plan, 'manual', 'QR_MANUAL', 30_000, $deadline);
        $this->assertInstanceOf(ManualQrisCheckoutPaymentSnapshot::class, $plan->rail());
    }

    public function test_klikqris_plan_is_only_a_local_snapshot_without_qr_creation(): void
    {
        KlikQrisSandboxScenarios::configureReadySandbox();
        Http::fake();
        $setting = ManualPaymentSetting::create([
            'payment_mode' => PaymentProvider::KLIKQRIS,
            'is_active' => true,
        ]);
        $deadline = CarbonImmutable::parse('2030-04-05 06:07:08');
        $product = Product::factory()->create(['price_amount' => 20_000]);

        $plan = $this->prepare(
            CheckoutPaymentIntents::klikQris(),
            $setting,
            $product,
            $deadline,
        );

        $this->assertPlan($plan, PaymentProvider::KLIKQRIS, 'QRIS', 40_000, $deadline);
        $this->assertInstanceOf(KlikQrisCheckoutPaymentSnapshot::class, $plan->rail());
        Http::assertNothingSent();
    }

    private function prepare(
        CheckoutPaymentIntent $intent,
        ManualPaymentSetting $setting,
        Product $product,
        CarbonImmutable $deadline,
    ): CheckoutPaymentPlan {
        $preparation = app(CheckoutPaymentPreparationResolver::class)->for($intent);
        $ttl = $intent->provider() === PaymentProvider::KLIKQRIS
            ? (int) config('services.klikqris.qris_ttl_minutes', 15)
            : $setting->qrisPaymentTtlMinutes();
        $this->travelTo($deadline->copy()->subMinutes($ttl));

        try {
            $preflightContext = new CheckoutPaymentPreflightContext(
                currentSetting: $setting,
                requestedProvider: $intent->provider(),
                requestedMethod: $intent->method(),
            );

            $items = new Collection([(object) ['product_id' => $product->id, 'quantity' => 2]]);
            $products = new Collection([$product->id => $product]);

            return $preparation->prepare(new CheckoutPaymentPreparationContext(
                cart: Cart::factory()->create(),
                items: $items,
                products: $products,
                lockedSetting: $setting,
                preflight: $preparation->preflight($preflightContext),
                pricing: CheckoutPricingSnapshot::fromCartItems($items, $products, app(ProductQuantityPricing::class)),
            ));
        } finally {
            $this->travelBack();
        }
    }

    private function assertPlan(
        CheckoutPaymentPlan $plan,
        string $provider,
        string $method,
        int $amount,
        CarbonImmutable $deadline,
    ): void {
        $this->assertSame('IDR', $plan->orderCurrency());
        $this->assertSame($provider, $plan->provider());
        $this->assertSame($method, $plan->method());
        $this->assertSame('IDR', $plan->paymentCurrency());
        $this->assertSame($amount, $plan->amount());
        $this->assertTrue($plan->orderExpiresAt()->equalTo($deadline));
        $this->assertTrue($plan->paymentExpiresAt()->equalTo($deadline));
        $this->assertSame(
            match ($provider) {
                PaymentProvider::KLIKQRIS => CheckoutPostCreationBehavior::KlikQrisInstructions,
                default => CheckoutPostCreationBehavior::ManualQrisInstructions,
            },
            $plan->postCreationBehavior(),
        );
    }
}
