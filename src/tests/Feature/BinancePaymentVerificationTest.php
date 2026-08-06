<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Jobs\VerifyBinanceOrderId;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Binance\BinancePaymentVerification;
use App\Services\Binance\BinanceVerificationSubmissionOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BinancePaymentVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.binance', [
            'pay_id' => '987654321',
            'pay_uid' => null,
            'api_key' => 'binance-key',
            'api_secret' => 'binance-secret',
            'base_url' => 'https://api.binance.test',
            'verification_queue' => 'binance-verification',
            'verify_clock_skew_seconds' => 0,
        ]);
    }

    public function test_order_id_submission_returns_a_named_result_and_dispatches_the_transport_adapter(): void
    {
        Queue::fake([VerifyBinanceOrderId::class]);

        $order = Order::factory()->create(['expires_at' => now()->addHour()]);
        $payment = Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'manual',
            'method' => 'BINANCE_PAY_ID',
            'amount' => 5_000_000,
            'currency' => 'USDT',
            'status' => PaymentStatus::Pending,
            'expired_at' => now()->addHour(),
            'binance_auto_verification_enabled' => true,
            'binance_verification_mode' => 'order_id',
        ]);

        $result = app(BinancePaymentVerification::class)->submitOrderId($order->id, ' 445011487581044736 ');

        $this->assertSame(BinanceVerificationSubmissionOutcome::Accepted, $result->outcome);
        $this->assertSame(1, $result->version);
        $this->assertSame('445011487581044736', $payment->fresh()->binance_order_id);
        Queue::assertPushed(VerifyBinanceOrderId::class, fn (VerifyBinanceOrderId $job): bool => $job->paymentId === $payment->id
            && $job->submissionVersion === 1);
    }
}
