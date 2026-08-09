<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\TestCase;

class KlikQrisSandboxValidationCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        KlikQrisSandboxScenarios::configureReadySandbox();
        $this->travelTo('2026-07-28 07:00:00');
    }

    public function test_missing_configuration_fails_with_labels_only_and_no_http(): void
    {
        config()->set('services.klikqris.sandbox_enabled', false);
        config()->set('services.klikqris.sandbox_api_key', 'sk_sandbox_secret_key');
        config()->set('services.klikqris.sandbox_merchant_id', 'merchant-secret-id');
        config()->set('services.klikqris.sandbox_base_url', 'https://klikqris.com/api/sandbox');
        Http::fake();

        Artisan::call('klikqris:validate-sandbox', ['payment' => 'missing-payment']);
        $output = Artisan::output();

        $this->assertStringContainsString('FAIL KLIKQRIS_SANDBOX_ENABLED configured - Value is blank or disabled.', $output);
        $this->assertSensitiveDenyListAbsent($output, [
            'sk_sandbox_secret_key',
            'merchant-secret-id',
            'token=secret-query',
            'secret-query',
        ]);

        $this->artisan('klikqris:validate-sandbox', ['payment' => 'missing-payment'])
            ->expectsOutputToContain('FAIL KLIKQRIS_SANDBOX_ENABLED configured - Value is blank or disabled.')
            ->doesntExpectOutputToContain('sk_sandbox_secret_key')
            ->doesntExpectOutputToContain('merchant-secret-id')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_production_environment_refuses_execution_before_payment_or_http(): void
    {
        config()->set('app.env', 'production');
        $payment = KlikQrisSandboxScenarios::klikQrisPayment();
        Http::fake();

        $this->artisan('klikqris:validate-sandbox', ['payment' => $payment->id, '--create' => true])
            ->expectsOutputToContain('FAIL production execution refused - KlikQRIS sandbox validation is never authorized in production.')
            ->assertFailed();

        Http::assertNothingSent();
        $this->assertNull($payment->fresh()->provider_create_state);
    }

    public function test_rejects_non_klikqris_uuid_but_merchant_ref_lookup_selects_klikqris_row(): void
    {
        $sharedMerchantRef = 'SHARED-MERCHANT-REF-11';
        $pakasir = Payment::factory()->create([
            'provider' => 'pakasir',
            'merchant_ref' => $sharedMerchantRef,
            'provider_payment_method' => 'qris',
            'amount' => 12000,
            'status' => PaymentStatus::Pending,
        ]);
        $klikqris = KlikQrisSandboxScenarios::klikQrisPayment([
            'merchant_ref' => $sharedMerchantRef,
            'amount' => 12000,
            'provider_total_payment' => 12016,
            'provider_payment_method' => 'qris',
            'provider_expires_at' => now()->addMinutes(10),
        ]);
        $klikqris->update([
            'provider_payload' => KlikQrisSandboxScenarios::createPayload($klikqris->fresh(), [
                'qris_image' => 'raw-base64-image',
                'expired_at' => now()->addMinutes(10)->toAtomString(),
            ]),
        ]);

        $hash = substr(hash('sha256', $sharedMerchantRef), 0, 12);

        $this->artisan('klikqris:validate-sandbox', ['payment' => $pakasir->id])
            ->expectsOutputToContain('FAIL payment provider is klikqris - Provider is pakasir.')
            ->assertFailed();

        $this->artisan('klikqris:validate-sandbox', ['payment' => $sharedMerchantRef])
            ->expectsOutputToContain('PASS payment provider is klikqris')
            ->expectsOutputToContain('payment_uuid='.$klikqris->id)
            ->expectsOutputToContain('merchant_ref_hash='.$hash)
            ->doesntExpectOutputToContain($sharedMerchantRef)
            ->assertSuccessful();
    }

    public function test_create_and_reconcile_reuse_services_with_secret_safe_success_output(): void
    {
        Queue::fake();
        $order = app(OrderCheckoutService::class)->checkout(
            $this->cartWithProduct(price: 30103),
            CheckoutPaymentIntents::klikQris(now()->addMinutes(30))
        );
        $payment = $order->payments()->sole();
        $payment->update(['merchant_ref' => 'INV-KLIKQRIS-SANDBOX-11']);
        $payment->refresh();
        $apiKey = 'sk_sandbox_secret_key';
        $merchantId = 'merchant-secret-id';
        $signature = 'opaque-test-signature';
        $rawQrisImage = 'iVBORw0KGgoAAAANSUhEUgAAA-secret-base64';
        $qrisUrl = 'https://klikqris.com/storage/sandbox/qris_sandbox_transaction.png?token=secret-query';
        config()->set('services.klikqris.sandbox_api_key', $apiKey);
        config()->set('services.klikqris.sandbox_merchant_id', $merchantId);

        Http::fake(function (Request $request) use ($payment, $signature, $rawQrisImage, $qrisUrl) {
            if ($request->method() === 'POST') {
                return Http::response(KlikQrisSandboxScenarios::createPayload($payment, [
                    'qris_url' => 'https://klikqris.com/storage/sandbox/qris_sandbox_transaction.png',
                    'qris_image' => $rawQrisImage,
                    'expired_at' => now()->addMinutes(15)->toAtomString(),
                    'signature' => $signature,
                    'provider_debug' => ['qris_url_with_query' => $qrisUrl],
                ]));
            }

            return Http::response(KlikQrisSandboxScenarios::statusPayload($payment, [
                'signature' => $signature,
                'provider_debug' => ['qris_url_with_query' => $qrisUrl],
            ]));
        });

        $merchantHash = substr(hash('sha256', (string) $payment->merchant_ref), 0, 12);

        $code = Artisan::call('klikqris:validate-sandbox', [
            'payment' => $payment->id,
            '--create' => true,
            '--reconcile' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('PASS idempotent KlikQRIS create returned stable transaction', $output);
        $this->assertStringContainsString('PASS authoritative status processing marked paid - payment_status=paid event_processed=true.', $output);
        $this->assertStringContainsString('payment_uuid='.$payment->id, $output);
        $this->assertStringContainsString('merchant_ref_hash='.$merchantHash, $output);
        $this->assertStringContainsString('normalized_status=paid', $output);
        $this->assertStringContainsString('total=IDR 30119', $output);
        $this->assertStringContainsString('expires_at=', $output);
        $this->assertStringNotContainsString($apiKey, $output);
        $this->assertStringNotContainsString($merchantId, $output);
        $this->assertStringNotContainsString($signature, $output);
        $this->assertStringNotContainsString($qrisUrl, $output);
        $this->assertStringNotContainsString($rawQrisImage, $output);
        $this->assertStringNotContainsString('provider_debug', $output);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(OrderStatus::Processing, $payment->order->fresh()->order_status);
        $this->assertSame(1, $payment->paymentEvents()->where('event_type', PaymentEventType::ProviderCreate->value)->count());
        $this->assertSame(1, $payment->paymentEvents()->where('event_type', PaymentEventType::Reconciliation->value)->whereNotNull('processed_at')->count());
        Http::assertSentCount(2);
    }

    public function test_create_and_reconcile_accept_an_already_paid_persisted_payment(): void
    {
        $expiresAt = now()->addMinutes(10);
        $payment = KlikQrisSandboxScenarios::klikQrisPayment([
            'amount' => 1000,
            'status' => PaymentStatus::Paid,
            'provider_total_payment' => 1016,
            'provider_fee' => 16,
            'provider_payment_method' => 'qris',
            'provider_expires_at' => $expiresAt,
            'provider_create_state' => 'created',
        ]);
        $payment->update([
            'provider_payload' => KlikQrisSandboxScenarios::createPayload($payment->fresh(), [
                'expired_at' => $expiresAt->toISOString(),
            ]),
        ]);

        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::statusPayload($payment), 200));

        $code = Artisan::call('klikqris:validate-sandbox', [
            'payment' => $payment->id,
            '--create' => true,
            '--reconcile' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('PASS idempotent KlikQRIS create returned stable transaction', $output);
        $this->assertStringContainsString('PASS authoritative status processing marked paid - payment_status=paid event_processed=true.', $output);
        $this->assertSame(1, $payment->paymentEvents()->where('event_type', PaymentEventType::Reconciliation->value)->whereNotNull('processed_at')->count());
        Http::assertSentCount(1);
    }

    public function test_actual_provider_qris_url_with_query_is_rejected_and_not_persisted_as_successful_transaction(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment(['merchant_ref' => 'INV-KLIKQRIS-QUERY-SECRET']);
        $queryQrisUrl = 'https://klikqris.com/storage/sandbox/qris_sandbox_transaction.png?token=secret-query';

        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::createPayload($payment, [
            'qris_url' => $queryQrisUrl,
            'signature' => 'opaque-test-signature',
            'qris_image' => 'iVBORw0KGgoAAAANSUhEUgAAA-secret-base64',
        ])));

        $code = Artisan::call('klikqris:validate-sandbox', [
            'payment' => $payment->id,
            '--create' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('FAIL idempotent KlikQRIS create returned stable transaction', $output);
        $this->assertStringContainsString('FAIL stored KlikQRIS QRIS fields match provider shape', $output);
        $this->assertSensitiveDenyListAbsent($output, [
            $queryQrisUrl,
            'token=secret-query',
            'secret-query',
            'iVBORw0KGgo',
            'raw-base64-image',
            'opaque-test-signature',
            '"qris_url"',
        ]);

        $payment->refresh();
        $this->assertNotSame('created', $payment->provider_create_state);
        $this->assertNull($payment->provider_payload);
    }

    public function test_stored_provider_qris_url_with_fragment_fails_validation_without_leaking_payload_fragments(): void
    {
        $fragmentQrisUrl = 'https://klikqris.com/storage/sandbox/qris_sandbox_transaction.png#secret-fragment';
        $payment = KlikQrisSandboxScenarios::klikQrisPayment([
            'provider_total_payment' => 10016,
            'provider_expires_at' => now()->addMinutes(10),
            'provider_payload' => KlikQrisSandboxScenarios::createPayload(
                KlikQrisSandboxScenarios::klikQrisPayment(['merchant_ref' => 'UNUSED-FRAGMENT-PAYMENT']),
                []
            ),
        ]);
        $payment->update([
            'merchant_ref' => 'INV-KLIKQRIS-FRAGMENT-SECRET',
            'provider_payload' => KlikQrisSandboxScenarios::createPayload($payment->fresh(), [
                'qris_url' => $fragmentQrisUrl,
                'expired_at' => now()->addMinutes(10)->toAtomString(),
                'signature' => 'opaque-test-signature',
                'provider_debug' => ['raw_payload_fragment' => '{"qris_url":"'.$fragmentQrisUrl.'"}'],
            ]),
        ]);

        $code = Artisan::call('klikqris:validate-sandbox', ['payment' => $payment->id]);
        $output = Artisan::output();

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('FAIL stored KlikQRIS QRIS fields match provider shape', $output);
        $this->assertSensitiveDenyListAbsent($output, [
            $fragmentQrisUrl,
            'secret-fragment',
            'raw_payload_fragment',
            '"qris_url"',
            'opaque-test-signature',
        ]);
    }

    public function test_provider_failure_paths_do_not_leak_exception_messages_or_payload_fragments(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment();
        $secretMessage = 'provider exploded token=secret-query raw-base64-image iVBORw0KGgo {"qris_url":"https://klikqris.com/storage/sandbox/qris_secret.png?token=secret-query"}';

        Http::fake(fn () => throw new RuntimeException($secretMessage));

        $createCode = Artisan::call('klikqris:validate-sandbox', ['payment' => $payment->id, '--create' => true]);
        $createOutput = Artisan::output();
        $this->assertSame(1, $createCode, $createOutput);
        $this->assertStringContainsString(RuntimeException::class, $createOutput);
        $this->assertSensitiveDenyListAbsent($createOutput, [
            'provider exploded',
            'token=secret-query',
            'secret-query',
            'raw-base64-image',
            'iVBORw0KGgo',
            '"qris_url"',
        ]);

        $payment->update([
            'provider_total_payment' => 10016,
            'provider_expires_at' => now()->addMinutes(10),
            'provider_payload' => KlikQrisSandboxScenarios::createPayload($payment->fresh(), [
                'expired_at' => now()->addMinutes(10)->toAtomString(),
            ]),
        ]);

        $reconcileCode = Artisan::call('klikqris:validate-sandbox', ['payment' => $payment->id, '--reconcile' => true]);
        $reconcileOutput = Artisan::output();
        $this->assertSame(1, $reconcileCode, $reconcileOutput);
        $this->assertStringContainsString(RuntimeException::class, $reconcileOutput);
        $this->assertSensitiveDenyListAbsent($reconcileOutput, [
            'provider exploded',
            'token=secret-query',
            'secret-query',
            'raw-base64-image',
            'iVBORw0KGgo',
            '"qris_url"',
            'opaque-test-signature',
        ]);
    }

    private function cartWithProduct(int $price = 1000, int $stock = 10, int $quantity = 1): Cart
    {
        $customer = Customer::factory()->create(['telegram_id' => fake()->unique()->numberBetween(100000, 999999999)]);
        $product = Product::factory()->create(['stock' => $stock, 'reserved_stock' => 0, 'price_amount' => $price]);
        $cart = Cart::factory()->create(['customer_id' => $customer->id, 'status' => CartStatus::Active, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => $quantity]);

        return $cart;
    }

    /** @param list<string> $substrings */
    private function assertSensitiveDenyListAbsent(string $output, array $substrings): void
    {
        foreach ($substrings as $substring) {
            $this->assertStringNotContainsString($substring, $output, 'Output leaked sensitive substring: '.$substring);
        }
    }
}
