<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Jobs\VerifyBinanceOrderId;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Binance\BinancePaymentVerification;
use App\Services\Binance\BinanceVerificationOutcome;
use App\Services\Binance\BinanceVerificationSubmissionOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_malformed_matching_order_is_a_provider_error_not_a_missing_payment(): void
    {
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
            'binance_order_id' => '445011487581044736',
            'binance_order_id_submission_version' => 1,
        ]);
        $result = app(BinancePaymentVerification::class)->verify($payment, [[
            'orderId' => '445011487581044736', 'transactionId' => 'TX-445011487581044736',
            'amount' => '5.0000001', 'currency' => 'USDT', 'transactionTime' => now()->getTimestampMs(),
            'receiverInfo' => ['accountId' => '987654321'],
        ]], 1);

        $this->assertSame(BinanceVerificationOutcome::ProviderError, $result->outcome);
        $this->assertTrue($result->retryable);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    #[DataProvider('binanceCheckoutModes')]
    public function test_failed_binance_transfer_does_not_pay_the_order(string $mode): void
    {
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
            'binance_verification_mode' => $mode,
            'binance_order_id' => $mode === 'order_id' ? '445011487581044736' : null,
            'binance_order_id_submission_version' => 1,
        ]);
        $result = app(BinancePaymentVerification::class)->verify($payment, [[
            'orderId' => '445011487581044736', 'transactionId' => 'TX-445011487581044736',
            'amount' => '5', 'currency' => 'USDT', 'transactionTime' => now()->getTimestampMs(),
            'status' => 'FAILED', 'receiverInfo' => ['accountId' => '987654321'],
        ]], 1);

        $this->assertSame(BinanceVerificationOutcome::Mismatch, $result->outcome);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public static function binanceCheckoutModes(): array
    {
        return ['order ID' => ['order_id'], 'amount unique' => ['amount_unique']];
    }
}
