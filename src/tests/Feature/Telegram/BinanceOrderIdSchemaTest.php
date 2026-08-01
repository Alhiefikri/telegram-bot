<?php

namespace Tests\Feature\Telegram;

use App\Enums\PaymentStatus;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_additive_repair_restores_schema_for_databases_that_ran_the_earlier_migration_shape(): void
    {
        $order = Order::factory()->create();
        $payment = Payment::factory()->for($order)->create([
            'provider' => 'manual',
            'method' => 'BINANCE_PAY_ID',
            'currency' => 'USDT',
            'amount' => 5_000_000,
        ]);

        DB::statement('DROP INDEX IF EXISTS payments_binance_pending_verification_lookup');
        DB::statement('DROP INDEX IF EXISTS payments_binance_order_lookup');

        Schema::table('payments', function ($table): void {
            $table->dropColumn([
                'binance_verification_mode',
                'binance_receiver_type',
                'binance_receiver_value',
                'binance_order_id_submission_version',
                'binance_verification_status',
                'binance_last_verified_at',
            ]);
        });
        Schema::table('manual_payment_settings', function ($table): void {
            $table->dropColumn([
                'binance_verification_mode',
                'binance_order_id_compatibility_ready_at',
            ]);
        });

        $migration = require database_path('migrations/2026_08_01_000004_repair_binance_order_id_schema.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumns('payments', [
            'binance_verification_mode',
            'binance_receiver_type',
            'binance_receiver_value',
            'binance_order_id_submission_version',
            'binance_verification_status',
            'binance_last_verified_at',
        ]));
        $this->assertTrue(Schema::hasColumns('manual_payment_settings', [
            'binance_verification_mode',
            'binance_order_id_compatibility_ready_at',
        ]));
        $this->assertSame('amount_unique', Payment::query()->findOrFail($payment->id)->binance_verification_mode);
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
