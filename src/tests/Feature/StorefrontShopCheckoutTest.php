<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Requests\StorefrontShopCheckoutRequest;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Support\PakasirCheckoutFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class StorefrontShopCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_named_route_contracts_are_registered_without_replacing_legacy_product_routes(): void
    {
        $this->assertRouteContract('storefront.shop.index', 'GET', 'shop');
        $this->assertRouteContract('storefront.shop.checkout', 'POST', 'shop/checkout');
        $this->assertRouteContract('storefront.products.show', 'GET', 'produk/{product}');
        $this->assertRouteContract('storefront.products.payment', 'GET', 'produk/{product}/bayar');

        $showRoute = $this->assertRouteContract('storefront.shop.orders.show', 'GET', 'shop/orders/{order}');
        $statusRoute = $this->assertRouteContract('storefront.shop.orders.status', 'POST', 'shop/orders/{order}/status');

        $this->assertContains('signed', $showRoute->middleware());
        $this->assertContains('signed', $statusRoute->middleware());
        $this->assertContains('throttle:pakasir-status-refresh', $statusRoute->middleware());

        $order = Order::factory()->create();
        $showUrl = URL::signedRoute('storefront.shop.orders.show', $order);
        $statusUrl = URL::signedRoute('storefront.shop.orders.status', $order);

        $this->assertSame('/shop/orders/'.$order->getKey(), parse_url($showUrl, PHP_URL_PATH));
        $this->assertSame('/shop/orders/'.$order->getKey().'/status', parse_url($statusUrl, PHP_URL_PATH));
        $this->assertTrue(URL::hasValidSignature(Request::create($showUrl, 'GET')));
        $this->assertTrue(URL::hasValidSignature(Request::create($statusUrl, 'POST')));
    }

    public function test_buyer_handle_normalizes_telegram_usernames_and_keeps_display_names_separate(): void
    {
        $cases = [
            'telegram username with at sign' => [
                'input' => ' @LhieStore_99 ',
                'expected' => ['display_name' => 'LhieStore_99', 'telegram_username' => 'LhieStore_99'],
            ],
            'telegram username without at sign' => [
                'input' => 'LhieStore_99',
                'expected' => ['display_name' => 'LhieStore_99', 'telegram_username' => 'LhieStore_99'],
            ],
            'display name with spaces is not telegram' => [
                'input' => ' Lia Store ',
                'expected' => ['display_name' => 'Lia Store', 'telegram_username' => null],
            ],
            'display name with dash is not telegram' => [
                'input' => 'lia-store',
                'expected' => ['display_name' => 'lia-store', 'telegram_username' => null],
            ],
        ];

        foreach ($cases as $case => $data) {
            $request = StorefrontShopCheckoutRequest::create('/shop/checkout', 'POST', [
                'buyer_handle' => $data['input'],
            ]);

            $this->assertSame($data['expected'], $request->buyerHandle(), $case);
        }
    }

    public function test_pakasir_api_qris_checkout_creates_web_customer_pending_order_and_redirects_to_signed_order(): void
    {
        $this->configurePakasirApiQrisMode();
        $product = Product::factory()->create(['name' => 'ChatGPT Plus', 'price_amount' => 25000, 'stock' => 4, 'reserved_stock' => 0]);
        $paymentNumber = '00020101021226610016ID.CO.QRIS.TEST0118SIGNEDWEBBUYER99';
        $providerExpiresAt = now()->addMinutes(15)->toISOString();

        Http::preventStrayRequests();
        Http::fake([
            'https://app.pakasir.test/api/transactioncreate/qris' => function (HttpRequest $request) use ($paymentNumber, $providerExpiresAt) {
                return Http::response([
                    'payment' => [
                        'project' => 'lhie-test',
                        'order_id' => $request['order_id'],
                        'amount' => $request['amount'],
                        'payment_method' => 'qris',
                        'payment_number' => $paymentNumber,
                        'fee' => 700,
                        'total_payment' => $request['amount'] + 700,
                        'expired_at' => $providerExpiresAt,
                    ],
                ], 200);
            },
        ]);

        $response = $this
            ->from(route('storefront.shop.index'))
            ->post(route('storefront.shop.checkout'), [
                'product_id' => $product->id,
                'quantity' => 2,
                'buyer_handle' => '@WebBuyer_99',
            ]);

        $order = Order::with(['customer', 'payments', 'cart'])->firstOrFail();
        $payment = $order->payments->first();

        $this->assertRedirectsToSignedOrderShow($response->headers->get('Location'), $order);
        $this->assertNull($order->customer->telegram_id);
        $this->assertSame('WebBuyer_99', $order->customer->username);
        $this->assertSame('WebBuyer_99', $order->customer->first_name);
        $this->assertSame(OrderStatus::PendingPayment, $order->order_status);
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
        $this->assertSame(50000, $order->total_amount);
        $this->assertSame(CartStatus::CheckedOut, $order->cart->status);

        $this->assertNotNull($payment);
        $this->assertSame('pakasir', $payment->provider);
        $this->assertSame(PakasirCheckoutFlow::API_QRIS, $payment->method);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame(50000, $payment->amount);
        $this->assertSame('qris', $payment->provider_payment_method);
        $this->assertSame($paymentNumber, $payment->provider_payment_number);
        $this->assertSame(hash('sha256', $paymentNumber), $payment->provider_payment_number_hash);
        $this->assertSame(700, $payment->provider_fee);
        $this->assertSame(50700, $payment->provider_total_payment);
        $this->assertSame('lhie-test', data_get($payment->provider_payload, 'payment.project'));
        $this->assertSame($payment->merchant_ref, data_get($payment->provider_payload, 'payment.order_id'));
        $this->assertSame(50000, data_get($payment->provider_payload, 'payment.amount'));
        $this->assertNull(data_get($payment->provider_payload, 'payment.payment_number'));

        $this->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee($order->invoice_number)
            ->assertSee('src="data:image/png;base64,', false)
            ->assertDontSee($paymentNumber)
            ->assertDontSee('/qr-lhie.jpg', false);

        $product->refresh();
        $this->assertSame(4, $product->stock);
        $this->assertSame(2, $product->reserved_stock);
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Reservation)->count());

        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://app.pakasir.test/api/transactioncreate/qris'
            && $request['project'] === 'lhie-test'
            && $request['order_id'] === $payment->merchant_ref
            && $request['amount'] === 50000
            && $request['api_key'] === 'secret-key');
    }

    public function test_checkout_rejects_before_local_rows_when_pakasir_native_qris_is_not_available(): void
    {
        $message = 'QRIS otomatis belum aktif. Hubungi admin atau coba lagi nanti.';
        $cases = [
            'missing pakasir credentials' => function (): void {
                config()->set('services.pakasir.slug', null);
                config()->set('services.pakasir.api_key', null);
                config()->set('services.pakasir.base_url', 'https://app.pakasir.test');
                ManualPaymentSetting::create([
                    'payment_mode' => 'pakasir',
                    'pakasir_checkout_flow' => PakasirCheckoutFlow::API_QRIS,
                    'is_active' => true,
                ]);
            },
            'admin payment mode is manual' => function (): void {
                $this->configurePakasirConfig();
                ManualPaymentSetting::create([
                    'payment_mode' => 'manual',
                    'pakasir_checkout_flow' => PakasirCheckoutFlow::API_QRIS,
                    'is_active' => true,
                ]);
            },
            'admin pakasir flow is hosted' => function (): void {
                $this->configurePakasirConfig();
                ManualPaymentSetting::create([
                    'payment_mode' => 'pakasir',
                    'pakasir_checkout_flow' => PakasirCheckoutFlow::HOSTED,
                    'is_active' => true,
                ]);
            },
        ];

        foreach ($cases as $case => $configure) {
            ManualPaymentSetting::query()->delete();
            $configure();
            $product = Product::factory()->create(['stock' => 3, 'reserved_stock' => 0]);
            Http::fake();

            $response = $this
                ->from(route('storefront.shop.index'))
                ->post(route('storefront.shop.checkout'), [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'buyer_handle' => 'Web Buyer',
                ]);

            $response->assertRedirect(route('storefront.shop.index'));
            $this->assertSessionContainsMessage($message, $case);
            $this->assertNoLocalCheckoutRowsCreated($case);
            $this->assertSame(3, $product->fresh()->stock, $case);
            $this->assertSame(0, $product->fresh()->reserved_stock, $case);
            Http::assertNothingSent();
        }
    }

    public function test_native_qris_create_failure_after_local_checkout_releases_stock_and_credentials(): void
    {
        $this->configurePakasirApiQrisMode();
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0, 'price_amount' => 33000]);
        $stockItem = ProductStockItem::create([
            'product_id' => $product->id,
            'secret' => 'web-checkout@example.test|Secret-123',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://app.pakasir.test/api/transactioncreate/qris' => Http::response(['message' => 'upstream unavailable'], 500),
        ]);

        $response = $this
            ->from(route('storefront.shop.index'))
            ->post(route('storefront.shop.checkout'), [
                'product_id' => $product->id,
                'quantity' => 1,
                'buyer_handle' => '@RetryBuyer',
            ]);

        $response->assertRedirect(route('storefront.shop.index'));
        $this->assertSessionContainsMessage('QRIS Pakasir belum bisa dibuat. Stok sudah dilepas; coba lagi beberapa menit.');

        $order = Order::with(['payments', 'orderItems'])->firstOrFail();
        $payment = $order->payments->first();
        $product->refresh();
        $stockItem->refresh();

        $this->assertSame(OrderStatus::Cancelled, $order->order_status);
        $this->assertSame(PaymentStatus::Failed, $order->payment_status);
        $this->assertNotNull($payment);
        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame(1, $product->stock);
        $this->assertSame(0, $product->reserved_stock);
        $this->assertNull($stockItem->order_item_id);
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Reservation)->count());
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Release)->count());
    }

    private function assertRouteContract(string $name, string $method, string $uri): \Illuminate\Routing\Route
    {
        $route = Route::getRoutes()->getByName($name);

        $this->assertNotNull($route, "Route [{$name}] is not registered.");
        $this->assertSame($uri, $route->uri(), "Route [{$name}] changed URI.");
        $this->assertContains($method, $route->methods(), "Route [{$name}] does not support {$method}.");

        return $route;
    }

    private function configurePakasirApiQrisMode(): void
    {
        $this->configurePakasirConfig();
        ManualPaymentSetting::create([
            'payment_mode' => 'pakasir',
            'pakasir_checkout_flow' => PakasirCheckoutFlow::API_QRIS,
            'is_active' => true,
        ]);
    }

    private function configurePakasirConfig(): void
    {
        config()->set('services.pakasir.slug', 'lhie-test');
        config()->set('services.pakasir.api_key', 'secret-key');
        config()->set('services.pakasir.base_url', 'https://app.pakasir.test');
        config()->set('services.pakasir.redirect_url', 'https://lhie.test/shop/pakasir-return');
    }

    private function assertRedirectsToSignedOrderShow(?string $location, Order $order): void
    {
        $this->assertIsString($location);
        $this->assertSame('/shop/orders/'.$order->getKey(), parse_url($location, PHP_URL_PATH));
        $this->assertTrue(URL::hasValidSignature(Request::create($location, 'GET')));
    }

    private function assertSessionContainsMessage(string $message, string $context = ''): void
    {
        $messages = [];
        $errors = session('errors');
        if ($errors instanceof ViewErrorBag) {
            $messages = array_merge($messages, $errors->getBag('default')->all());
        }

        $session = session()->all();
        array_walk_recursive($session, function (mixed $value) use (&$messages): void {
            if (is_string($value)) {
                $messages[] = $value;
            }
        });

        $this->assertContains($message, array_values(array_unique($messages)), $context);
    }

    private function assertNoLocalCheckoutRowsCreated(string $context): void
    {
        $this->assertSame(0, Customer::count(), $context);
        $this->assertSame(0, Cart::count(), $context);
        $this->assertSame(0, CartItem::count(), $context);
        $this->assertSame(0, Order::count(), $context);
        $this->assertSame(0, Payment::count(), $context);
        $this->assertSame(0, InventoryMovement::count(), $context);
    }
}
