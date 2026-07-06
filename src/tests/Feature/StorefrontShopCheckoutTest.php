<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidCheckout;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Services\OrderStateMachine;
use App\Services\Telegram\TelegramQrCodeRenderer;
use App\Storefront\StorefrontShopCheckout;
use App\Storefront\StorefrontShopCheckoutInput;
use App\Support\PakasirCheckoutFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
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

    public function test_checkout_module_normalizes_buyer_identity_from_input_handle(): void
    {
        $cases = [
            'telegram username with at sign' => [
                'input' => ' @LhieStore_99 ',
                'expected_first_name' => 'LhieStore_99',
                'expected_username' => 'LhieStore_99',
            ],
            'telegram username without at sign' => [
                'input' => 'LhieStore_99',
                'expected_first_name' => 'LhieStore_99',
                'expected_username' => 'LhieStore_99',
            ],
            'display name with spaces is not telegram' => [
                'input' => ' Lia Store ',
                'expected_first_name' => 'Lia Store',
                'expected_username' => null,
            ],
            'display name with dash is not telegram' => [
                'input' => 'lia-store',
                'expected_first_name' => 'lia-store',
                'expected_username' => null,
            ],
        ];

        foreach (array_values($cases) as $index => $data) {
            $this->configurePakasirApiQrisMode();
            $this->fakePakasirQrisCreate(
                amount: 15000,
                paymentNumber: '00020101021226610016ID.CO.QRIS.NORMALIZE'.$index,
                fee: 103,
            );
            $product = Product::factory()->create([
                'price_amount' => 15000,
                'stock' => 1,
                'reserved_stock' => 0,
                'is_active' => true,
            ]);

            $result = app(StorefrontShopCheckout::class)->start(new StorefrontShopCheckoutInput(
                productId: $product->id,
                quantity: 1,
                buyerHandle: $data['input'],
            ));
            $order = Order::latest('id')->firstOrFail();

            $this->assertRedirectsToSignedOrderShow($result->redirectUrl, $order);
            $this->assertDatabaseHas('customers', [
                'id' => $order->customer_id,
                'telegram_id' => null,
                'username' => $data['expected_username'],
                'first_name' => $data['expected_first_name'],
            ]);
        }
    }

    public function test_checkout_module_rejects_products_that_are_not_available_products(): void
    {
        $this->configurePakasirApiQrisMode();
        Http::fake();

        $cases = [
            'inactive product' => Product::factory()->create([
                'stock' => 1,
                'reserved_stock' => 0,
                'is_active' => false,
            ]),
            'insufficient stock' => Product::factory()->create([
                'stock' => 1,
                'reserved_stock' => 1,
                'is_active' => true,
            ]),
        ];

        foreach ($cases as $case => $product) {
            try {
                app(StorefrontShopCheckout::class)->start(new StorefrontShopCheckoutInput(
                    productId: $product->id,
                    quantity: 1,
                    buyerHandle: 'Web Buyer',
                ));
                $this->fail('Expected checkout to reject '.$case.'.');
            } catch (InsufficientStock $exception) {
                $this->assertSame('Stok produk tidak cukup atau tidak tersedia.', $exception->getMessage(), $case);
            }

            $this->assertNoLocalCheckoutRowsCreated($case);
        }

        Http::assertNothingSent();
    }

    public function test_checkout_module_preserves_native_qris_readiness_error_before_available_product_validation(): void
    {
        Http::fake();
        $product = Product::factory()->create([
            'stock' => 1,
            'reserved_stock' => 1,
            'is_active' => true,
        ]);

        try {
            app(StorefrontShopCheckout::class)->start(new StorefrontShopCheckoutInput(
                productId: $product->id,
                quantity: 1,
                buyerHandle: 'Web Buyer',
            ));
            $this->fail('Expected checkout to reject disabled native QRIS before validating availability.');
        } catch (InvalidCheckout $exception) {
            $this->assertSame('QRIS otomatis belum aktif. Hubungi admin atau coba lagi nanti.', $exception->getMessage());
        }

        $this->assertNoLocalCheckoutRowsCreated('disabled native QRIS');
        Http::assertNothingSent();
    }

    public function test_shop_checkout_maps_missing_product_to_existing_validation_message(): void
    {
        $this->configurePakasirApiQrisMode();
        Http::fake();

        $response = $this
            ->from(route('storefront.shop.index'))
            ->post(route('storefront.shop.checkout'), [
                'product_id' => 999_999,
                'quantity' => 1,
                'buyer_handle' => '@buyer_test',
            ]);

        $response
            ->assertRedirect(route('storefront.shop.index'))
            ->assertInvalid(['product_id' => 'Produk tidak ditemukan atau sudah tidak tersedia.']);
        $this->assertNoLocalCheckoutRowsCreated('missing product');
        Http::assertNothingSent();
    }

    public function test_shop_route_renders_marketplace_with_ready_modal_and_empty_stock_state(): void
    {
        $ready = Product::factory()->create([
            'name' => 'Claude Pro Ready',
            'description' => 'Akun private siap dari dashboard',
            'price_amount' => 60000,
            'stock' => 1,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        $empty = Product::factory()->create([
            'name' => 'ChatGPT Habis',
            'price_amount' => 45000,
            'stock' => 1,
            'reserved_stock' => 1,
            'is_active' => true,
        ]);

        $response = $this->get(route('storefront.shop.index'));
        $emptyCard = $this->unavailableProductCardHtml($response->getContent(), $empty);

        $response
            ->assertOk()
            ->assertSee('Marketplace akun AI siap pakai')
            ->assertSee('openProductModal(JSON.parse($el.dataset.product))', false)
            ->assertSee('data-product=', false)
            ->assertSee('Claude Pro Ready')
            ->assertSee('Akun private siap dari dashboard')
            ->assertSee('Rp60.000')
            ->assertSee('Stok: 1')
            ->assertSee('Bayar QRIS di Website')
            ->assertSee('Beli lewat Telegram')
            ->assertSee('STOK HABIS')
            ->assertSee('Restock segera')
            ->assertSee('Stok kosong');

        $this->assertStringContainsString('id="product-'.$empty->id.'"', $emptyCard);
        $this->assertStringContainsString('ChatGPT Habis', $emptyCard);
        $this->assertStringContainsString('STOK HABIS', $emptyCard);
        $this->assertStringContainsString('Restock segera', $emptyCard);
        $this->assertStringContainsString('Stok kosong', $emptyCard);
        $this->assertStringNotContainsString('openProductModal', $emptyCard);
        $this->assertStringNotContainsString('JSON.parse($el.dataset.product)', $emptyCard);
        $this->assertStringNotContainsString('data-product=', $emptyCard);
    }

    public function test_shop_modal_keeps_telegram_checkout_action(): void
    {
        Product::factory()->create([
            'name' => 'CapCut Pro Web',
            'stock' => 3,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $this->get(route('storefront.shop.index'))
            ->assertOk()
            ->assertSee('checkoutManual()', false)
            ->assertSee('@click="checkoutManual()"', false)
            ->assertSee('Beli lewat Telegram')
            ->assertSee('action="'.route('storefront.shop.checkout').'"', false)
            ->assertSee('method="POST"', false)
            ->assertSee('Bayar QRIS di Website');
    }

    public function test_shop_checkout_creates_native_qris_order_from_provider_total(): void
    {
        $product = Product::factory()->create([
            'name' => 'GPT Plus Native QRIS',
            'price_amount' => 15000,
            'stock' => 4,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'first-account@example.test|first-secret']);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'second-account@example.test|second-secret']);
        $paymentNumber = '00020101021226610016ID.CO.QRIS.SHOPCHECKOUTNATIVE99';

        [$response, $order, $payment] = $this->startNativeQrisCheckout(
            product: $product,
            quantity: 2,
            buyerHandle: '@buyer_test',
            paymentNumber: $paymentNumber,
        );

        $location = $response->headers->get('Location');
        $this->assertIsString($location);
        $this->assertStringContainsString('/shop/orders/', $location);
        $this->assertStringContainsString('signature=', $location);
        $this->assertSame('/shop/orders/'.$order->getKey(), parse_url($location, PHP_URL_PATH));
        $this->assertTrue(URL::hasValidSignature(Request::create($location, 'GET')));

        $this->assertDatabaseHas('customers', [
            'id' => $order->customer_id,
            'telegram_id' => null,
            'username' => 'buyer_test',
            'first_name' => 'buyer_test',
        ]);
        $this->assertSame(30000, $order->total_amount);
        $this->assertSame(OrderStatus::PendingPayment, $order->order_status);
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
        $this->assertSame(CartStatus::CheckedOut, $order->cart?->status);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'order_id' => $order->id,
            'provider' => 'pakasir',
            'method' => PakasirCheckoutFlow::API_QRIS,
            'amount' => 30000,
            'provider_fee' => 103,
            'provider_total_payment' => 30103,
            'provider_payment_method' => 'qris',
        ]);
        $this->assertSame($paymentNumber, $payment->provider_payment_number);
        $this->assertSame(hash('sha256', $paymentNumber), $payment->provider_payment_number_hash);
        $this->assertSame('lhie-test', data_get($payment->provider_payload, 'payment.project'));
        $this->assertSame($payment->merchant_ref, data_get($payment->provider_payload, 'payment.order_id'));
        $this->assertNull(data_get($payment->provider_payload, 'payment.payment_number'));
        $this->assertSame(2, ProductStockItem::where('product_id', $product->id)->whereNotNull('order_item_id')->count());
        $this->assertSame(2, $product->fresh()->reserved_stock);
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Reservation)->count());

        $expectedQrDataUri = 'data:image/png;base64,'.base64_encode('png-for:'.$paymentNumber);
        $this->get($location)
            ->assertOk()
            ->assertSee('Total bayar QRIS')
            ->assertSee('Rp30.103')
            ->assertSee($expectedQrDataUri, false)
            ->assertSee('Data akun aman sampai pembayaran masuk')
            ->assertDontSee('first-account@example.test|first-secret', false)
            ->assertDontSee('second-account@example.test|second-secret', false);

        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://app.pakasir.test/api/transactioncreate/qris'
            && $request['project'] === 'lhie-test'
            && $request['order_id'] === $payment->merchant_ref
            && $request['amount'] === 30000
            && $request['api_key'] === 'secret-key');
    }

    public function test_shop_checkout_accepts_non_telegram_buyer_name(): void
    {
        $product = Product::factory()->create([
            'name' => 'Claude Team Web',
            'price_amount' => 20000,
            'stock' => 2,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        [$response, $order] = $this->startNativeQrisCheckout(
            product: $product,
            quantity: 1,
            buyerHandle: 'Budi Store',
            paymentNumber: '00020101021226610016ID.CO.QRIS.BUDISTORE99',
        );

        $this->assertRedirectsToSignedOrderShow($response->headers->get('Location'), $order);
        $this->assertDatabaseHas('customers', [
            'id' => $order->customer_id,
            'telegram_id' => null,
            'username' => null,
            'first_name' => 'Budi Store',
        ]);
        $this->assertSame(1, Order::count());
        $this->assertSame(1, Payment::count());
    }

    public function test_shop_checkout_fails_closed_when_native_qris_is_not_enabled(): void
    {
        $product = Product::factory()->create([
            'name' => 'No Native QRIS',
            'stock' => 3,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        Http::fake();

        $response = $this
            ->from(route('storefront.shop.index'))
            ->post(route('storefront.shop.checkout'), [
                'product_id' => $product->id,
                'quantity' => 1,
                'buyer_handle' => '@buyer_test',
            ]);

        $response
            ->assertRedirect(route('storefront.shop.index'))
            ->assertInvalid(['product_id' => 'QRIS otomatis belum aktif. Hubungi admin atau coba lagi nanti.']);
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(0, Customer::count());
        $this->assertSame(0, Cart::count());
        $this->assertSame(0, CartItem::count());
        $this->assertSame(0, $product->fresh()->reserved_stock);
        Http::assertNothingSent();
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

            $response
                ->assertRedirect(route('storefront.shop.index'))
                ->assertInvalid(['product_id' => $message]);
            $this->assertNoLocalCheckoutRowsCreated($case);
            $this->assertSame(3, $product->fresh()->stock, $case);
            $this->assertSame(0, $product->fresh()->reserved_stock, $case);
            Http::assertNothingSent();
        }
    }

    public function test_failed_native_qris_create_releases_reserved_stock_and_credentials(): void
    {
        $this->configurePakasirApiQrisMode();
        $product = Product::factory()->create([
            'name' => 'Credential Native QRIS',
            'stock' => 1,
            'reserved_stock' => 0,
            'price_amount' => 33000,
            'is_active' => true,
        ]);
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

        $response
            ->assertRedirect(route('storefront.shop.index'))
            ->assertInvalid(['product_id' => 'QRIS Pakasir belum bisa dibuat. Stok sudah dilepas; coba lagi beberapa menit.']);

        $order = Order::with(['payments', 'orderItems'])->firstOrFail();
        $payment = $order->payments->first();

        $this->assertSame(OrderStatus::Cancelled, $order->order_status);
        $this->assertSame(PaymentStatus::Failed, $order->payment_status);
        $this->assertNotNull($payment);
        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame(1, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertNull($stockItem->fresh()->order_item_id);
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Reservation)->count());
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Release)->count());
        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://app.pakasir.test/api/transactioncreate/qris');
    }

    public function test_paid_shop_order_reveals_allocated_account_on_signed_order_page(): void
    {
        Queue::fake();
        $product = Product::factory()->create([
            'name' => 'Allocated Account Product',
            'price_amount' => 42000,
            'stock' => 1,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        ProductStockItem::create([
            'product_id' => $product->id,
            'secret' => 'account@example.test|pass',
        ]);
        [$response, $order, $payment] = $this->startNativeQrisCheckout(
            product: $product,
            quantity: 1,
            buyerHandle: '@paid_buyer',
            paymentNumber: '00020101021226610016ID.CO.QRIS.PAIDREVEAL99',
        );
        $this->assertRedirectsToSignedOrderShow($response->headers->get('Location'), $order);

        app(OrderStateMachine::class)->markPaymentPaid($payment);

        $this->get(URL::signedRoute('storefront.shop.orders.show', $order))
            ->assertOk()
            ->assertSee('Data akun')
            ->assertSee('Allocated Account Product')
            ->assertSee('account@example.test|pass', false)
            ->assertSee('Panduan login & keamanan');
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
    }

    public function test_shop_status_endpoint_reconciles_completed_pakasir_payment(): void
    {
        Queue::fake();
        $product = Product::factory()->create([
            'name' => 'Status Reconcile Product',
            'price_amount' => 73000,
            'stock' => 1,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        [, $order, $payment] = $this->startNativeQrisCheckout(
            product: $product,
            quantity: 1,
            buyerHandle: 'Status Buyer',
            paymentNumber: '00020101021226610016ID.CO.QRIS.STATUSPAID99',
            fee: 500,
        );
        Http::fake([
            'https://app.pakasir.test/api/transactiondetail*' => Http::response([
                'transaction' => [
                    'project' => 'lhie-test',
                    'order_id' => $payment->merchant_ref,
                    'amount' => 73000,
                    'payment_method' => 'qris',
                    'status' => 'completed',
                ],
            ], 200),
        ]);

        $redirectUrl = URL::signedRoute('storefront.shop.orders.show', $order);

        $this->postJson(URL::signedRoute('storefront.shop.orders.status', $order))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('payment_status', PaymentStatus::Paid->value)
            ->assertJsonPath('order_status', OrderStatus::Processing->value)
            ->assertJsonPath('is_paid', true)
            ->assertJsonPath('redirect_url', $redirectUrl);

        $payment->refresh();
        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(OrderStatus::Processing, $order->order_status);
        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://app.pakasir.test/api/transactiondetail?project=lhie-test&amount=73000&order_id='.$payment->merchant_ref.'&api_key=secret-key');
    }

    private function startNativeQrisCheckout(Product $product, int $quantity, string $buyerHandle, string $paymentNumber, int $fee = 103): array
    {
        $this->configurePakasirApiQrisMode();
        $this->fakeQrRenderer();
        $this->fakePakasirQrisCreate(
            amount: $product->price_amount * $quantity,
            paymentNumber: $paymentNumber,
            fee: $fee,
        );

        $response = $this
            ->from(route('storefront.shop.index'))
            ->post(route('storefront.shop.checkout'), [
                'product_id' => $product->id,
                'quantity' => $quantity,
                'buyer_handle' => $buyerHandle,
            ]);

        $order = Order::with(['customer', 'cart', 'payments', 'orderItems.stockItems'])->firstOrFail();
        $payment = $order->payments->first();
        $this->assertNotNull($payment);

        return [$response, $order, $payment];
    }

    private function fakePakasirQrisCreate(int $amount, string $paymentNumber, int $fee): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://app.pakasir.test/api/transactioncreate/qris' => function (HttpRequest $request) use ($amount, $paymentNumber, $fee) {
                $this->assertSame('lhie-test', $request['project']);
                $this->assertSame($amount, $request['amount']);
                $this->assertSame('secret-key', $request['api_key']);

                return Http::response([
                    'payment' => [
                        'project' => 'lhie-test',
                        'order_id' => $request['order_id'],
                        'amount' => $amount,
                        'fee' => $fee,
                        'total_payment' => $amount + $fee,
                        'payment_method' => 'qris',
                        'payment_number' => $paymentNumber,
                        'expired_at' => now()->addMinutes(15)->utc()->toAtomString(),
                    ],
                ], 200);
            },
        ]);
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

    private function fakeQrRenderer(): void
    {
        $this->app->instance(TelegramQrCodeRenderer::class, new class extends TelegramQrCodeRenderer
        {
            public function png(string $content): string
            {
                return 'png-for:'.$content;
            }
        });
    }

    private function assertRouteContract(string $name, string $method, string $uri): \Illuminate\Routing\Route
    {
        $route = Route::getRoutes()->getByName($name);

        $this->assertNotNull($route, "Route [{$name}] is not registered.");
        $this->assertSame($uri, $route->uri(), "Route [{$name}] changed URI.");
        $this->assertContains($method, $route->methods(), "Route [{$name}] does not support {$method}.");

        return $route;
    }

    private function assertRedirectsToSignedOrderShow(?string $location, Order $order): void
    {
        $this->assertIsString($location);
        $this->assertSame('/shop/orders/'.$order->getKey(), parse_url($location, PHP_URL_PATH));
        $this->assertStringContainsString('signature=', $location);
        $this->assertTrue(URL::hasValidSignature(Request::create($location, 'GET')));
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

    private function unavailableProductCardHtml(string $html, Product $product): string
    {
        $start = strpos($html, '<article id="product-'.$product->id.'"');
        $this->assertNotFalse($start, 'Expected unavailable product card for product '.$product->id.' to be rendered.');

        $end = strpos($html, '</article>', $start);
        $this->assertNotFalse($end, 'Expected unavailable product card for product '.$product->id.' to be closed.');

        return substr($html, $start, $end + strlen('</article>') - $start);
    }
}
