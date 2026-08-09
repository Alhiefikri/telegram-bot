<?php

namespace Tests\Unit\Services;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Services\PaymentReceiptReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentReceiptReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_total_semantics_keep_buyer_paid_qris_fees_out_of_earnings(): void
    {
        $payment = Payment::factory()->make([
            'provider' => 'klikqris',
            'provider_payment_method' => 'qris',
            'amount' => 35000,
            'provider_fee' => 227,
            'provider_total_payment' => 35227,
        ]);

        $this->assertSame(35000, app(PaymentReceiptReport::class)->netAmount($payment));
    }

    public function test_provider_total_semantics_reduce_earnings_for_merchant_paid_qris_fees(): void
    {
        $payment = Payment::factory()->make([
            'provider' => 'pakasir',
            'method' => 'api_qris',
            'provider_payment_method' => 'qris',
            'amount' => 35000,
            'provider_fee' => 227,
            'provider_total_payment' => 35000,
        ]);

        $this->assertSame(34773, app(PaymentReceiptReport::class)->netAmount($payment));
    }

    public function test_configured_pakasir_fee_payer_is_used_when_provider_total_is_missing(): void
    {
        $payment = Payment::factory()->make([
            'provider' => 'pakasir',
            'method' => 'api_qris',
            'provider_payment_method' => 'qris',
            'amount' => 35000,
            'provider_fee' => 227,
            'provider_total_payment' => null,
        ]);

        config()->set('services.pakasir.qris_fee.payer', 'customer');
        $this->assertSame(35000, app(PaymentReceiptReport::class)->netAmount($payment));

        config()->set('services.pakasir.qris_fee.payer', 'merchant');
        $this->assertSame(34773, app(PaymentReceiptReport::class)->netAmount($payment));
    }

    public function test_sql_canonical_earning_projection_matches_hydrated_projection(): void
    {
        $buyerPaidOrder = Order::factory()->create([
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => 35000,
        ]);
        Payment::factory()->for($buyerPaidOrder)->create([
            'provider' => 'klikqris',
            'method' => 'QRIS',
            'provider_payment_method' => 'qris',
            'amount' => 35000,
            'provider_fee' => 227,
            'provider_total_payment' => 35227,
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        $merchantPaidOrder = Order::factory()->create([
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => 35000,
        ]);
        Payment::factory()->for($merchantPaidOrder)->create([
            'provider' => 'pakasir',
            'method' => 'api_qris',
            'provider_payment_method' => 'qris',
            'amount' => 35000,
            'provider_fee' => 227,
            'provider_total_payment' => 35000,
            'status' => PaymentStatus::Paid,
            'paid_at' => now()->addSecond(),
        ]);

        $report = app(PaymentReceiptReport::class);
        $payments = $report->canonicalPaidPayments();

        $this->assertSame(69773, $report->idrNetTotal($payments));
        $this->assertSame(69773, $report->idrNetTotalCanonical());
    }

    public function test_sql_projection_uses_configured_pakasir_fee_payer_without_provider_total(): void
    {
        $order = Order::factory()->create(['payment_status' => PaymentStatus::Paid]);
        Payment::factory()->for($order)->create([
            'provider' => 'pakasir',
            'method' => 'api_qris',
            'provider_payment_method' => 'qris',
            'amount' => 35000,
            'provider_fee' => 227,
            'provider_total_payment' => null,
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        $report = app(PaymentReceiptReport::class);

        config()->set('services.pakasir.qris_fee.payer', 'customer');
        $this->assertSame(35000, $report->idrNetTotalCanonical());

        config()->set('services.pakasir.qris_fee.payer', 'merchant');
        $this->assertSame(34773, $report->idrNetTotalCanonical());
    }
}
