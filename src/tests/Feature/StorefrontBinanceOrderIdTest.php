<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\VerifyBinanceOrderId;
use App\Models\Order;
use App\Models\Payment;
use App\Storefront\StorefrontInvoiceUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class StorefrontBinanceOrderIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_storefront_submission_is_queued_without_provider_http(): void
    {
        Queue::fake([VerifyBinanceOrderId::class]);
        $order = Order::factory()->create([
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'expires_at' => now()->addMinutes(20),
        ]);
        $payment = Payment::factory()->for($order)->create([
            'provider' => 'manual',
            'method' => 'BINANCE_PAY_ID',
            'currency' => 'USDT',
            'amount' => 5_000_000,
            'status' => PaymentStatus::Pending,
            'binance_auto_verification_enabled' => true,
            'binance_verification_mode' => 'order_id',
            'expired_at' => now()->addMinutes(20),
        ]);

        $response = $this->post(URL::signedRoute('storefront.shop.orders.binance-order-id', $order, null, false), [
            'binance_order_id' => '445011487581044736',
        ]);

        $response->assertRedirect(app(StorefrontInvoiceUrl::class)->show($order));
        $this->assertSame('445011487581044736', $payment->fresh()->binance_order_id);
        $this->assertSame(1, $payment->fresh()->binance_order_id_submission_version);
        Queue::assertPushed(VerifyBinanceOrderId::class);
    }

    public function test_signed_submission_cannot_bypass_release_a_amount_unique_mode(): void
    {
        Queue::fake([VerifyBinanceOrderId::class]);
        $order = Order::factory()->create([
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'expires_at' => now()->addMinutes(20),
        ]);
        $payment = Payment::factory()->for($order)->create([
            'provider' => 'manual',
            'method' => 'BINANCE_PAY_ID',
            'currency' => 'USDT',
            'amount' => 5_000_000,
            'status' => PaymentStatus::Pending,
            'binance_auto_verification_enabled' => true,
            'binance_verification_mode' => 'amount_unique',
            'expired_at' => now()->addMinutes(20),
        ]);

        $this->post(URL::signedRoute('storefront.shop.orders.binance-order-id', $order, null, false), [
            'binance_order_id' => '445011487581044736',
        ])->assertRedirect();

        $this->assertNull($payment->fresh()->binance_order_id);
        Queue::assertNothingPushed();
    }

    public function test_signed_binance_status_poll_is_state_only_and_never_calls_binance(): void
    {
        $order = Order::factory()->create([
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'expires_at' => now()->addMinutes(20),
        ]);
        Payment::factory()->for($order)->create([
            'provider' => 'manual',
            'method' => 'BINANCE_PAY_ID',
            'currency' => 'USDT',
            'amount' => 5_000_000,
            'status' => PaymentStatus::Pending,
            'binance_auto_verification_enabled' => true,
            'binance_verification_mode' => 'order_id',
            'binance_verification_status' => 'checking',
            'expired_at' => now()->addMinutes(20),
        ]);
        Http::fake();

        $this->postJson(URL::signedRoute('storefront.shop.orders.status', $order, null, false))
            ->assertOk()
            ->assertJsonPath('status', 'refreshed')
            ->assertJsonPath('is_paid', false);

        Http::assertNothingSent();
    }

    public function test_signed_binance_status_poll_does_not_retry_closed_order_id_verification(): void
    {
        config()->set('services.binance.api_key', 'binance-key');
        config()->set('services.binance.api_secret', 'binance-secret');
        config()->set('services.binance.base_url', 'https://api.binance.test');

        $order = Order::factory()->create([
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'expires_at' => now()->addMinutes(20),
        ]);
        Payment::factory()->for($order)->create([
            'provider' => 'manual',
            'method' => 'BINANCE_PAY_ID',
            'currency' => 'USDT',
            'amount' => 5_000_000,
            'status' => PaymentStatus::Pending,
            'binance_auto_verification_enabled' => true,
            'binance_verification_mode' => 'order_id',
            'binance_order_id' => '445011487581044736',
            'binance_verification_status' => 'not_found',
            'binance_verification_grace_until' => now()->subMinute(),
            'expired_at' => now()->addMinutes(20),
        ]);
        Http::fake([
            'https://api.binance.test/*' => Http::response(['code' => '000000', 'success' => true, 'data' => []]),
        ]);

        $this->postJson(URL::signedRoute('storefront.shop.orders.status', $order, null, false))
            ->assertOk()
            ->assertJsonPath('is_terminal', true);

        Http::assertNothingSent();
    }

    public function test_amount_unique_status_refresh_uses_binance_workflow_to_claim_a_match(): void
    {
        config()->set('services.binance.api_key', 'binance-key');
        config()->set('services.binance.api_secret', 'binance-secret');
        config()->set('services.binance.pay_id', '987654321');
        config()->set('services.binance.history_cache_seconds', 0);
        Queue::fake();

        $order = Order::factory()->create([
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'expires_at' => now()->addMinutes(20),
        ]);
        $payment = Payment::factory()->for($order)->create([
            'provider' => 'manual',
            'method' => 'BINANCE_PAY_ID',
            'currency' => 'USDT',
            'amount' => 5_000_000,
            'status' => PaymentStatus::Pending,
            'binance_auto_verification_enabled' => true,
            'binance_verification_mode' => 'amount_unique',
            'binance_receiver_type' => 'pay_id',
            'binance_receiver_value' => '987654321',
            'binance_verification_status' => 'checking',
            'expired_at' => now()->addMinutes(20),
            'created_at' => now()->subMinute(),
        ]);
        config()->set('services.binance.base_url', 'https://api.binance.test');
        Http::fake([
            'https://api.binance.test/*' => Http::response([
                'code' => '000000',
                'success' => true,
                'data' => [[
                    'orderId' => 'BINANCE-ORDER-1',
                    'transactionId' => 'TX-1',
                    'amount' => '5',
                    'currency' => 'USDT',
                    'transactionTime' => now()->getTimestampMs(),
                    'receiverInfo' => ['accountId' => '987654321'],
                ]],
            ]),
        ]);

        $response = $this->postJson(URL::signedRoute('storefront.shop.orders.status', $order, null, false));

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.binance.test'));
    }

    public function test_cancelled_binance_invoice_has_terminal_status_and_method_specific_copy(): void
    {
        $order = Order::factory()->create([
            'order_status' => OrderStatus::Cancelled,
            'payment_status' => PaymentStatus::Pending,
            'expires_at' => now()->addMinutes(20),
        ]);
        Payment::factory()->for($order)->create([
            'provider' => 'manual',
            'method' => 'BINANCE_PAY_ID',
            'currency' => 'USDT',
            'amount' => 5_000_000,
            'status' => PaymentStatus::Pending,
            'binance_verification_mode' => 'amount_unique',
            'expired_at' => now()->addMinutes(20),
        ]);

        $this->get(URL::signedRoute('storefront.shop.orders.show', $order, null, false))
            ->assertOk()
            ->assertSee('Dibatalkan')
            ->assertSee('Jangan kirim USDT')
            ->assertDontSee('Jangan bayar QRIS');

        $this->postJson(URL::signedRoute('storefront.shop.orders.status', $order, null, false))
            ->assertOk()
            ->assertJsonPath('status', 'terminal')
            ->assertJsonPath('message', 'Order dibatalkan. Jangan kirim USDT untuk invoice ini; buat checkout Binance baru jika masih ingin membeli.');
    }
}
