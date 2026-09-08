<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidCheckout;
use App\Jobs\SendAdminOrderPendingNotification;
use App\Models\CheckoutMaintenanceSetting;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\OrderStateMachine;
use App\Storefront\StorefrontInvoiceUrl;
use App\Storefront\StorefrontShopCheckout;
use App\Storefront\StorefrontShopCheckoutInput;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $orderIdRoute = $this->assertRouteContract('storefront.shop.orders.binance-order-id', 'POST', 'shop/orders/{order}/binance-order-id');
        $this->assertContains('storefront.signed', $orderIdRoute->middleware());
        $this->assertContains('throttle:storefront-binance-order-id', $orderIdRoute->middleware());

        $bscHashRoute = $this->assertRouteContract('storefront.shop.orders.bsc-tx-hash', 'POST', 'shop/orders/{order}/bsc-tx-hash');
        $this->assertContains('storefront.signed', $bscHashRoute->middleware());
        $this->assertContains('throttle:storefront-bsc-tx-hash', $bscHashRoute->middleware());
        $this->assertNotNull(app(RateLimiter::class)->limiter('storefront-bsc-tx-hash'));

        $order = Order::factory()->create();
        $showUrl = URL::signedRoute('storefront.shop.orders.show', $order, null, false);
        $statusUrl = URL::signedRoute('storefront.shop.orders.status', $order, null, false);
        $bscHashUrl = URL::signedRoute('storefront.shop.orders.bsc-tx-hash', $order, null, false);

        $this->assertSame('/shop/orders/'.$order->getKey(), parse_url($showUrl, PHP_URL_PATH));
        $this->assertSame('/shop/orders/'.$order->getKey().'/status', parse_url($statusUrl, PHP_URL_PATH));
        $this->assertSame('/shop/orders/'.$order->getKey().'/bsc-tx-hash', parse_url($bscHashUrl, PHP_URL_PATH));
        $this->assertTrue(URL::hasValidSignature(Request::create($showUrl, 'GET'), false));
        $this->assertTrue(URL::hasValidSignature(Request::create($statusUrl, 'POST'), false));
        $this->assertTrue(URL::hasValidSignature(Request::create($bscHashUrl, 'POST'), false));
    }

    public function test_direct_checkout_url_redirects_to_the_shop_instead_of_returning_method_not_allowed(): void
    {
        $this->get(route('storefront.shop.checkout.start'))
            ->assertRedirect(route('storefront.shop.index'));
    }

    public function test_shop_checkout_rejects_missing_or_false_terms_consent_before_any_side_effect(): void
    {
        $product = $this->availableProduct(['stock' => 1]);
        Http::fake();

        foreach ([null, false] as $termsAccepted) {
            $payload = [
                'product_id' => $product->id,
                'quantity' => 1,
                'buyer_name' => 'buyer_test',
            ];
            if ($termsAccepted !== null) {
                $payload['terms_accepted'] = $termsAccepted;
            }

            $this->from(route('storefront.home'))
                ->post(route('storefront.shop.checkout'), $payload)
                ->assertRedirect(route('storefront.products.show', ['product' => $product, 'checkout' => 1]))
                ->assertSessionHasErrors('terms_accepted');

            $this->assertNoLocalCheckoutRowsCreated('terms consent rejection');
        }

        Http::assertNothingSent();
    }

    public function test_sakurupiah_checkout_requires_a_whatsapp_phone_before_any_side_effect(): void
    {
        ManualPaymentSetting::create([
            'payment_mode' => 'sakurupiah',
            'is_active' => true,
        ]);
        $product = $this->availableProduct(['stock' => 1]);
        Http::fake();

        $this->from(route('storefront.home'))
            ->post(route('storefront.shop.checkout'), [
                'product_id' => $product->id,
                'quantity' => 1,
                'buyer_name' => 'buyer_test',
                'payment_method' => 'qris',
                'terms_accepted' => true,
            ])
            ->assertRedirect(route('storefront.products.show', ['product' => $product, 'checkout' => 1]))
            ->assertSessionHasErrors([
                'support_contact_channel',
                'support_contact_value',
            ]);

        $this->assertNoLocalCheckoutRowsCreated('missing Sakurupiah customer phone');
        Http::assertNothingSent();
    }

    public function test_checkout_service_rejects_unaccepted_terms_before_any_side_effect(): void
    {
        $product = $this->availableProduct(['stock' => 1]);
        Http::fake();

        try {
            app(StorefrontShopCheckout::class)->start(new StorefrontShopCheckoutInput(
                productId: $product->id,
                quantity: 1,
                buyerName: 'Direct Buyer',
            ));
            $this->fail('Expected checkout without terms consent to be rejected.');
        } catch (InvalidCheckout $exception) {
            $this->assertSame('Setujui Syarat Penggunaan dan Kebijakan Refund untuk melanjutkan checkout.', $exception->getMessage());
        }

        $this->assertNoLocalCheckoutRowsCreated('direct terms consent rejection');
        Http::assertNothingSent();
    }

    public function test_checkout_service_rejects_when_the_current_terms_version_is_not_configured(): void
    {
        $this->manualPaymentModeIsActive();
        config()->set('storefront.policies.terms.version', '');
        $product = $this->availableProduct(['stock' => 1]);
        Http::fake();

        try {
            app(StorefrontShopCheckout::class)->start(new StorefrontShopCheckoutInput(
                productId: $product->id,
                quantity: 1,
                buyerName: 'Unversioned Buyer',
                termsAccepted: true,
            ));
            $this->fail('Expected checkout without a current terms version to be rejected.');
        } catch (InvalidCheckout $exception) {
            $this->assertSame('Checkout terms are not configured.', $exception->getMessage());
        }

        $this->assertNoLocalCheckoutRowsCreated('missing terms version');
        Http::assertNothingSent();
    }

    public function test_web_checkout_snapshots_the_current_terms_version_and_acceptance_time(): void
    {
        $this->manualPaymentModeIsActive();
        config()->set('storefront.policies.terms.version', '2026-08-29-v1');
        $product = $this->availableProduct(['stock' => 1]);
        $before = now()->subSecond();

        $this->postStorefrontShopCheckout($product, 1, 'terms_buyer')->assertRedirect();

        $after = now()->addSecond();
        $order = Order::query()->sole();
        $acceptedAt = $order->terms_accepted_at;

        $this->assertSame('2026-08-29-v1', $order->terms_version);
        $this->assertNotNull($acceptedAt);
        $this->assertTrue($acceptedAt->betweenIncluded($before, $after));

        config()->set('storefront.policies.terms.version', '2026-09-01-v2');
        $order->refresh();

        $this->assertSame('2026-08-29-v1', $order->terms_version);
        $this->assertTrue($order->terms_accepted_at->equalTo($acceptedAt));
    }

    public function test_new_checkout_rejects_retired_pakasir_setting_without_side_effects(): void
    {
        ManualPaymentSetting::create([
            'payment_mode' => 'pakasir',
            'is_active' => true,
        ]);
        $product = $this->availableProduct([
            'price_amount' => 15000,
            'stock' => 1,
        ]);
        Http::fake();

        $this->expectException(InvalidCheckout::class);
        try {
            app(StorefrontShopCheckout::class)->start(new StorefrontShopCheckoutInput(
                productId: $product->id,
                quantity: 1,
                buyerName: 'Retired Provider Buyer',
                termsAccepted: true,
            ));
        } finally {
            $this->assertSame(0, Order::query()->count());
            $this->assertSame(0, Payment::query()->count());
            $this->assertSame(0, Customer::query()->count());
            Http::assertNothingSent();
        }
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
            $this->manualPaymentModeIsActive();
            $product = $this->availableProduct([
                'price_amount' => 15000,
                'stock' => 1,
            ]);

            $result = app(StorefrontShopCheckout::class)->start(new StorefrontShopCheckoutInput(
                productId: $product->id,
                quantity: 1,
                buyerName: $data['input'],
                termsAccepted: true,
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
        $this->manualPaymentModeIsActive();
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
                    termsAccepted: true,
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
                termsAccepted: true,
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
        $this->manualPaymentModeIsActive();
        Http::fake();

        $response = $this
            ->from(route('storefront.shop.index'))
            ->post(route('storefront.shop.checkout'), [
                'product_id' => 999_999,
                'quantity' => 1,
                'buyer_name' => 'buyer_test',
                'terms_accepted' => '1',
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
                'terms_accepted' => '1',
            ]);

        $response
            ->assertRedirect(route('storefront.products.show', ['product' => $product, 'checkout' => 1]))
            ->assertSessionHas('storefront_checkout_error', 'QRIS otomatis belum aktif. Hubungi admin atau coba lagi nanti.')
            ->assertInvalid(['product_id' => 'QRIS otomatis belum aktif. Hubungi admin atau coba lagi nanti.']);

        $this->followingRedirects()
            ->get(route('storefront.products.show', ['product' => $product, 'checkout' => 1]))
            ->assertOk()
            ->assertDontSee('data-checkout-product=', false)
            ->assertSee('Checkout pembayaran belum aktif. Hubungi admin atau coba lagi nanti.');

        $this->assertNoLocalCheckoutRowsCreated('disabled native QRIS from homepage');
        Http::assertNothingSent();
    }

    public function test_landing_catalog_renders_lazy_modal_and_empty_stock_state(): void
    {
        $this->manualPaymentModeIsActive();
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
            ->assertSee('openProductModalFromUrl($el.dataset.modalUrl', false)
            ->assertSee('data-modal-url="'.route('storefront.products.modal', $ready).'"', false)
            ->assertDontSee('data-product=', false)
            ->assertDontSee('JSON.parse($el.dataset.product)', false)
            ->assertSee('Claude Pro Ready')
            ->assertSee('Akun private siap dari dashboard')
            ->assertSee('Rp60.000')
            ->assertSee('Stok: 1')
            ->assertDontSee('Bayar via QR Manual')
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

    public function test_landing_modal_keeps_only_the_website_qris_checkout_action(): void
    {
        $this->manualPaymentModeIsActive();
        $product = $this->availableProduct([
            'name' => 'CapCut Pro Web',
            'stock' => 3,
        ]);

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertDontSee('checkoutManual()', false)
            ->assertDontSee('@click="checkoutManual()"', false)
            ->assertDontSee('Beli lewat Telegram')
            ->assertSee('openProductModalFromUrl($el.dataset.modalUrl', false)
            ->assertSee('data-modal-url="'.route('storefront.products.modal', $product).'"', false)
            ->assertDontSee('data-product=', false)
            ->assertSee('action="'.route('storefront.shop.checkout').'"', false)
            ->assertSee('method="POST"', false)
            ->assertDontSee('Bayar via QR Manual');
    }

    public function test_landing_modal_renders_accessible_native_checkout_controls(): void
    {
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_pay_enabled' => true,
            'is_active' => true,
            'binance_verification_mode' => 'order_id',
            'binance_order_id_compatibility_ready_at' => now(),
        ]);
        config()->set('services.binance.pay_id', '987654321');
        $product = $this->availableProduct([
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
            ->assertSee('name="payment_choice"', false)
            ->assertSee('type="hidden" name="payment_method"', false)
            ->assertSee('data-modal-url="'.route('storefront.products.modal', $product).'"', false)
            ->assertDontSee('data-product=', false)
            ->assertDontSee('Bayar via QR Manual');
    }

    public function test_failed_checkout_reopens_modal_state_and_renders_inline_buyer_error(): void
    {
        $this->manualPaymentModeIsActive();
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
                'terms_accepted' => '1',
                'payment_method' => 'qris',
            ])
            ->assertOk()
            ->assertSee('data-checkout-restore=', false)
            ->assertSee('Retry Checkout Product')
            ->assertSee('id="storefront-buyer-name-error"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('aria-describedby="storefront-buyer-name-help storefront-buyer-name-error"', false);
    }

    public function test_shop_checkout_creates_binance_pay_order_without_creating_a_qris_transaction(): void
    {
        Queue::fake([SendAdminOrderPendingNotification::class]);
        config()->set('services.binance.pay_id', '987654321');
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_pay_enabled' => true,
            'is_active' => true,
            'binance_verification_mode' => 'order_id',
            'binance_order_id_compatibility_ready_at' => now(),
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
            'terms_accepted' => '1',
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
            ->assertSee('PENTING')
            ->assertSee('Kirim tepat 6 USDT, termasuk semua angka desimal.')
            ->assertSee('Jangan membulatkan atau mengubah nominal.')
            ->assertSee('987654321')
            ->assertSee('/payment '.$order->invoice_number)
            ->assertSee('data-copy-text="6 USDT"', false)
            ->assertSee('data-copy-text="987654321"', false)
            ->assertSee('data-copy-text="/payment '.$order->invoice_number.'"', false)
            ->assertSee('data-copy-status', false);
    }

    public function test_binance_auto_verification_checkout_highlights_the_exact_product_amount(): void
    {
        Queue::fake([SendAdminOrderPendingNotification::class]);
        config()->set('services.binance.pay_id', '987654321');
        config()->set('services.binance.api_key', 'read-only-key');
        config()->set('services.binance.api_secret', 'read-only-secret');
        config()->set('services.binance.verification_mode', 'order_id');
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_pay_enabled' => true,
            'is_active' => true,
            'binance_verification_mode' => 'order_id',
            'binance_order_id_compatibility_ready_at' => now(),
        ]);
        $product = $this->availableProduct([
            'usdt_price_amount' => 3_000_000,
            'stock' => 1,
        ]);

        $response = $this->post(route('storefront.shop.checkout'), [
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'automatic_binance_buyer',
            'terms_accepted' => '1',
            'payment_method' => 'binance_pay',
        ]);

        $order = Order::with('payments')->sole();
        $payment = $order->payments->sole();
        $this->assertTrue($payment->binance_auto_verification_enabled);
        $this->assertSame(3_000_000, $payment->amount);

        $this->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee('PENTING')
            ->assertSee('Kirim tepat 3 USDT, termasuk semua angka desimal.')
            ->assertSee('Jangan membulatkan atau mengubah nominal.')
            ->assertSee('Setelah membayar, kirim Binance Pay Order ID.', false)
            ->assertSee('Buka pembayaran selesai di Binance Pay, lihat Order Details, lalu salin Order ID persis.', false)
            ->assertDontSee('/payment '.$order->invoice_number);
    }

    public function test_binance_amount_unique_auto_verification_does_not_route_to_screenshot_proof(): void
    {
        Queue::fake([SendAdminOrderPendingNotification::class]);
        config()->set('services.binance.pay_id', '987654321');
        config()->set('services.binance.api_key', 'read-only-key');
        config()->set('services.binance.api_secret', 'read-only-secret');
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_pay_enabled' => true,
            'is_active' => true,
            'binance_verification_mode' => 'amount_unique',
        ]);
        $product = $this->availableProduct([
            'usdt_price_amount' => 3_000_000,
            'stock' => 1,
        ]);

        $response = $this->post(route('storefront.shop.checkout'), [
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'automatic_amount_buyer',
            'terms_accepted' => '1',
            'payment_method' => 'binance_pay',
        ]);

        $order = Order::with('payments')->sole();
        $payment = $order->payments->sole();
        $this->assertTrue($payment->binance_auto_verification_enabled);
        $this->assertSame('amount_unique', $payment->binance_verification_mode);

        $this->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee('Mengecek pembayaran otomatis')
            ->assertSee('Kami akan mengecek riwayat Binance untuk nominal dan penerima yang tepat.')
            ->assertDontSee('Setelah membayar, kirim Binance Pay Order ID.')
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
            'terms_accepted' => '1',
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

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('openProductModalFromUrl($el.dataset.modalUrl', false)
            ->assertSee('data-modal-url="'.route('storefront.products.modal', $product).'"', false)
            ->assertDontSee('data-product=', false);
        $settings->update(['binance_pay_enabled' => false]);

        $this->post(route('storefront.shop.checkout'), [
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'buyer_test',
            'payment_method' => 'binance_pay',
            'terms_accepted' => '1',
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
            'terms_accepted' => '1',
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
        $this->manualPaymentModeIsActive();
        $payload = [
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'buyer_test',
            'terms_accepted' => '1',
        ];
        $session = ['storefront.checkout_client_key' => 'cancel-retry-session'];

        $first = $this->withSession($session)->post(route('storefront.shop.checkout'), $payload);
        $old = Order::sole();
        $firstOrder = $old->id;
        $this->post(app(StorefrontInvoiceUrl::class)->cancel($old));
        $old->refresh();

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
        $this->manualPaymentModeIsActive();

        $payload = [
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'buyer_test',
            'terms_accepted' => '1',
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

    public function test_repeated_web_checkout_reuses_a_completed_order_while_maintenance_is_active(): void
    {
        Queue::fake([SendAdminOrderPendingNotification::class]);
        $product = $this->availableProduct([
            'name' => 'Completed Idempotent Web Checkout',
            'price_amount' => 15000,
            'stock' => 2,
        ]);
        $this->manualPaymentModeIsActive();

        $payload = [
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'buyer_test',
            'terms_accepted' => '1',
        ];
        $session = ['storefront.checkout_client_key' => 'completed-browser-session'];
        $first = $this->withSession($session)
            ->from(route('storefront.home'))
            ->post(route('storefront.shop.checkout'), $payload);
        $order = Order::sole();
        $order->forceFill([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ])->save();
        $order->payments()->sole()->update(['status' => PaymentStatus::Paid]);
        CheckoutMaintenanceSetting::query()->firstOrFail()->update([
            'is_active' => true,
            'version' => 1,
            'activated_at' => now(),
        ]);

        $second = $this->withSession($session)
            ->from(route('storefront.home'))
            ->post(route('storefront.shop.checkout'), $payload);

        $first->assertRedirect();
        $second->assertRedirect($first->headers->get('Location'));
        $this->assertSame(1, Order::count());
        $this->assertSame(1, Payment::count());
        Queue::assertPushed(SendAdminOrderPendingNotification::class, 1);
    }

    public function test_fresh_web_checkout_succeeds_after_deactivation_with_revalidated_stock(): void
    {
        Queue::fake([SendAdminOrderPendingNotification::class]);
        $product = $this->availableProduct([
            'name' => 'Reopened Checkout Product',
            'price_amount' => 15000,
            'stock' => 0,
            'reserved_stock' => 0,
        ]);
        $this->manualPaymentModeIsActive();
        CheckoutMaintenanceSetting::query()->firstOrFail()->update([
            'is_active' => true,
            'version' => 1,
            'activated_at' => now(),
        ]);

        $blocked = $this->postStorefrontShopCheckout($product, 1, 'buyer_test');
        $blocked
            ->assertRedirect(route('storefront.products.show', ['product' => $product, 'checkout' => 1]))
            ->assertSessionHas('storefront_checkout_error', 'Checkout sedang dalam maintenance. Kamu tetap bisa melihat produk, stok, dan mengelola keranjang, tetapi belum dapat membuat pesanan baru. Pesanan yang sudah dibuat tetap diproses. Silakan cek status kembali beberapa saat lagi.');
        $this->assertNoLocalCheckoutRowsCreated('active maintenance before reopening');

        $product->forceFill(['stock' => 1, 'reserved_stock' => 0])->save();
        CheckoutMaintenanceSetting::query()->firstOrFail()->update([
            'is_active' => false,
            'public_note' => null,
            'version' => 2,
            'activated_at' => null,
        ]);

        $reopened = $this->postStorefrontShopCheckout($product, 1, 'buyer_test');

        $reopened->assertRedirect();
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
        $this->manualPaymentModeIsActive();

        $session = ['storefront.checkout_client_key' => 'same-browser-support-contact-session'];
        $basePayload = [
            'product_id' => $product->id,
            'quantity' => 1,
            'buyer_name' => 'buyer_test',
            'terms_accepted' => '1',
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
            'terms_accepted' => '1',
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
            ->assertSee('email : account@example.test', false)
            ->assertSee('password : pass', false)
            ->assertDontSee('account@example.test|pass', false)
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
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
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
