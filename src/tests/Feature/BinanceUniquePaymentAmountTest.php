<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\Binance\BinanceUniquePaymentAmount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BinanceUniquePaymentAmountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.binance.api_key', 'binance-key');
        config()->set('services.binance.api_secret', 'binance-secret');
    }

    public function test_returns_the_exact_base_amount_when_it_is_free(): void
    {
        $this->assertSame(1_000_000, app(BinanceUniquePaymentAmount::class)->allocate(1_000_000));
    }

    public function test_returns_the_lowest_free_sequential_mill_usdt_amount(): void
    {
        $this->occupy(1_000_000);
        $this->occupy(1_001_000);

        $this->assertSame(1_002_000, app(BinanceUniquePaymentAmount::class)->allocate(1_000_000));
    }

    public function test_reuses_a_lower_gap_before_higher_occupied_amounts(): void
    {
        $this->occupy(1_000_000);
        $this->occupy(1_002_000);

        $this->assertSame(1_001_000, app(BinanceUniquePaymentAmount::class)->allocate(1_000_000));
    }

    public function test_expired_payment_does_not_occupy_the_base_amount(): void
    {
        $this->occupy(1_000_000, PaymentStatus::Expired);

        $this->assertSame(1_000_000, app(BinanceUniquePaymentAmount::class)->allocate(1_000_000));
    }

    public function test_paid_payment_does_not_occupy_the_base_amount(): void
    {
        $this->occupy(1_000_000, PaymentStatus::Paid);

        $this->assertSame(1_000_000, app(BinanceUniquePaymentAmount::class)->allocate(1_000_000));
    }

    public function test_order_id_payment_does_not_occupy_the_base_amount(): void
    {
        Payment::factory()->create([
            'provider' => 'manual',
            'method' => 'BINANCE_PAY_ID',
            'amount' => 1_000_000,
            'currency' => 'USDT',
            'status' => PaymentStatus::Pending,
            'binance_auto_verification_enabled' => true,
            'binance_verification_mode' => 'order_id',
        ]);

        $this->assertSame(1_000_000, app(BinanceUniquePaymentAmount::class)->allocate(1_000_000));
    }

    public function test_non_binance_payment_does_not_occupy_the_base_amount(): void
    {
        Payment::factory()->create([
            'amount' => 1_000_000,
            'currency' => 'USDT',
            'status' => PaymentStatus::Pending,
        ]);

        $this->assertSame(1_000_000, app(BinanceUniquePaymentAmount::class)->allocate(1_000_000));
    }

    public function test_advances_to_the_next_batch_after_the_current_batch_is_full(): void
    {
        foreach (range(0, 999) as $offset) {
            $this->occupy(1_000_000 + ($offset * 1_000));
        }

        $this->assertSame(2_000_000, app(BinanceUniquePaymentAmount::class)->allocate(1_000_000));
    }

    private function occupy(int $amount, PaymentStatus $status = PaymentStatus::Pending): Payment
    {
        return Payment::factory()->create([
            'provider' => 'manual',
            'method' => 'BINANCE_PAY_ID',
            'amount' => $amount,
            'currency' => 'USDT',
            'status' => $status,
            'binance_auto_verification_enabled' => true,
            'binance_verification_mode' => 'amount_unique',
        ]);
    }
}
