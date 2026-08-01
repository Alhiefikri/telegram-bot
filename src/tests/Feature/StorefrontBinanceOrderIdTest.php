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
}
