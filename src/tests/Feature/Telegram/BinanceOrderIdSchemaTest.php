<?php

namespace Tests\Feature\Telegram;

use App\Enums\PaymentStatus;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BinanceOrderIdSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_binance_payment_schema_contains_the_dual_mode_verification_contract(): void
    {
        $this->assertTrue(Schema::hasColumns('payments', [
            'binance_verification_mode',
            'binance_order_id',
            'binance_order_id_attempts',
            'binance_order_id_submission_version',
            'binance_order_id_submitted_at',
            'binance_verification_grace_until',
            'binance_verification_status',
            'binance_last_verified_at',
        ]));

        $this->assertTrue(Schema::hasColumn('manual_payment_settings', 'binance_order_id_compatibility_ready_at'));
    }

    public function test_legacy_and_order_id_payments_can_coexist_and_submission_version_is_persisted(): void
    {
        $order = Order::factory()->create();
        $legacy = Payment::factory()->for($order)->create([
            'provider' => 'manual',
            'method' => 'BINANCE_PAY_ID',
            'currency' => 'USDT',
            'binance_verification_mode' => 'amount_unique',
            'amount' => 5_000_000,
        ]);
        $new = Payment::factory()->for(Order::factory())->create([
            'provider' => 'manual',
            'method' => 'BINANCE_PAY_ID',
            'currency' => 'USDT',
            'binance_verification_mode' => 'order_id',
            'binance_order_id' => '445011487581044736',
            'binance_order_id_submission_version' => 2,
            'binance_verification_status' => 'checking',
            'amount' => $legacy->amount,
            'status' => PaymentStatus::Pending,
        ]);

        $this->assertSame('amount_unique', $legacy->fresh()->binance_verification_mode);
        $this->assertSame('order_id', $new->fresh()->binance_verification_mode);
        $this->assertSame(2, $new->fresh()->binance_order_id_submission_version);
        $this->assertSame('checking', $new->fresh()->binance_verification_status);
    }

    public function test_compatibility_marker_is_nullable_until_operational_checks_pass(): void
    {
        $setting = ManualPaymentSetting::create(['is_active' => true]);

        $this->assertNull($setting->fresh()->binance_order_id_compatibility_ready_at);

        $setting->update(['binance_order_id_compatibility_ready_at' => now()]);
        $this->assertNotNull($setting->fresh()->binance_order_id_compatibility_ready_at);
    }
}
