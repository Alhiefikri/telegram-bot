<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidCheckout;
use App\Jobs\SendAdminOrderPendingNotification;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Services\OrderStateMachine;
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
use Tests\Support\StorefrontCheckoutScenarios;
use Tests\Support\StorefrontShopAssertions;
use Tests\TestCase;

class StorefrontShopCheckoutTest extends TestCase
{
    use RefreshDatabase;
    use StorefrontCheckoutScenarios;
    use StorefrontShopAssertions;

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
            $this->pakasirNativeQrisIsReady();
            $this->providerQrisCreateSucceedsWithProviderTotal(
                amount: 15000,
                paymentNumber: '00020101021226610016ID.CO.QRIS.NORMALIZE'.$index,
                fee: 103,
            );
            $product = $this->availableProduct([
                'price_amount' => 15000,
                'stock' => 1,
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
        $this->pakasirNativeQrisIsReady();
        Http::fake();

        $cases = [
            'inactive product' => $this->unavailableProduct([
                'stock' => 1,
                'reserved_stock' => 0,
                'is_active' => false,
            ]),
            'insufficient stock' => $this->unavailableProduct(),
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
        $product = $this->unavailableProduct();

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
        $this->pakasirNativeQrisIsReady();
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
        $ready = $this->availableProduct([
            'name' => 'Claude Pro Ready',
            'description' => 'Akun private siap dari dashboard',
            'price_amount' => 60000,
            'stock' => 1,
        ]);
        $empty = $this->unavailableProduct([
            'name' => 'ChatGPT Habis',
            'price_amount' => 45000,
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
        $this->availableProduct([
            'name' => 'CapCut Pro Web',
            'stock' => 3,
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
        Queue::fake([SendAdminOrderPendingNotification::class]);
        $product = $this->availableProductWithCredentials(
            secrets: [
                'first-account@example.test|first-secret',
                'second-account@example.test|second-secret',
            ],
            attributes: [
                'name' => 'GPT Plus Native QRIS',
                'price_amount' => 15000,
                'stock' => 4,
            ],
        );
        $paymentNumber = '00020101021226610016ID.CO.QRIS.SHOPCHECKOUTNATIVE99';

        [$response, $order, $payment] = $this->successfulNativeQrisCheckout(
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
        Queue::assertPushedOn('telegram-outbound', SendAdminOrderPendingNotification::class, fn (SendAdminOrderPendingNotification $job): bool => $job->orderId === $order->id);

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
        $product = $this->availableProduct([
            'name' => 'Claude Team Web',
            'price_amount' => 20000,
            'stock' => 2,
        ]);

        [$response, $order] = $this->successfulNativeQrisCheckout(
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
        $product = $this->availableProduct([
            'name' => 'No Native QRIS',
            'stock' => 3,
        ]);
        Http::fake();

        $response = $this->postStorefrontShopCheckout($product, 1, '@buyer_test');

        $response
            ->assertRedirect(route('storefront.shop.index'))
            ->assertInvalid(['product_id' => 'QRIS otomatis belum aktif. Hubungi admin atau coba lagi nanti.']);
        $this->assertNoLocalCheckoutRowsCreated('disabled native QRIS');
        $this->assertSame(0, $product->fresh()->reserved_stock);
        Http::assertNothingSent();
    }

    public function test_checkout_rejects_before_local_rows_when_pakasir_native_qris_is_not_available(): void
    {
        $message = 'QRIS otomatis belum aktif. Hubungi admin atau coba lagi nanti.';
        $cases = [
            'missing pakasir credentials' => function (): void {
                $this->pakasirNativeQrisHasMissingCredentials();
            },
            'admin payment mode is manual' => function (): void {
                $this->manualPaymentModeIsActive();
            },
            'admin pakasir flow is hosted' => function (): void {
                $this->pakasirHostedCheckoutIsActive();
            },
        ];

        foreach ($cases as $case => $configure) {
            $this->pakasirPaymentSettingsStartEmpty();
            $configure();
            $product = $this->availableProduct(['stock' => 3]);
            Http::fake();

            $response = $this->postStorefrontShopCheckout($product, 1, 'Web Buyer');

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
        $this->pakasirNativeQrisIsReady();
        $product = $this->availableProductWithCredentials(
            secrets: ['web-checkout@example.test|Secret-123'],
            attributes: [
                'name' => 'Credential Native QRIS',
                'stock' => 1,
                'price_amount' => 33000,
            ],
        );
        $stockItem = ProductStockItem::where('product_id', $product->id)->firstOrFail();
        $this->providerQrisCreateFails();

        $response = $this->postStorefrontShopCheckout($product, 1, '@RetryBuyer');

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
        $product = $this->availableProductWithCredentials(
            secrets: ['account@example.test|pass'],
            attributes: [
                'name' => 'Allocated Account Product',
                'price_amount' => 42000,
                'stock' => 1,
            ],
        );
        [$response, $order, $payment] = $this->successfulNativeQrisCheckout(
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
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
    }

    public function test_shop_status_endpoint_reconciles_completed_pakasir_payment(): void
    {
        Queue::fake();
        $product = $this->availableProduct([
            'name' => 'Status Reconcile Product',
            'price_amount' => 73000,
            'stock' => 1,
        ]);
        [, $order, $payment] = $this->successfulNativeQrisCheckout(
            product: $product,
            quantity: 1,
            buyerHandle: 'Status Buyer',
            paymentNumber: '00020101021226610016ID.CO.QRIS.STATUSPAID99',
            fee: 500,
        );
        $this->providerTransactionDetailCompletesPayment($payment);

        $redirectUrl = URL::signedRoute('storefront.shop.orders.show', $order);

        $this->postJson(URL::signedRoute('storefront.shop.orders.status', $order))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('payment_status', PaymentStatus::Paid->value)
            ->assertJsonPath('order_status', OrderStatus::Completed->value)
            ->assertJsonPath('is_paid', true)
            ->assertJsonPath('redirect_url', $redirectUrl);

        $payment->refresh();
        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(OrderStatus::Completed, $order->order_status);
        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://app.pakasir.test/api/transactiondetail?project=lhie-test&amount=73000&order_id='.$payment->merchant_ref.'&api_key=secret-key');
    }

    private function assertRouteContract(string $name, string $method, string $uri): \Illuminate\Routing\Route
    {
        $route = Route::getRoutes()->getByName($name);

        $this->assertNotNull($route, "Route [{$name}] is not registered.");
        $this->assertSame($uri, $route->uri(), "Route [{$name}] changed URI.");
        $this->assertContains($method, $route->methods(), "Route [{$name}] does not support {$method}.");

        return $route;
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
