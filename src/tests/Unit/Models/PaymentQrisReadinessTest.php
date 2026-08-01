<?php

namespace Tests\Unit\Models;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Tests\Support\QrisTestPayload;
use Tests\TestCase;

class PaymentQrisReadinessTest extends TestCase
{
    public function test_persisted_qris_is_payable_only_when_fee_total_and_emv_amount_are_consistent(): void
    {
        $valid = $this->payment(20_000, 450, 20_450, QrisTestPayload::forAmount(20_450));
        $badFinancials = $this->payment(20_000, 2_660, 268_660, QrisTestPayload::forAmount(268_660));
        $badQrAmount = $this->payment(20_000, 450, 20_450, QrisTestPayload::forAmount(20_451));

        $this->assertTrue($valid->isPayableNativePakasirQris());
        $this->assertFalse($badFinancials->isPayableNativePakasirQris());
        $this->assertFalse($badQrAmount->isPayableNativePakasirQris());
    }

    private function payment(int $amount, int $fee, int $total, string $paymentNumber): Payment
    {
        return new Payment([
            'provider' => 'pakasir',
            'method' => 'api_qris',
            'status' => PaymentStatus::Pending,
            'amount' => $amount,
            'provider_fee' => $fee,
            'provider_total_payment' => $total,
            'provider_payment_method' => 'qris',
            'provider_payment_number' => $paymentNumber,
        ]);
    }
}
