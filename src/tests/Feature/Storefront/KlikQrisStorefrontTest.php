<?php

namespace Tests\Feature\Storefront;

use App\Enums\PaymentStatus;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Storefront\StorefrontInvoiceUrl;
use App\Storefront\StorefrontShopCheckout;
use App\Storefront\StorefrontShopCheckoutInput;
use App\Storefront\StorefrontShopSignedInvoice;
use App\Support\PakasirCheckoutFlow;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\Support\StorefrontCheckoutScenarios;
use Tests\TestCase;

class KlikQrisStorefrontTest extends TestCase
{
    use RefreshDatabase;
    use StorefrontCheckoutScenarios;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config()->set('services.storefront.telegram_reservation_floor', 0);
    }

    public function test_checkout_uses_klikqris_sandbox_direct_qris_without_hosted_or_snap_fallback(): void
    {
        KlikQrisSandboxScenarios::configureReadySandbox();
        ManualPaymentSetting::query()->create([
            'payment_mode' => 'klikqris',
            'pakasir_enabled' => false,
            'pakasir_base_url' => null,
            'pakasir_slug' => null,
            'pakasir_api_key' => null,
            'pakasir_qris_enabled' => false,
            'pakasir_checkout_flow' => PakasirCheckoutFlow::HOSTED,
        ]);
        Http::fake([
            'https://klikqris.com/api/sandbox/qris/create' => function (Request $request) {
                return Http::response([
                    'data' => [
                        'order_id' => $request['order_id'],
                        'amount' => 1000,
                        'total_amount' => '1016.00',
                        'status' => 'PENDING',
                        'qris_url' => 'https://klikqris.com/storage/sandbox/qris_storefront.png',
                        'qris_image' => 'SHOULD_NOT_RENDER_BASE64',
                        'expired_at' => now()->addMinutes(15)->toISOString(),
                        'signature' => 'SECRET_SIGNATURE_SHOULD_NOT_RENDER',
                    ],
                ]);
            },
        ]);

        $product = $this->availableProduct(['price_amount' => 1000, 'stock' => 1]);

        $result = app(StorefrontShopCheckout::class)->start(new StorefrontShopCheckoutInput(
            productId: $product->id,
            quantity: 1,
            buyerName: 'Klik Buyer',
        ));

        $order = Order::with('payments')->firstOrFail();
        $payment = $order->payments->first();
        $this->assertSame('klikqris', $payment->provider);
        $this->assertSame(1016, $payment->provider_total_payment);
        $this->assertSame(16, $payment->provider_fee);
        $this->assertNotNull($payment->provider_expires_at);
        $this->assertSame('https://klikqris.com/storage/sandbox/qris_storefront.png', $payment->klikQrisQrisUrl());
        $this->assertStringContainsString('/shop/orders/'.$order->getKey(), $result->redirectUrl);

        $html = $this->get($result->redirectUrl)->assertOk()->getContent();

        $this->assertStringContainsString('https://klikqris.com/storage/sandbox/qris_storefront.png', $html);
        $this->assertStringContainsString('IDR 1016.00', $html);
        $this->assertStringContainsString('data-native-qris-crop="top-square"', $html);
        $this->assertStringContainsString('Batas bayar', $html);
        $this->assertStringNotContainsString('pakasir', strtolower($html));
        $this->assertStringNotContainsString('snap.pay', strtolower($html));
        $this->assertStringNotContainsString('SHOULD_NOT_RENDER_BASE64', $html);
        $this->assertStringNotContainsString('SECRET_SIGNATURE_SHOULD_NOT_RENDER', $html);
    }

    public function test_disabled_klikqris_selector_preserves_existing_pakasir_checkout_selection(): void
    {
        config()->set('services.klikqris.sandbox_enabled', false);
        $this->pakasirNativeQrisIsReady();
        $this->providerQrisCreateSucceedsWithProviderTotal(
            amount: 15000,
            paymentNumber: '00020101021226610016ID.CO.QRIS.PAKASIR.FALLBACK',
            fee: 103,
        );
        $product = $this->availableProduct(['price_amount' => 15000, 'stock' => 1]);

        app(StorefrontShopCheckout::class)->start(new StorefrontShopCheckoutInput(
            productId: $product->id,
            quantity: 1,
            buyerName: 'Pakasir Buyer',
        ));

        $payment = Payment::query()->sole();
        $this->assertSame('pakasir', $payment->provider);
        $this->assertTrue($payment->isPayableNativePakasirQris());
    }

    public function test_signed_invoice_fails_closed_when_persisted_klikqris_display_amount_mismatches_provider_total(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment([
            'amount' => 1000,
            'provider_total_payment' => 1016,
            'provider_expires_at' => now()->addMinutes(15),
            'provider_payload' => ['data' => [
                'total_amount' => '1017.00',
                'qris_url' => 'https://klikqris.com/storage/sandbox/qris_mismatch.png',
            ]],
        ]);

        $invoice = app(StorefrontShopSignedInvoice::class)->read($payment->order);

        $this->assertFalse($invoice->isQrisPayable);
        $this->assertNull($invoice->qrisImageSource);
        $this->assertNull($invoice->payableAmount);
        $this->assertNull($invoice->payableAmountDisplay);
    }

    public function test_storefront_renders_provider_qris_image_fallback_when_klikqris_url_cannot_be_used(): void
    {
        $this->travelTo('2026-08-13 10:10:00 UTC');
        KlikQrisSandboxScenarios::configureReadyProduction();
        app()->detectEnvironment(fn (): string => 'production');

        $qrisUrl = 'https://api.klikqris.example/storage/qris_api/qris_INV-KLIK-STOREFRONT.png';
        $fallbackImage = KlikQrisSandboxScenarios::pngDataUri('storefront-fallback');
        $expiresAt = now()->addMinutes(15)->startOfSecond();
        $payment = KlikQrisSandboxScenarios::klikQrisPayment([
            'merchant_ref' => 'INV-KLIK-STOREFRONT',
            'amount' => 1000,
            'provider_total_payment' => 1016,
            'provider_fee' => 16,
            'provider_expires_at' => $expiresAt,
            'expired_at' => $expiresAt,
            'provider_create_state' => 'created',
            'provider_payload' => ['data' => [
                'order_id' => 'INV-KLIK-STOREFRONT',
                'amount' => 1000,
                'total_amount' => '1016.00',
                'status' => 'PENDING',
                'qris_url' => $qrisUrl,
                'expired_at' => $expiresAt->toISOString(),
            ]],
        ]);

        Http::fake([
            $qrisUrl => Http::response('not-a-png-body', 200, ['Content-Type' => 'image/png']),
            'https://api.klikqris.example/qris/status/INV-KLIK-STOREFRONT' => Http::response(['data' => [
                'order_id' => 'INV-KLIK-STOREFRONT',
                'amount' => 1000,
                'total_amount' => '1016.00',
                'status' => 'PENDING',
                'qris_url' => $qrisUrl,
                'qris_image' => $fallbackImage,
                'expired_at' => $expiresAt->toISOString(),
                'signature' => 'provider-signature',
            ]]),
        ]);

        $invoice = app(StorefrontShopSignedInvoice::class)->read($payment->order);
        $fallbackUrl = app(StorefrontInvoiceUrl::class)->qrisImage($payment->order);

        $this->assertTrue($invoice->isQrisPayable);
        $this->assertSame($qrisUrl, $invoice->qrisImageSource);

        $this->assertStringContainsString('/qris', $fallbackUrl);

        $this->get($invoice->redirectUrl)
            ->assertOk()
            ->assertSee('data-qris-fallback-src="'.e($fallbackUrl).'"', false);

        $imageResponse = $this->get($fallbackUrl);
        $this->assertSame(200, $imageResponse->status());
        $this->assertSame("\x89PNG\r\n\x1A\nstorefront-fallback", $imageResponse->getContent());
    }

    public function test_status_route_uses_provider_neutral_limiter_and_keeps_old_alias_registered(): void
    {
        $statusRoute = Route::getRoutes()->getByName('storefront.shop.orders.status');

        $this->assertContains('throttle:automatic-payment-status-refresh', $statusRoute->middleware());
        $this->assertNotNull(app(RateLimiter::class)->limiter('automatic-payment-status-refresh'));
        $this->assertNotNull(app(RateLimiter::class)->limiter('pakasir-status-refresh'));
    }

    public function test_klikqris_pending_status_refresh_marks_paid_and_single_flight_skips_duplicate_provider_requests(): void
    {
        KlikQrisSandboxScenarios::configureReadySandbox();
        $payment = KlikQrisSandboxScenarios::klikQrisPayment([
            'amount' => 1000,
            'provider_total_payment' => 1016,
            'provider_fee' => 16,
            'provider_payload' => KlikQrisSandboxScenarios::createPayload(Payment::factory()->make(['merchant_ref' => 'INV-KLIK-REFRESH', 'amount' => 1000]), [
                'order_id' => 'INV-KLIK-REFRESH',
                'amount' => 1000,
                'total_amount' => 1016,
                'qris_url' => 'https://klikqris.com/storage/sandbox/qris_refresh.png',
                'expired_at' => now()->addMinutes(15)->toISOString(),
            ]),
            'merchant_ref' => 'INV-KLIK-REFRESH',
            'provider_expires_at' => now()->addMinutes(15),
        ]);
        $order = $payment->order;
        $statusCalls = 0;
        Http::fake([
            'https://klikqris.com/api/sandbox/qris/status/INV-KLIK-REFRESH' => function () use (&$statusCalls, $payment) {
                $statusCalls++;

                return Http::response(KlikQrisSandboxScenarios::statusPayload($payment, [
                    'order_id' => 'INV-KLIK-REFRESH',
                    'amount' => 1000,
                    'total_amount' => 1016,
                    'status' => 'SUCCESS',
                    'signature' => 'SECRET_REFRESH_SIGNATURE',
                ]));
            },
        ]);

        $url = URL::signedRoute('storefront.shop.orders.status', $order, null, false);
        $this->postJson($url)->assertOk()->assertJsonPath('is_paid', true);
        $this->postJson($url)->assertOk()->assertJsonPath('is_paid', true);

        $this->assertSame(1, $statusCalls);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    public function test_klikqris_status_refresh_uses_the_native_qris_payment_lock(): void
    {
        KlikQrisSandboxScenarios::configureReadySandbox();
        $payment = KlikQrisSandboxScenarios::klikQrisPayment([
            'amount' => 1000,
            'provider_total_payment' => 1016,
            'provider_fee' => 16,
            'provider_payload' => KlikQrisSandboxScenarios::createPayload(Payment::factory()->make([
                'merchant_ref' => 'INV-KLIK-LOCKED-REFRESH',
                'amount' => 1000,
            ]), [
                'order_id' => 'INV-KLIK-LOCKED-REFRESH',
                'amount' => 1000,
                'total_amount' => 1016,
                'qris_url' => 'https://klikqris.com/storage/sandbox/qris_locked_refresh.png',
                'expired_at' => now()->addMinutes(15)->toISOString(),
            ]),
            'merchant_ref' => 'INV-KLIK-LOCKED-REFRESH',
            'provider_expires_at' => now()->addMinutes(15),
        ]);
        $lock = Cache::lock(app(KlikQrisNativeQrisService::class)->paymentLockKey($payment), 30);
        $this->assertTrue($lock->get());
        Http::fake();

        try {
            $response = $this->postJson(URL::signedRoute('storefront.shop.orders.status', $payment->order, null, false));
        } finally {
            $lock->release();
        }

        $response->assertStatus(202)->assertJsonPath('status', 'in_progress');
        Http::assertNothingSent();
    }

    public function test_klikqris_provider_failure_returns_retryable_json_without_details(): void
    {
        KlikQrisSandboxScenarios::configureReadySandbox();
        $payment = KlikQrisSandboxScenarios::klikQrisPayment([
            'amount' => 1000,
            'provider_total_payment' => 1016,
            'provider_payload' => KlikQrisSandboxScenarios::createPayload(Payment::factory()->make(['merchant_ref' => 'INV-KLIK-FAIL', 'amount' => 1000]), [
                'order_id' => 'INV-KLIK-FAIL',
                'qris_url' => 'https://klikqris.com/storage/sandbox/qris_fail.png',
                'expired_at' => now()->addMinutes(15)->toISOString(),
            ]),
            'merchant_ref' => 'INV-KLIK-FAIL',
            'provider_expires_at' => now()->addMinutes(15),
        ]);
        Http::fake(['https://klikqris.com/api/sandbox/qris/status/INV-KLIK-FAIL' => Http::response(['api_key' => 'leaked', 'signature' => 'leaked'], 500)]);

        $json = $this->postJson(URL::signedRoute('storefront.shop.orders.status', $payment->order, null, false))
            ->assertOk()
            ->assertJsonPath('status', 'provider_failure')
            ->getContent();

        $this->assertStringNotContainsString('SECRET_FAIL_SIGNATURE', $json);
        $this->assertStringNotContainsString('api_key', $json);
        $this->assertStringNotContainsString('signature', $json);
    }

    public function test_storefront_base64_only_create_does_not_render_missing_or_unsafe_media(): void
    {
        KlikQrisSandboxScenarios::configureReadySandbox();
        ManualPaymentSetting::query()->create([
            'payment_mode' => 'klikqris',
            'pakasir_enabled' => false,
            'pakasir_checkout_flow' => PakasirCheckoutFlow::HOSTED,
        ]);
        $base64 = KlikQrisSandboxScenarios::pngDataUri('storefront-base64-only');
        Http::fake([
            'https://klikqris.com/api/sandbox/qris/create' => function (Request $request) use ($base64) {
                return Http::response(['data' => [
                    'order_id' => $request['order_id'],
                    'amount' => 1000,
                    'total_amount' => 1016,
                    'status' => 'PENDING',
                    'qris_url' => null,
                    'qris_image' => $base64,
                    'signature' => 'STORE_TOKEN_SHOULD_NOT_RENDER',
                    'merchant_id' => 'MERCHANT_SECRET_STOREFRONT',
                    'expired_at' => now()->addMinutes(15)->toISOString(),
                ]]);
            },
        ]);
        $product = $this->availableProduct(['price_amount' => 1000, 'stock' => 1]);

        $result = app(StorefrontShopCheckout::class)->start(new StorefrontShopCheckoutInput(
            productId: $product->id,
            quantity: 1,
            buyerName: 'Base64 Buyer',
        ));
        $payment = Payment::query()->where('provider', 'klikqris')->firstOrFail();
        $html = $this->get($result->redirectUrl)->assertOk()->getContent();

        $this->assertSame('transient_qris_image', $payment->fresh()->provider_create_state);
        $this->assertFalse($payment->fresh()->isPayableNativeKlikQris());
        $this->assertStringNotContainsString('<img src="data:image/png;base64,', $html);
        $this->assertStringNotContainsString($base64, $html);
        $this->assertStringNotContainsString('STORE_TOKEN_SHOULD_NOT_RENDER', $html);
        $this->assertStringNotContainsString('MERCHANT_SECRET_STOREFRONT', $html);
        $this->assertStringContainsString('QRIS belum siap dibayar', $html);
    }

    public function test_pakasir_status_refresh_still_uses_pakasir_and_manual_or_binance_remain_unsupported(): void
    {
        $this->pakasirNativeQrisIsReady();
        $this->providerQrisCreateSucceedsWithProviderTotal(amount: 1000, paymentNumber: '00020101021226610016ID.CO.QRIS.STATUS.PAKASIR', fee: 103);
        $product = $this->availableProduct(['price_amount' => 1000, 'stock' => 1]);
        app(StorefrontShopCheckout::class)->start(new StorefrontShopCheckoutInput(productId: $product->id, quantity: 1, buyerName: 'Pakasir Status'));
        $pakasirPayment = Payment::query()->where('provider', 'pakasir')->sole();
        Http::fake(['*' => Http::response(['transaction' => ['status' => 'completed', 'amount' => 1103, 'order_id' => $pakasirPayment->merchant_ref]], 200)]);

        $this->postJson(URL::signedRoute('storefront.shop.orders.status', $pakasirPayment->order, null, false))->assertOk();
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'pakasir'));

        foreach (['manual', 'binance'] as $provider) {
            $order = Order::factory()->create();
            Payment::factory()->for($order)->create(['provider' => $provider, 'method' => $provider]);
            $this->postJson(URL::signedRoute('storefront.shop.orders.status', $order, null, false))
                ->assertStatus(422);
        }
    }
}
