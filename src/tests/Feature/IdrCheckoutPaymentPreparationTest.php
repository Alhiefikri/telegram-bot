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
use App\Services\Checkout\KlikQrisCheckoutPaymentPreparation;
use App\Services\Checkout\ManualQrisCheckoutPaymentPreparation;
use App\Services\Checkout\PakasirCheckoutPaymentPreparation;
use App\Services\Checkout\PaymentPlan\KlikQrisCheckoutPaymentSnapshot;
use App\Services\Checkout\PaymentPlan\ManualQrisCheckoutPaymentSnapshot;
use App\Services\Checkout\PaymentPlan\PakasirCheckoutPaymentSnapshot;
use App\Support\PakasirCheckoutFlow;
use App\Support\PaymentProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\TestCase;

final class IdrCheckoutPaymentPreparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolver_registers_the_manual_pakasir_and_klikqris_adapters(): void
    {
        $resolver = app(CheckoutPaymentPreparationResolver::class);

        $this->assertInstanceOf(
            ManualQrisCheckoutPaymentPreparation::class,
            $resolver->for(CheckoutPaymentIntents::manualQris()),
        );
        $this->assertInstanceOf(
            PakasirCheckoutPaymentPreparation::class,
            $resolver->for(CheckoutPaymentIntents::pakasir(PakasirCheckoutFlow::HOSTED)),
        );
        $this->assertInstanceOf(
            PakasirCheckoutPaymentPreparation::class,
            $resolver->for(CheckoutPaymentIntents::pakasir(PakasirCheckoutFlow::API_QRIS)),
        );
        $this->assertInstanceOf(
            KlikQrisCheckoutPaymentPreparation::class,
            $resolver->for(CheckoutPaymentIntents::klikQris()),
        );
    }

    public function test_manual_qris_plan_uses_the_product_total_and_requested_expiry(): void
    {
        $setting = ManualPaymentSetting::create([
            'payment_mode' => PaymentProvider::MANUAL,
            'is_active' => true,
            'qris_payment_ttl_minutes' => 11,
        ]);
        $deadline = CarbonImmutable::parse('2030-02-03 04:05:06');
        $product = Product::factory()->create(['price_amount' => 15_000]);

        $plan = $this->prepare(
            CheckoutPaymentIntents::manualQris($deadline),
            $setting,
            $product,
        );

        $this->assertPlan($plan, 'manual', 'QR_MANUAL', 30_000, $deadline);
        $this->assertInstanceOf(ManualQrisCheckoutPaymentSnapshot::class, $plan->rail());
    }

    #[DataProvider('pakasirFlows')]
    public function test_pakasir_plan_preserves_the_selected_flow_without_provider_http(string $flow): void
    {
        config()->set('services.pakasir.slug', 'preparation-store');
        config()->set('services.pakasir.api_key', 'preparation-key');
        config()->set('services.pakasir.base_url', 'https://app.pakasir.test');
        Http::fake();
        $setting = ManualPaymentSetting::create([
            'payment_mode' => PaymentProvider::PAKASIR,
            'pakasir_checkout_flow' => $flow,
            'is_active' => true,
        ]);
        $deadline = CarbonImmutable::parse('2030-03-04 05:06:07');
        $product = Product::factory()->create(['price_amount' => 12_500]);

        $plan = $this->prepare(
            CheckoutPaymentIntents::pakasir($flow, $deadline),
            $setting,
            $product,
        );

        $this->assertPlan($plan, PaymentProvider::PAKASIR, $flow, 25_000, $deadline);
        $snapshot = $plan->rail();
        $this->assertInstanceOf(PakasirCheckoutPaymentSnapshot::class, $snapshot);
        $this->assertSame($flow, $snapshot->checkoutFlow());
        Http::assertNothingSent();
    }

    /** @return array<string, array{string}> */
    public static function pakasirFlows(): array
    {
        return [
            'hosted' => [PakasirCheckoutFlow::HOSTED],
            'native QRIS' => [PakasirCheckoutFlow::API_QRIS],
        ];
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
            CheckoutPaymentIntents::klikQris($deadline),
            $setting,
            $product,
        );

        $this->assertPlan($plan, PaymentProvider::KLIKQRIS, 'QRIS', 40_000, $deadline);
        $this->assertInstanceOf(KlikQrisCheckoutPaymentSnapshot::class, $plan->rail());
        Http::assertNothingSent();
    }

    private function prepare(
        CheckoutPaymentIntent $intent,
        ManualPaymentSetting $setting,
        Product $product,
    ): CheckoutPaymentPlan {
        $preparation = app(CheckoutPaymentPreparationResolver::class)->for($intent);
        $preflightContext = new CheckoutPaymentPreflightContext(
            currentSetting: $setting,
            requestedProvider: $intent->provider(),
            requestedMethod: $intent->method(),
        );

        return $preparation->prepare(new CheckoutPaymentPreparationContext(
            cart: Cart::factory()->create(),
            items: new Collection([(object) ['product_id' => $product->id, 'quantity' => 2]]),
            products: new Collection([$product->id => $product]),
            lockedSetting: $setting,
            preflight: $preparation->preflight($preflightContext),
            requestedExpiresAt: $intent->expiresAt(setting: $setting),
        ));
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
                PaymentProvider::PAKASIR => CheckoutPostCreationBehavior::PakasirInstructions,
                PaymentProvider::KLIKQRIS => CheckoutPostCreationBehavior::KlikQrisInstructions,
                default => CheckoutPostCreationBehavior::ManualQrisInstructions,
            },
            $plan->postCreationBehavior(),
        );
    }
}
