<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Product;
use App\Providers\AppServiceProvider;
use App\Services\OrderCheckoutService;
use App\Services\Pakasir\PakasirClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class PakasirGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.pakasir', [
            'slug' => 'warung-ai',
            'api_key' => 'pakasir-secret',
            'base_url' => 'https://app.pakasir.com',
            'redirect_url' => 'https://example.test/payment/return',
            'qris_only' => true,
        ]);
    }

    public function test_generates_deterministic_hosted_payment_url_for_existing_payment(): void
    {
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), 'qris', 'pakasir');
        $payment = $order->payments()->firstOrFail();

        $url = app(PakasirClient::class)->hostedPaymentUrl($payment);

        $this->assertSame(
            'https://app.pakasir.com/pay/warung-ai/10000?order_id='.$payment->merchant_ref.'&redirect=https%3A%2F%2Fexample.test%2Fpayment%2Freturn&qris_only=1',
            $url,
        );
        $this->assertStringNotContainsString('api_key', $url);
    }

    public function test_hosted_payment_url_omits_optional_params_when_not_configured(): void
    {
        config()->set('services.pakasir.redirect_url', null);
        config()->set('services.pakasir.qris_only', false);
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), 'qris', 'pakasir');
        $payment = $order->payments()->firstOrFail();

        $url = app(PakasirClient::class)->hostedPaymentUrl($payment);

        $this->assertSame('https://app.pakasir.com/pay/warung-ai/10000?order_id='.$payment->merchant_ref, $url);
    }

    public function test_transaction_detail_request_uses_project_amount_order_id_and_api_key(): void
    {
        Http::fake([
            'https://app.pakasir.com/api/transactiondetail*' => Http::response(['transaction' => ['status' => 'completed']], 200),
        ]);
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), 'qris', 'pakasir');
        $payment = $order->payments()->firstOrFail();

        $response = app(PakasirClient::class)->transactionDetail($payment);

        $this->assertSame(['transaction' => ['status' => 'completed']], $response);
        Http::assertSent(fn ($request) => $request->url() === 'https://app.pakasir.com/api/transactiondetail?project=warung-ai&amount=10000&order_id='.$payment->merchant_ref.'&api_key=pakasir-secret');
    }

    public function test_production_validation_requires_slug_and_api_key_when_pakasir_mode_is_active(): void
    {
        config([
            'app.env' => 'production',
            'app.key' => 'base64:test-key',
            'app.url' => 'https://example.test',
            'services.telegram.token' => 'telegram-token',
            'services.telegram.webhook_secret' => 'telegram-secret',
            'services.telegram.payment_mode' => 'pakasir',
            'services.pakasir.slug' => '',
            'services.pakasir.api_key' => '',
        ]);
        app()->detectEnvironment(fn () => 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing required production configuration: PAKASIR_SLUG, PAKASIR_API_KEY');

        (new AppServiceProvider(app()))->boot();
    }

    public function test_production_validation_requires_https_pakasir_base_url_when_pakasir_mode_is_active(): void
    {
        config([
            'app.env' => 'production',
            'app.key' => 'base64:test-key',
            'app.url' => 'https://example.test',
            'services.telegram.token' => 'telegram-token',
            'services.telegram.webhook_secret' => 'telegram-secret',
            'services.telegram.payment_mode' => 'pakasir',
            'services.pakasir.slug' => 'warung-ai',
            'services.pakasir.api_key' => 'pakasir-secret',
            'services.pakasir.base_url' => 'http://app.pakasir.test',
        ]);
        app()->detectEnvironment(fn () => 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PAKASIR_BASE_URL must use HTTPS in production.');

        (new AppServiceProvider(app()))->boot();
    }

    public function test_manual_checkout_remains_local_and_does_not_call_pakasir(): void
    {
        Http::fake();
        config(['services.telegram.payment_mode' => 'manual']);

        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), 'QR_MANUAL', 'manual');
        $payment = $order->payments()->firstOrFail();

        $this->assertSame('manual', $payment->provider);
        $this->assertSame('QR_MANUAL', $payment->method);
        $this->assertNull($payment->provider_reference);
        Http::assertNothingSent();
    }

    private function cartWithProduct(int $stock = 10, int $quantity = 1): Cart
    {
        $customer = Customer::factory()->create(['first_name' => 'Buyer', 'phone_number' => '08123456789']);
        $product = Product::factory()->create(['stock' => $stock, 'reserved_stock' => 0, 'price_amount' => 10000]);
        $cart = Cart::factory()->create(['customer_id' => $customer->id, 'status' => CartStatus::Active, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => $quantity]);

        return $cart;
    }
}
