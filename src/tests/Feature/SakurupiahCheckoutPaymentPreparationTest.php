<?php

namespace Tests\Feature;

use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
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
use App\Services\Sakurupiah\SakurupiahCreateInvoice;
use App\Services\Sakurupiah\SakurupiahFee;
use App\Services\Sakurupiah\SakurupiahInvoice;
use App\Services\Sakurupiah\SakurupiahInvoiceLifecycleService;
use App\Services\Sakurupiah\SakurupiahInvoiceOutcome;
use App\Services\Sakurupiah\SakurupiahProviderAdapter;
use App\Services\Sakurupiah\SakurupiahTransactionStatus;
use App\Support\PaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SakurupiahCheckoutPaymentPreparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sakurupiah_is_resolved_to_a_typed_idr_qris_checkout_plan_without_create_http(): void
    {
        $this->configureReadySakurupiah();
        $setting = ManualPaymentSetting::create([
            'payment_mode' => PaymentProvider::SAKURUPIAH,
            'sakurupiah_channel' => 'QRISC',
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
            'sakurupiah_channel' => 'QRISC',
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
            'sakurupiah_channel' => 'QRISC',
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
            'sakurupiah_channel' => 'QRISC',
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

    #[DataProvider('checkoutChannels')]
    public function test_checkout_releases_stock_after_fifteen_minutes_while_provider_invoice_remains_valid(string $channel, int $expiryHours): void
    {
        $this->travelTo('2026-10-27 07:00:00');
        Queue::fake();
        $this->configureReadySakurupiah(channel: $channel);
        ManualPaymentSetting::create([
            'payment_mode' => PaymentProvider::SAKURUPIAH,
            'sakurupiah_channel' => $channel,
            'is_active' => true,
        ]);
        $product = Product::factory()->create(['price_amount' => 12_000, 'stock' => 2, 'reserved_stock' => 0]);
        $cart = $this->cartForProduct($product, telegramId: null);
        $cart->customer->update(['phone_number' => '08123456789']);
        $startedAt = now()->toImmutable();
        $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::sakurupiah());
        $payment = $order->payments()->sole();
        $providerDeadline = $startedAt->addHours($expiryHours);
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('createInvoice')->once()->withArgs(
            fn (SakurupiahCreateInvoice $request): bool => $request->method === $channel && $request->expired === $expiryHours,
        )->andReturn(new SakurupiahInvoice(
            trxId: 'trx-local-deadline',
            merchantRef: $payment->merchant_ref,
            paymentCode: $channel,
            status: SakurupiahTransactionStatus::Pending,
            amount: 12_000,
            total: 12_000,
            fee: SakurupiahFee::from(0),
            merchantFee: 1,
            createdAt: $startedAt,
            expiresAt: $providerDeadline,
            checkoutUrl: 'https://sakurupiah.id/pay/local-deadline',
        ));
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);
        $result = app(SakurupiahInvoiceLifecycleService::class)->lifecycle($payment);
        $this->assertSame(SakurupiahInvoiceOutcome::Payable, $result->outcome());
        $this->assertSame($startedAt->addMinutes(15)->timestamp, $order->fresh()->expires_at->timestamp);
        $this->assertSame($startedAt->addMinutes(15)->timestamp, $payment->fresh()->expired_at->timestamp);
        $this->assertSame($providerDeadline->timestamp, $payment->fresh()->provider_expires_at->timestamp);
        $this->assertSame(1, $product->fresh()->reserved_stock);

        $this->travelTo($startedAt->addMinutes(15)->subSecond());
        $this->artisan('orders:expire-pending')->assertSuccessful();
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        $this->assertSame(1, $product->fresh()->reserved_stock);

        $this->travelTo($startedAt->addMinutes(15));
        $this->artisan('orders:expire-pending')->assertSuccessful();
        $this->assertSame(OrderStatus::Expired, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Expired, $payment->fresh()->status);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertSame(2, $product->fresh()->availableStock());
        $this->assertSame($providerDeadline->timestamp, $payment->fresh()->provider_expires_at->timestamp);
        Http::assertSentCount(1);
    }

    public static function checkoutChannels(): array
    {
        return [
            'QRIS' => ['QRIS', 24],
            'QRISC' => ['QRISC', 24],
            'QRISMU' => ['QRISMU', 12],
            'QRIS2' => ['QRIS2', 24],
        ];
    }

    private function configureReadySakurupiah(int $minimum = 200, int $maximum = 20_000_000, string $channel = 'QRISC'): void
    {
        config()->set('services.sakurupiah', array_replace(config('services.sakurupiah', []), [
            'sandbox_enabled' => true,
            'sandbox_base_url' => 'https://sakurupiah.id/api-sanbox/',
            'sandbox_api_id' => 'sandbox-id',
            'sandbox_api_key' => 'sandbox-key',
            'sandbox_callback_url' => 'https://store.example.test/api/webhook/sakurupiah',
            'sandbox_return_url' => 'https://store.example.test/shop/orders',
            'sandbox_allowed_hosts' => ['sakurupiah.id'],
            'sandbox_callback_allowed_hosts' => ['store.example.test'],
            'sandbox_return_allowed_hosts' => ['store.example.test'],
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
                'kode' => $channel,
                'nama' => $channel.' Merchant',
                'minimal' => $minimum,
                'maksimal' => $maximum,
                'tipe' => 'DIRECT',
                'status' => 'Aktif',
                'addition' => ['default_expired' => $channel === 'QRISMU' ? 12 : 24],
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
