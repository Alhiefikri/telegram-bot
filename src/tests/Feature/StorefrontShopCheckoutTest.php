<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidCheckout;
use App\Jobs\SendAdminOrderPendingNotification;
use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Services\OrderStateMachine;
use App\Storefront\StorefrontInvoiceUrl;
use App\Storefront\StorefrontShopCheckout;
use App\Storefront\StorefrontShopCheckoutInput;
use App\Support\PakasirCheckoutFlow;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\StorefrontCheckoutScenarios;
use Tests\Support\StorefrontShopAssertions;
use Tests\TestCase;

class StorefrontShopCheckoutTest extends TestCase
{
    use RefreshDatabase;
    use StorefrontCheckoutScenarios;
    use StorefrontShopAssertions;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.storefront.telegram_reservation_floor', 0);
    }

    public function test_shop_named_route_contracts_are_registered(): void
    {
        $this->assertRouteContract('storefront.shop.index', 'GET', 'shop');
        $this->assertRouteContract('storefront.shop.checkout', 'POST', 'shop/checkout');
        $this->assertRouteContract('storefront.shop.checkout.start', 'GET', 'shop/checkout');
        $this->assertRouteContract('storefront.products.show', 'GET', 'produk/{product}');
        $this->assertFalse(Route::has('storefront.products.payment'));
        $this->assertFalse(Route::has('storefront.products.checkout-manual'));

        $showRoute = $this->assertRouteContract('storefront.shop.orders.show', 'GET', 'shop/orders/{order}');
        $statusRoute = $this->assertRouteContract('storefront.shop.orders.status', 'POST', 'shop/orders/{order}/status');

        $this->assertContains('storefront.signed', $showRoute->middleware());
        $this->assertContains('storefront.signed', $statusRoute->middleware());
        $this->assertContains('throttle:automatic-payment-status-refresh', $statusRoute->middleware());
        $this->assertNotNull(app(RateLimiter::class)->limiter('pakasir-status-refresh'));

        $order = Order::factory()->create();
        $showUrl = URL::signedRoute('storefront.shop.orders.show', $order, null, false);
        $statusUrl = URL::signedRoute('storefront.shop.orders.status', $order, null, false);

        $this->assertSame('/shop/orders/'.$order->getKey(), parse_url($showUrl, PHP_URL_PATH));
        $this->assertSame('/shop/orders/'.$order->getKey().'/status', parse_url($statusUrl, PHP_URL_PATH));
        $this->assertTrue(URL::hasValidSignature(Request::create($showUrl, 'GET'), false));
        $this->assertTrue(URL::hasValidSignature(Request::create($statusUrl, 'POST'), false));
    }

    public function test_direct_checkout_url_redirects_to_the_shop_instead_of_returning_method_not_allowed(): void
    {
        $this->get(route('storefront.shop.checkout.start'))
            ->assertRedirect(route('storefront.shop.index'));
    }

    public function test_checkout_module_stores_buyer_name_without_treating_it_as_contact_identity(): void
    {
        $cases = [
            'username shaped name is still only a buyer name' => [
                'input' => 'LhieStore_99',
                'expected_first_name' => 'LhieStore_99',
            ],
            'display name with spaces' => [
                'input' => ' Lia Store ',
                'expected_first_name' => 'Lia Store',
            ],
            'display name with dash' => [
                'input' => 'lia-store',
                'expected_first_name' => 'lia-store',
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
                buyerName: $data['input'],
            ));
            $order = Order::latest('id')->firstOrFail();

            $this->assertRedirectsToSignedOrderShow($result->redirectUrl, $order);
            $this->assertDatabaseHas('customers', [
                'id' => $order->customer_id,
                'telegram_id' => null,
                'username' => null,
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
                    buyerName: 'Web Buyer',
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
                buyerName: 'Web Buyer',
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
                'buyer_name' => 'buyer_test',
            ]);

        $response
            ->assertRedirect(url('/#products'))
            ->assertSessionHas('storefront_checkout_error', 'Produk tidak ditemukan atau sudah tidak tersedia.')
            ->assertInvalid(['product_id' => 'Produk tidak ditemukan atau sudah tidak tersedia.']);
        $this->assertNoLocalCheckoutRowsCreated('missing product');
        Http::assertNothingSent();
    }

    public function test_shop_checkout_failure_from_homepage_redirects_to_selected_product_with_visible_error(): void
    {
        $product = $this->availableProduct([
            'name' => 'Disabled Native QRIS Notice',
            'stock' => 1,
        ]);
        Http::fake();

        $response = $this
            ->from(route('storefront.home'))
            ->post(route('storefront.shop.checkout'), [
                'product_id' => $product->id,
                'quantity' => 1,
                'buyer_name' => 'buyer_test',
            ]);

        $response
            ->assertRedirect(route('storefront.products.show', ['product' => $product, 'checkout' => 1]))
            ->assertSessionHas('storefront_checkout_error', 'QRIS otomatis belum aktif. Hubungi admin atau coba lagi nanti.')
            ->assertInvalid(['product_id' => 'QRIS otomatis belum aktif. Hubungi admin atau coba lagi nanti.']);

        $this->followingRedirects()
            ->get(route('storefront.products.show', ['product' => $product, 'checkout' => 1]))
            ->assertOk()
            ->assertSee('data-checkout-product=', false)
            ->assertSee('QRIS otomatis belum aktif. Hubungi admin atau coba lagi nanti.');

        $this->assertNoLocalCheckoutRowsCreated('disabled native QRIS from homepage');
        Http::assertNothingSent();
    }

    public function test_landing_catalog_renders_ready_modal_and_empty_stock_state(): void
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

        $response = $this->get(route('storefront.home'));
        $emptyCard = $this->unavailableProductCardHtml($response->getContent(), $empty);

        $response
            ->assertOk()
            ->assertSee('Pilih produk digital sesuai kebutuhan.')
            ->assertSee('openProductModal(JSON.parse($el.dataset.product))', false)
            ->assertSee('data-product=', false)
            ->assertSee('Claude Pro Ready')
            ->assertSee('Akun private siap dari dashboard')
            ->assertSee('Rp60.000')
            ->assertSee('Stok: 1')
            ->assertSee('Bayar QRIS di Website')
            ->assertDontSee('Beli lewat Telegram')
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

    public function test_landing_modal_uses_only_the_website_qris_checkout_action(): void
    {
        $this->availableProduct([
            'name' => 'CapCut Pro Web',
            'stock' => 3,
        ]);

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertDontSee('checkoutManual()', false)
            ->assertDontSee('@click="checkoutManual()"', false)
            ->assertDontSee('Beli lewat Telegram')
            ->assertSee('action="'.route('storefront.shop.checkout').'"', false)
            ->assertSee('method="POST"', false)
            ->assertSee('Bayar QRIS di Website');
    }

    public function test_landing_modal_renders_accessible_native_checkout_controls(): void
    {
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_pay_enabled' => true,
            'is_active' => true,
        ]);
        config()->set('services.binance.pay_id', '987654321');
        $this->availableProduct([
            'name' => 'Accessible Checkout Product',
            'stock' => 3,
            'usdt_price_amount' => 2_000_000,
        ]);

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('role="dialog"', false)
            ->assertSee('aria-modal="true"', false)
            ->assertSee('aria-labelledby="modal-title"', false)
            ->assertSee('id="modal-title"', false)
            ->assertSee('x-ref="dialogPanel"', false)
            ->assertSee('@keydown="handleModalKeydown($event)"', false)
            ->assertSee('type="radio"', false)
            ->assertSee('name="payment_method"', false)
            ->assertSee('Total produk di atas belum termasuk biaya QRIS. Biaya dan total QRIS final ditentukan provider setelah QRIS dibuat. Bayar hanya nominal total pada invoice QRIS.')
            ->assertDontSee('type="hidden" name="payment_method"', false);
    }

    public function test_failed_checkout_reopens_modal_state_and_renders_inline_buyer_error(): void
    {
        $product = $this->availableProduct([
            'name' => 'Retry Checkout Product',
            'stock' => 3,
        ]);

        $this->followingRedirects()
            ->from(route('storefront.home'))
            ->post(route('storefront.shop.checkout'), [
                'product_id' => $product->id,
                'quantity' => 2,
                'buyer_name' => 'x',
                'payment_method' => 'qris',
            ])
            ->assertOk()
            ->assertSee('data-checkout-restore=', false)
            ->assertSee('Retry Checkout Product')
            ->assertSee('id="storefront-buyer-name-error"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('aria-describedby="storefront-buyer-name-help storefront-buyer-name-error"', false);
    }

    #[Group('postgres-release')]
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
            buyerName: 'buyer_test',
            paymentNumber: $paymentNumber,
        );

        $location = $response->headers->get('Location');
        $this->assertIsString($location);
        $this->assertStringContainsString('/shop/orders/', $location);
        $this->assertStringContainsString('signature=', $location);
        $this->assertSame('/shop/orders/'.$order->getKey(), parse_url($location, PHP_URL_PATH));
        $this->assertTrue(URL::hasValidSignature(Request::create($location, 'GET'), false));

        $this->assertDatabaseHas('customers', [
            'id' => $order->customer_id,
            'telegram_id' => null,
            'username' => null,
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

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'storefront.checkout_opened',
            'subject_type' => Product::class,
            'subject_id' => (string) $product->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'storefront.checkout_submitted',
            'subject_type' => Order::class,
            'subject_id' => (string) $order->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'storefront.qris_created',
            'subject_type' => Payment::class,
            'subject_id' => (string) $payment->id,
        ]);

        $metadata = AuditLog::where('action', 'storefront.qris_created')->where('subject_id', (string) $payment->id)->firstOrFail()->new_values;
        $this->assertSame($order->invoice_number, $metadata['invoice_number']);
        $this->assertSame('pakasir', $metadata['provider']);
        $this->assertSame('checkout_submit', $metadata['source']);
        $this->assertArrayNotHasKey('buyer_name', $metadata);
        $this->assertArrayNotHasKey('support_contact_value', $metadata);
        $this->assertArrayNotHasKey('provider_payment_number', $metadata);
        $this->assertStringNotContainsString($paymentNumber, json_encode($metadata, JSON_THROW_ON_ERROR));
    }

    public function test_shop_checkout_creates_binance_pay_order_without_creating_a_qris_transaction(): void
    {
        Queue::fake([SendAdminOrderPendingNotification::class]);
        config()->set('services.binance.pay_id', '987654321');
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_pay_enabled' => true,
            'is_active' => true,
        ]);
        $product = $this->availableProduct([
            'name' => 'International Checkout Product',
            'price_amount' => 50000,
            'usdt_price_amount' => 3_000_000,
            'stock' => 2,
        ]);
        Http::fake();

        $response = $this->post(route('storefront.shop.checkout'), [
            'product_id' => $product->id,
            'quantity' => 2,
            'buyer_name' => 'international_buyer',
            'payment_method' => 'binance_pay',
        ]);

        $response->assertRedirect();
        $order = Order::with('payments')->sole();
        $payment = $order->payments->sole();
        $this->assertSame('manual', $payment->provider);
        $this->assertSame('BINANCE_PAY_ID', $payment->method);
        $this->assertSame(6_000_000, $payment->amount);
        $this->assertSame('USDT', $payment->currency);
        Http::assertNothingSent();
        Queue::assertPushedOn('telegram-outbound', SendAdminOrderPendingNotification::class, fn (SendAdminOrderPendingNotification $job): bool => $job->orderId === $order->id);

        $this->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee('Binance Pay (USDT)')
            ->assertSee('6 USDT')
            ->assertSee('⚠️ IMPORTANT')
            ->assertSee('Send exactly 6 USDT, including every decimal digit.')
            ->assertSee('Do not round or change the amount.')
            ->assertSee('987654321')
            ->assertSee('/payment '.$order->invoice_number)
            ->assertSee('data-copy-text="6 USDT"', false)
            ->assertSee('data-copy-text="987654321"', false)
            ->assertSee('data-copy-text="/payment '.$order->invoice_number.'"', false)
            ->assertSee('data-copy-status', false);
    }

    public function test_binance_auto_verification_checkout_highlights_the_exact_unique_amount(): void
    {
        Queue::fake([SendAdminOrderPendingNotification::class]);
        config()->set('services.binance.pay_id', '987654321');
        config()->set('services.binance.api_key', 'read-only-key');
        config()->set('services.binance.api_secret', 'read-only-secret');
        config()->set('services.binance.unique_amount_min_offset_micro', 321);
        config()->set('services.binance.unique_amount_max_offset_micro', 321);
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_pay_enabled' => true,
            'is_active' => true,
        ]);
        $product = $this->availableProduct([
            'usdt_price_amount' => 3_000_000,
            'stock' => 1,
        ]);

        $response = $this->post(route('storefront.shop.checkout'), [
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'automatic_binance_buyer',
            'payment_method' => 'binance_pay',
        ]);

        $order = Order::with('payments')->sole();
        $payment = $order->payments->sole();
        $this->assertTrue($payment->binance_auto_verification_enabled);
        $this->assertSame(3_000_321, $payment->amount);

        $this->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee('⚠️ IMPORTANT')
            ->assertSee('Send exactly 3.000321 USDT, including every decimal digit.')
            ->assertSee('Do not round or change the amount.')
            ->assertSee("tap “I've Paid”", false)
            ->assertDontSee('/payment '.$order->invoice_number);
    }

    public function test_shop_checkout_rejects_unrecognized_payment_method_before_creating_rows(): void
    {
        $product = $this->availableProduct(['stock' => 1]);

        $this->post(route('storefront.shop.checkout'), [
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'buyer_test',
            'payment_method' => 'manual;DROP TABLE payments',
        ])->assertSessionHasErrors('payment_method');

        $this->assertNoLocalCheckoutRowsCreated('invalid payment method');
    }

    public function test_shop_checkout_rechecks_binance_availability_after_the_payment_option_was_rendered(): void
    {
        config()->set('services.binance.pay_id', '987654321');
        $settings = ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_pay_enabled' => true,
            'is_active' => true,
        ]);
        $product = $this->availableProduct(['usdt_price_amount' => 2_000_000, 'stock' => 1]);

        $this->get(route('storefront.home'))->assertOk()->assertSee('Binance Pay');
        $settings->update(['binance_pay_enabled' => false]);

        $this->post(route('storefront.shop.checkout'), [
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'buyer_test',
            'payment_method' => 'binance_pay',
        ])->assertRedirect(route('storefront.products.show', ['product' => $product, 'checkout' => 1]))
            ->assertSessionHas('storefront_checkout_error', 'Binance Pay belum tersedia untuk produk ini.');

        $this->assertNoLocalCheckoutRowsCreated('disabled Binance Pay after render');
    }

    public function test_repeated_binance_checkout_from_the_same_session_reuses_the_existing_order(): void
    {
        Queue::fake([SendAdminOrderPendingNotification::class]);
        config()->set('services.binance.pay_id', '987654321');
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_pay_enabled' => true,
            'is_active' => true,
        ]);
        $product = $this->availableProduct(['usdt_price_amount' => 2_000_000, 'stock' => 2]);
        $payload = [
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'buyer_test',
            'payment_method' => 'binance_pay',
        ];

        $first = $this->withSession(['storefront.checkout_client_key' => 'same-binance-browser-session'])
            ->post(route('storefront.shop.checkout'), $payload);
        $second = $this->withSession(['storefront.checkout_client_key' => 'same-binance-browser-session'])
            ->post(route('storefront.shop.checkout'), $payload);

        $first->assertRedirect();
        $second->assertRedirect($first->headers->get('Location'));
        $this->assertSame(1, Order::count());
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, $product->fresh()->reserved_stock);
        Queue::assertPushed(SendAdminOrderPendingNotification::class, 1);
    }

    public function test_cancelled_checkout_can_be_retried_with_identical_payload_and_session(): void
    {
        Queue::fake([SendAdminOrderPendingNotification::class]);
        $product = $this->availableProduct(['name' => 'Retryable Cancelled Checkout', 'price_amount' => 15000, 'stock' => 3]);
        $this->pakasirNativeQrisIsReady();
        $this->providerQrisCreateSucceedsWithProviderTotal(15000, '00020101021226610016ID.CO.QRIS.RETRY99');
        $payload = ['product_id' => $product->id, 'quantity' => 1, 'buyer_name' => 'buyer_test'];
        $session = ['storefront.checkout_client_key' => 'cancel-retry-session'];

        $first = $this->withSession($session)->post(route('storefront.shop.checkout'), $payload);
        $old = Order::sole();
        $firstOrder = $old->id;
        $this->providerTransactionDetailIsPending($old->payments()->firstOrFail());
        $this->post(app(StorefrontInvoiceUrl::class)->cancel($old));
        $old->refresh();

        $this->providerQrisCreateSucceedsWithProviderTotal(15000, '00020101021226610016ID.CO.QRIS.RETRY100');
        $second = $this->withSession($session)->post(route('storefront.shop.checkout'), $payload);
        $new = Order::whereKeyNot($old->id)->sole();

        $first->assertRedirect();
        $second->assertRedirect(app(StorefrontInvoiceUrl::class)->show($new));
        $this->assertSame(2, Order::count());
        $this->assertSame(OrderStatus::Cancelled, $old->fresh()->order_status);
        $this->assertSame(PaymentStatus::Failed, $old->fresh()->payment_status);
        $this->assertNull($old->fresh()->checkout_key);
        $this->assertSame(OrderStatus::PendingPayment, $new->order_status);
        $this->assertSame(PaymentStatus::Pending, $new->payment_status);
        $this->assertNotSame($firstOrder, $new->id);
    }

    public function test_repeated_web_checkout_from_the_same_session_reuses_the_existing_order(): void
    {
        Queue::fake([SendAdminOrderPendingNotification::class]);
        $product = $this->availableProduct([
            'name' => 'Idempotent Web Checkout',
            'price_amount' => 15000,
            'stock' => 2,
        ]);
        $this->pakasirNativeQrisIsReady();
        $this->providerQrisCreateSucceedsWithProviderTotal(
            amount: 15000,
            paymentNumber: '00020101021226610016ID.CO.QRIS.IDEMPOTENT99',
        );

        $payload = [
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'buyer_test',
        ];
        $first = $this->withSession(['storefront.checkout_client_key' => 'same-browser-session'])
            ->from(route('storefront.home'))
            ->post(route('storefront.shop.checkout'), $payload);
        $second = $this->withSession(['storefront.checkout_client_key' => 'same-browser-session'])
            ->from(route('storefront.home'))
            ->post(route('storefront.shop.checkout'), $payload);

        $first->assertRedirect();
        $second->assertRedirect($first->headers->get('Location'));
        $this->assertSame(1, Order::count());
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, $product->fresh()->reserved_stock);
        Queue::assertPushed(SendAdminOrderPendingNotification::class, 1);
    }

    public function test_repeated_checkout_updates_optional_support_contact_without_creating_a_new_order(): void
    {
        Queue::fake([SendAdminOrderPendingNotification::class]);
        $product = $this->availableProduct([
            'name' => 'Support Contact Idempotent Checkout',
            'price_amount' => 15000,
            'stock' => 2,
        ]);
        $this->pakasirNativeQrisIsReady();
        $this->providerQrisCreateSucceedsWithProviderTotal(
            amount: 15000,
            paymentNumber: '00020101021226610016ID.CO.QRIS.CONTACTIDEMPOTENT99',
        );

        $session = ['storefront.checkout_client_key' => 'same-browser-support-contact-session'];
        $basePayload = [
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'buyer_test',
        ];
        $first = $this->withSession($session)
            ->from(route('storefront.home'))
            ->post(route('storefront.shop.checkout'), $basePayload + [
                'support_contact_channel' => 'telegram',
                'support_contact_value' => '@buyer_test',
            ]);
        $second = $this->withSession($session)
            ->from(route('storefront.home'))
            ->post(route('storefront.shop.checkout'), $basePayload + [
                'support_contact_channel' => 'whatsapp',
                'support_contact_value' => '+62 812-0000-0000',
            ]);

        $first->assertRedirect();
        $second->assertRedirect($first->headers->get('Location'));
        $order = Order::sole();
        $this->assertSame('whatsapp', $order->support_contact_channel);
        $this->assertSame('+6281200000000', $order->support_contact_value);
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, $product->fresh()->reserved_stock);
        Queue::assertPushed(SendAdminOrderPendingNotification::class, 1);
    }

    public function test_repeated_failed_qris_checkout_keeps_the_original_recovery_key_hash(): void
    {
        $product = $this->availableProduct([
            'name' => 'Idempotent Failed QRIS Checkout',
            'price_amount' => 15000,
            'stock' => 2,
        ]);
        $this->pakasirNativeQrisIsReady();
        $this->providerQrisCreateFails();
        $payload = [
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'buyer_test',
        ];

        $first = $this->withSession(['storefront.checkout_client_key' => 'same-failed-qris-browser-session'])
            ->from(route('storefront.home'))
            ->post(route('storefront.shop.checkout'), $payload);
        $hash = Order::sole()->credential_access_key_hash;
        $second = $this->withSession(['storefront.checkout_client_key' => 'same-failed-qris-browser-session'])
            ->from(route('storefront.home'))
            ->post(route('storefront.shop.checkout'), $payload);

        $first->assertRedirect();
        $second->assertRedirect($first->headers->get('Location'));
        $this->assertNotEmpty($hash);
        $this->assertSame($hash, Order::sole()->credential_access_key_hash);
        $this->assertSame(1, Order::count());
        $this->assertSame(1, Payment::count());
    }

    public function test_checkout_with_incomplete_provider_total_blocks_payment_and_reuses_original_order(): void
    {
        $product = $this->availableProduct([
            'name' => 'Incomplete QRIS Total Checkout',
            'price_amount' => 15000,
            'stock' => 2,
        ]);
        $this->pakasirNativeQrisIsReady();
        $paymentNumber = '00020101021226610016ID.CO.QRIS.INCOMPLETECREATE99';
        $this->providerQrisCreateSucceedsWithoutProviderTotal(15000, $paymentNumber);
        $payload = [
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'buyer_test',
        ];

        $first = $this->withSession(['storefront.checkout_client_key' => 'same-incomplete-qris-session'])
            ->from(route('storefront.home'))
            ->post(route('storefront.shop.checkout'), $payload);
        $order = Order::with('payments')->sole();
        $payment = $order->payments->sole();

        $first->assertRedirect();
        $this->assertSame('provider_total_pending', $payment->provider_create_state);
        $this->assertNull($payment->provider_total_payment);
        $this->assertSame($paymentNumber, $payment->provider_payment_number);

        $this->providerTransactionDetailFails();
        $second = $this->withSession(['storefront.checkout_client_key' => 'same-incomplete-qris-session'])
            ->from(route('storefront.home'))
            ->post(route('storefront.shop.checkout'), $payload);

        $second->assertRedirect($first->headers->get('Location'));
        $this->assertSame(1, Order::count());
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, $product->fresh()->reserved_stock);

        $this->get($first->headers->get('Location'))
            ->assertOk()
            ->assertSee('Total produk')
            ->assertSee('Rp15.000')
            ->assertSee('Total bayar QRIS')
            ->assertSee('Menunggu provider')
            ->assertSee('Provider belum memberi nominal')
            ->assertSee('Coba ambil total QRIS')
            ->assertDontSee('data:image/png;base64,'.base64_encode('png-for:'.$paymentNumber), false);
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
            buyerName: 'Budi Store',
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

    public function test_guest_checkout_does_not_require_support_contact(): void
    {
        $product = $this->availableProduct([
            'name' => 'Guest Checkout No Contact',
            'price_amount' => 20000,
            'stock' => 2,
        ]);

        [$response, $order] = $this->successfulNativeQrisCheckout(
            product: $product,
            quantity: 1,
            buyerName: 'Guest Buyer',
            paymentNumber: '00020101021226610016ID.CO.QRIS.GUESTNOCONTACT99',
        );

        $this->assertRedirectsToSignedOrderShow($response->headers->get('Location'), $order);
        $this->assertDatabaseHas('customers', [
            'id' => $order->customer_id,
            'telegram_id' => null,
            'first_name' => 'Guest Buyer',
            'username' => null,
            'email' => null,
        ]);
        $this->assertNull($order->support_contact_channel);
        $this->assertNull($order->support_contact_value);
    }

    public function test_guest_checkout_stores_optional_support_contact_without_making_it_order_ownership(): void
    {
        $product = $this->availableProduct([
            'name' => 'Guest Checkout With Contact',
            'price_amount' => 20000,
            'stock' => 2,
        ]);

        [$response, $order] = $this->successfulNativeQrisCheckout(
            product: $product,
            quantity: 1,
            buyerName: 'Guest Buyer',
            paymentNumber: '00020101021226610016ID.CO.QRIS.GUESTCONTACT99',
            supportContactChannel: 'telegram',
            supportContactValue: ' @support_buyer ',
        );

        $this->assertRedirectsToSignedOrderShow($response->headers->get('Location'), $order);
        $order->refresh();
        $this->assertSame('telegram', $order->support_contact_channel);
        $this->assertSame('@support_buyer', $order->support_contact_value);
        $rawContact = DB::table('orders')->where('id', $order->id)->value('support_contact_value');
        $this->assertIsString($rawContact);
        $this->assertNotSame('@support_buyer', $rawContact);
        $this->assertStringNotContainsString('@support_buyer', $rawContact);
        $this->assertDatabaseHas('customers', [
            'id' => $order->customer_id,
            'telegram_id' => null,
            'first_name' => 'Guest Buyer',
            'username' => null,
            'email' => null,
        ]);
    }

    public function test_checkout_rejects_unapproved_support_contact_channel_before_creating_rows(): void
    {
        $product = $this->availableProduct(['stock' => 1]);

        $this->post(route('storefront.shop.checkout'), [
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'Guest Buyer',
            'support_contact_channel' => 'instagram',
            'support_contact_value' => '@buyer',
        ])->assertSessionHasErrors('support_contact_channel');

        $this->assertNoLocalCheckoutRowsCreated('invalid support contact channel');
    }

    public function test_shop_checkout_signed_invoice_survives_proxy_scheme_or_host_mismatch(): void
    {
        $product = $this->availableProduct([
            'name' => 'Proxy Signed QRIS',
            'price_amount' => 30000,
            'stock' => 1,
        ]);

        [$response] = $this->successfulNativeQrisCheckout(
            product: $product,
            quantity: 1,
            buyerName: 'proxy_buyer',
            paymentNumber: '00020101021226610016ID.CO.QRIS.PROXYMISMATCH99',
        );

        $location = $response->headers->get('Location');
        $this->assertIsString($location);

        $path = parse_url($location, PHP_URL_PATH);
        $query = parse_url($location, PHP_URL_QUERY);
        $this->assertIsString($path);
        $this->assertIsString($query);

        $this->get('http://proxy-observed.example.test'.$path.'?'.$query)
            ->assertOk()
            ->assertSee('Proxy Signed QRIS');
    }

    public function test_shop_order_page_accepts_legacy_absolute_signed_invoice_links_after_relative_signature_migration(): void
    {
        $product = $this->availableProduct([
            'name' => 'Legacy Absolute QRIS',
            'price_amount' => 30000,
            'stock' => 1,
        ]);

        [, $order] = $this->successfulNativeQrisCheckout(
            product: $product,
            quantity: 1,
            buyerName: 'legacy_buyer',
            paymentNumber: '00020101021226610016ID.CO.QRIS.LEGACYABSOLUTE99',
        );

        $legacyUrl = URL::signedRoute('storefront.shop.orders.show', $order);
        $path = parse_url($legacyUrl, PHP_URL_PATH);
        $query = parse_url($legacyUrl, PHP_URL_QUERY);
        $this->assertIsString($path);
        $this->assertIsString($query);
        $this->assertFalse(URL::hasValidSignature(Request::create('https://proxy-observed.example.test'.$path.'?'.$query, 'GET'), false));

        $this->get('https://proxy-observed.example.test'.$path.'?'.$query)
            ->assertOk()
            ->assertSee('Legacy Absolute QRIS');
    }

    public function test_shop_checkout_fails_closed_when_native_qris_is_not_enabled(): void
    {
        $product = $this->availableProduct([
            'name' => 'No Native QRIS',
            'stock' => 3,
        ]);
        Http::fake();

        $response = $this->postStorefrontShopCheckout($product, 1, 'buyer_test');

        $response
            ->assertRedirect(route('storefront.products.show', ['product' => $product, 'checkout' => 1]))
            ->assertSessionHas('storefront_checkout_error', 'QRIS otomatis belum aktif. Hubungi admin atau coba lagi nanti.')
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
                ->assertRedirect(route('storefront.products.show', ['product' => $product, 'checkout' => 1]))
                ->assertInvalid(['product_id' => $message]);
            $this->assertNoLocalCheckoutRowsCreated($case);
            $this->assertSame(3, $product->fresh()->stock, $case);
            $this->assertSame(0, $product->fresh()->reserved_stock, $case);
            Http::assertNothingSent();
        }
    }

    public function test_failed_native_qris_create_keeps_pending_reservation_for_safe_recovery(): void
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

        $response = $this->postStorefrontShopCheckout($product, 1, 'RetryBuyer');

        $response->assertRedirect();

        $order = Order::with(['payments', 'orderItems'])->firstOrFail();
        $payment = $order->payments->first();

        $this->assertSame(OrderStatus::PendingPayment, $order->order_status);
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
        $this->assertNotNull($payment);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame('outcome_unknown', $payment->provider_create_state);
        $this->assertSame(1, $product->fresh()->stock);
        $this->assertSame(1, $product->fresh()->reserved_stock);
        $this->assertNotNull($stockItem->fresh()->order_item_id);
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Reservation)->count());
        $this->assertNotEmpty($order->credential_access_key_hash);
        $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Release)->count());

        $location = $response->headers->get('Location');
        $this->assertIsString($location);
        $this->get($location)
            ->assertOk()
            ->assertSee('Simpan Recovery Key pesanan website ini.')
            ->assertSee('Salin ke password manager atau catatan pribadi yang aman.')
            ->assertSee('data-copy-status', false)
            ->assertSee('LHIE-', false)
            ->assertDontSee('Ambil screenshot atau salin', false)
            ->assertDontSee('web-checkout@example.test|Secret-123', false);

        app(OrderStateMachine::class)->markPaymentPaid($payment);
        $this->flushSession();
        $this->get($location)
            ->assertOk()
            ->assertSee('Data akun siap dibuka')
            ->assertSee('Akses data pesanan')
            ->assertDontSee('web-checkout@example.test|Secret-123', false);

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
                'delivery_note' => 'Ganti password dan MFA setelah login pertama.',
                'usage_guide_title' => 'Panduan penggunaan provider lama',
                'usage_guide_url' => '/provider-lama/cara-pakai',
                'price_amount' => 42000,
                'stock' => 1,
            ],
        );
        [$response, $order, $payment] = $this->successfulNativeQrisCheckout(
            product: $product,
            quantity: 1,
            buyerName: 'paid_buyer',
            paymentNumber: '00020101021226610016ID.CO.QRIS.PAIDREVEAL99',
        );
        $this->assertRedirectsToSignedOrderShow($response->headers->get('Location'), $order);

        $product->update([
            'delivery_note' => 'Catatan baru yang tidak boleh diterima pembeli lama.',
            'usage_guide_title' => 'Panduan provider baru',
            'usage_guide_url' => '/provider-baru/cara-pakai',
        ]);

        app(OrderStateMachine::class)->markPaymentPaid($payment);

        $this->get(URL::signedRoute('storefront.shop.orders.show', $order, null, false))
            ->assertOk()
            ->assertSee('Data akun')
            ->assertSee('Allocated Account Product')
            ->assertSee('account@example.test|pass', false)
            ->assertSee('Simpan pesanan ke akun')
            ->assertSee('invoice plus Recovery Key tetap bisa dipakai')
            ->assertSee('Ganti password dan MFA setelah login pertama.')
            ->assertDontSee('Catatan baru yang tidak boleh diterima pembeli lama.')
            ->assertSee('Panduan penggunaan provider lama')
            ->assertSee(url('/provider-lama/cara-pakai'), false)
            ->assertDontSee('Panduan provider baru')
            ->assertDontSee('/provider-baru/cara-pakai', false);
        $orderItem = $order->orderItems()->sole();
        $this->assertSame('Ganti password dan MFA setelah login pertama.', $orderItem->delivery_note);
        $this->assertTrue($orderItem->delivery_note_snapshotted);
        $this->assertSame('Panduan penggunaan provider lama', $orderItem->usage_guide_title);
        $this->assertSame('/provider-lama/cara-pakai', $orderItem->usage_guide_url);
        $this->assertTrue($orderItem->usage_guide_snapshotted);
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
            buyerName: 'Status Buyer',
            paymentNumber: '00020101021226610016ID.CO.QRIS.STATUSPAID99',
            fee: 500,
        );
        $this->providerTransactionDetailCompletesPayment($payment);

        $redirectUrl = app(StorefrontInvoiceUrl::class)->show($order);

        $this->postJson(URL::signedRoute('storefront.shop.orders.status', $order, null, false))
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
