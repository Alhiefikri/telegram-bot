<?php

namespace Tests\Feature;

use App\Models\BscPaymentReceiver;
use App\Models\Order;
use App\Models\PaymentBscDetail;
use App\Services\Bsc\BscPaymentReceiverSnapshot;
use App\Services\Checkout\CheckoutPaymentPlan;
use App\Services\Checkout\CheckoutPaymentPlanWriter;
use App\Services\Checkout\CheckoutPostCreationBehavior;
use App\Services\Checkout\PaymentPlan\BinancePayCheckoutPaymentSnapshot;
use App\Services\Checkout\PaymentPlan\BscCheckoutPaymentSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CheckoutPaymentPlanWriterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_persists_common_payment_fields_and_typed_bsc_extension(): void
    {
        $receiver = BscPaymentReceiver::factory()->verified()->active()->create([
            'address' => '0x1111111111111111111111111111111111111111',
            'token_contract' => '0x55d398326f99059ff775485246999027b3197955',
        ]);
        $deadline = now()->addHour()->toImmutable();
        $receiverSnapshot = BscPaymentReceiverSnapshot::fromReceiver($receiver);
        $plan = new CheckoutPaymentPlan(
            orderCurrency: 'IDR',
            orderExpiresAt: $deadline,
            provider: 'manual',
            method: 'USDT_BEP20',
            paymentCurrency: 'USDT',
            amount: 2500000,
            paymentExpiresAt: $deadline,
            railSnapshot: new BscCheckoutPaymentSnapshot(
                provider: 'manual',
                method: 'USDT_BEP20',
                paymentCurrency: 'USDT',
                amount: 2500000,
                expiresAt: $deadline,
                receiver: $receiverSnapshot,
                baseAmountMicro: 2500000,
                payableAmountMicro: 2500000,
                expectedTokenUnits: '2500000000000000000',
                verificationStatus: PaymentBscDetail::STATUS_AWAITING_TRANSFER,
            ),
            postCreationBehavior: CheckoutPostCreationBehavior::BscInstructions,
        );

        $payment = app(CheckoutPaymentPlanWriter::class)->persist(Order::factory()->create(), $plan);

        $this->assertSame('manual', $payment->provider);
        $this->assertSame('USDT_BEP20', $payment->method);
        $this->assertSame(2500000, $payment->amount);
        $this->assertSame('USDT', $payment->currency);
        $this->assertSame(
            '2500000000000000000',
            (string) $payment->bscDetail()->sole()->expected_token_units,
        );
    }

    #[Test]
    public function it_persists_typed_binance_payment_fields_without_a_provider_call(): void
    {
        $deadline = now()->addHour()->toImmutable();
        $plan = new CheckoutPaymentPlan(
            orderCurrency: 'IDR',
            orderExpiresAt: $deadline,
            provider: 'manual',
            method: 'BINANCE_PAY_ID',
            paymentCurrency: 'USDT',
            amount: 3250000,
            paymentExpiresAt: $deadline,
            railSnapshot: new BinancePayCheckoutPaymentSnapshot(
                provider: 'manual',
                method: 'BINANCE_PAY_ID',
                paymentCurrency: 'USDT',
                amount: 3250000,
                expiresAt: $deadline,
                verificationMode: 'order_id',
                autoVerificationEnabled: false,
                receiverType: 'pay_id',
                receiverValue: '987654321',
                verificationStatus: 'awaiting_submission',
            ),
            postCreationBehavior: CheckoutPostCreationBehavior::BinancePayInstructions,
        );

        $payment = app(CheckoutPaymentPlanWriter::class)->persist(Order::factory()->create(), $plan);

        $this->assertFalse($payment->fresh()->binance_auto_verification_enabled);
        $this->assertSame('order_id', $payment->fresh()->binance_verification_mode);
        $this->assertSame('pay_id', $payment->fresh()->binance_receiver_type);
        $this->assertSame('987654321', $payment->fresh()->binance_receiver_value);
        $this->assertSame('awaiting_submission', $payment->fresh()->binance_verification_status);
    }
}
