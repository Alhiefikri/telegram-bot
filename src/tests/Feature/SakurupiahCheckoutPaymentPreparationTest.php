<?php

namespace Tests\Feature;

use App\Enums\InventoryMovementType;
use App\Exceptions\InvalidCheckout;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\Checkout\CheckoutPaymentPreflightContext;
use App\Services\Checkout\CheckoutPaymentPreparationContext;
use App\Services\Checkout\CheckoutPostCreationBehavior;
use App\Services\Checkout\CheckoutPricingSnapshot;
use App\Services\Checkout\PaymentProviderResolver;
use App\Services\Checkout\SakurupiahCheckoutIntent;
use App\Services\Checkout\SakurupiahCheckoutPaymentPreparation;
use App\Services\OrderCheckoutService;
use App\Services\Pricing\ProductQuantityPricing;
use App\Support\PaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SakurupiahCheckoutPaymentPreparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sakurupiah_is_resolved_to_a_typed_idr_qris_checkout_plan_without_create_http(): void
    {
        $this->configureReadySakurupiah();
        $setting = ManualPaymentSetting::create([
            'payment_mode' => PaymentProvider::SAKURUPIAH,
            'is_active' => true,
        ]);
        $product = Product::factory()->create([
            'price_amount' => 12_000,
            'stock' => 2,
            'reserved_stock' => 0,
        ]);
        $cart = $this->cartForProduct($product, telegramId: null);

        $selection = app(PaymentProviderResolver::class)->resolve('qris', $setting);

        $this->assertInstanceOf(SakurupiahCheckoutIntent::class, CheckoutPaymentIntents::sakurupiah());
        $this->assertSame(PaymentProvider::SAKURUPIAH, $selection->provider);
        $this->assertSame(PaymentProvider::SAKURUPIAH, $selection->paymentIntent->provider());
        $this->assertSame('QRIS', $selection->paymentIntent->method());
        $this->assertSame(CheckoutPostCreationBehavior::SakurupiahInstructions, $selection->paymentIntent->postCreationBehavior());
        $this->assertContains(PaymentProvider::SAKURUPIAH, PaymentProvider::allowed());
        $this->assertContains(PaymentProvider::SAKURUPIAH, PaymentProvider::known());
        $this->assertSame('Sakurupiah', PaymentProvider::label('SAKURUPIAH'));

        $order = app(OrderCheckoutService::class)->checkout(
            $cart,
            $selection->paymentIntent,
            checkoutKey: 'sakurupiah-checkout-key',
        );
        $replayed = app(OrderCheckoutService::class)->checkout(
            $cart,
            $selection->paymentIntent,
            checkoutKey: 'sakurupiah-checkout-key',
        );
        $this->assertSame($order->id, $replayed->id);
        $payment = $order->payments()->sole();

        $this->assertSame(PaymentProvider::SAKURUPIAH, $payment->provider);
        $this->assertSame('QRIS', $payment->method);
        $this->assertSame('IDR', $payment->currency);
        $this->assertSame(12_000, $payment->amount);
        $this->assertSame($order->expires_at->timestamp, $payment->expired_at->timestamp);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, InventoryMovement::query()->where('type', InventoryMovementType::Reservation)->count());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/list-payment.php'));
    }

    public function test_unready_sakurupiah_preparation_rolls_back_all_checkout_side_effects(): void
    {
        config()->set('services.sakurupiah', array_replace(config('services.sakurupiah', []), [
            'sandbox_enabled' => false,
            'sandbox_api_id' => 'sandbox-id',
            'sandbox_api_key' => 'sandbox-key',
        ]));
        Http::fake();
        ManualPaymentSetting::create([
            'payment_mode' => PaymentProvider::SAKURUPIAH,
            'is_active' => true,
        ]);
        $product = Product::factory()->create(['price_amount' => 12_000, 'stock' => 1, 'reserved_stock' => 0]);
        $cart = $this->cartForProduct($product, telegramId: null);

        $this->expectException(InvalidCheckout::class);

        try {
            app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::sakurupiah());
        } finally {
            $this->assertSame(0, Order::query()->count());
            $this->assertSame(0, Payment::query()->count());
            $this->assertSame(0, InventoryMovement::query()->count());
            $this->assertSame(0, $product->fresh()->reserved_stock);
            Http::assertNothingSent();
        }
    }

    public function test_unqualified_qris_contract_fails_before_local_persistence(): void
    {
        $this->configureReadySakurupiah();
        config()->set('services.sakurupiah.qris_contract.currency_confirmed', false);
        ManualPaymentSetting::create([
            'payment_mode' => PaymentProvider::SAKURUPIAH,
            'is_active' => true,
        ]);
        $product = Product::factory()->create(['price_amount' => 12_000, 'stock' => 1, 'reserved_stock' => 0]);
        $cart = $this->cartForProduct($product, telegramId: null);

        $this->expectException(InvalidCheckout::class);

        try {
            app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::sakurupiah());
        } finally {
            $this->assertSame(0, Order::query()->count());
            $this->assertSame(0, Payment::query()->count());
            $this->assertSame(0, InventoryMovement::query()->count());
            $this->assertSame(0, $product->fresh()->reserved_stock);
            Http::assertSentCount(1);
        }
    }

    public function test_amount_outside_runtime_qris_limits_fails_before_local_persistence(): void
    {
        $this->configureReadySakurupiah(minimum: 1_000, maximum: 5_000);
        ManualPaymentSetting::create([
            'payment_mode' => PaymentProvider::SAKURUPIAH,
            'is_active' => true,
        ]);
        $product = Product::factory()->create(['price_amount' => 6_000, 'stock' => 1, 'reserved_stock' => 0]);
        $cart = $this->cartForProduct($product, telegramId: null);

        $this->expectException(InvalidCheckout::class);

        try {
            app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::sakurupiah());
        } finally {
            $this->assertSame(0, Order::query()->count());
            $this->assertSame(0, Payment::query()->count());
            $this->assertSame(0, InventoryMovement::query()->count());
            $this->assertSame(0, $product->fresh()->reserved_stock);
            Http::assertSentCount(1);
        }
    }

    public function test_preflight_configuration_fingerprint_rejects_contract_change_without_another_http_call(): void
    {
        $this->configureReadySakurupiah();
        $setting = ManualPaymentSetting::create([
            'payment_mode' => PaymentProvider::SAKURUPIAH,
            'is_active' => true,
        ]);
        $product = Product::factory()->create(['price_amount' => 12_000, 'stock' => 1, 'reserved_stock' => 0]);
        $cart = $this->cartForProduct($product, telegramId: null);
        $preparation = app(SakurupiahCheckoutPaymentPreparation::class);
        $preflightContext = new CheckoutPaymentPreflightContext(
            currentSetting: $setting,
            requestedProvider: PaymentProvider::SAKURUPIAH,
            requestedMethod: SakurupiahCheckoutPaymentPreparation::METHOD,
        );
        $preflight = $preparation->preflight($preflightContext);
        Http::assertSentCount(1);

        config()->set('services.sakurupiah.qris_contract.expiry_hours', 12);

        $this->expectException(InvalidCheckout::class);
        $items = new Collection($cart->cartItems()->get()->all());
        $products = new Collection([$product->id => $product]);
        $preparation->prepare(new CheckoutPaymentPreparationContext(
            cart: $cart,
            items: $items,
            products: $products,
            lockedSetting: $setting,
            preflight: $preflight,
            pricing: CheckoutPricingSnapshot::fromCartItems($items, $products, app(ProductQuantityPricing::class)),
        ));
        Http::assertSentCount(1);
    }

    private function configureReadySakurupiah(int $minimum = 200, int $maximum = 20_000_000): void
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
                'expiry_hours' => 24,
                'expiry_confirmed' => true,
            ],
        ]));
        Http::fake(fn () => Http::response([
            'status' => '200',
            'data' => [[
                'kode' => 'QRISC',
                'nama' => 'QRISC Custom Merchant',
                'minimal' => $minimum,
                'maksimal' => $maximum,
                'tipe' => 'DIRECT',
                'status' => 'Aktif',
                'addition' => ['default_expired' => 24],
            ]],
        ], 200));
    }

    private function cartForProduct(Product $product, ?int $telegramId): Cart
    {
        $customer = Customer::factory()->create(['telegram_id' => $telegramId]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->setItemQuantity($cart, $product, 1);

        return $cart->fresh(['cartItems']);
    }
}
