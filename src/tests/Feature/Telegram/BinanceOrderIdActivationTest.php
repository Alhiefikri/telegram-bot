<?php

namespace Tests\Feature\Telegram;

use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BinanceOrderIdActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_activation_migration_is_a_safe_noop_without_readiness_marker(): void
    {
        Payment::factory()->for(Order::factory())->create();
        $setting = ManualPaymentSetting::create(['is_active' => true]);

        $migration = require database_path('migrations/2026_08_01_000003_activate_binance_order_id_mode.php');
        $migration->up();

        $this->assertSame('amount_unique', $setting->fresh()->binance_verification_mode);
    }

    public function test_activation_changes_the_amount_index_to_legacy_mode_only_after_marker(): void
    {
        $setting = ManualPaymentSetting::create([
            'is_active' => true,
            'binance_order_id_compatibility_ready_at' => now(),
        ]);
        $migration = require database_path('migrations/2026_08_01_000003_activate_binance_order_id_mode.php');
        $migration->up();

        $this->assertSame('order_id', $setting->fresh()->binance_verification_mode);
        $indexes = DB::select("PRAGMA index_list('payments')");
        $names = array_map(fn ($index): string => (string) ($index->name ?? ''), $indexes);
        $this->assertContains('payments_pending_binance_auto_amount_unique', $names);
    }

    public function test_readiness_command_marks_and_activates_after_canary(): void
    {
        config()->set('services.binance.api_key', 'key');
        config()->set('services.binance.api_secret', 'secret');
        config()->set('services.binance.pay_id', '987654321');
        $setting = ManualPaymentSetting::create(['is_active' => true]);

        $this->artisan('binance:mark-order-id-ready', ['--confirm-canary' => true])
            ->assertSuccessful();

        $fresh = $setting->fresh();
        $this->assertSame('order_id', $fresh->binance_verification_mode);
        $this->assertNotNull($fresh->binance_order_id_compatibility_ready_at);
    }

    public function test_activation_rollback_clears_readiness_marker_before_a_future_reactivation(): void
    {
        $setting = ManualPaymentSetting::create([
            'is_active' => true,
            'binance_order_id_compatibility_ready_at' => now(),
        ]);
        $migration = require database_path('migrations/2026_08_01_000003_activate_binance_order_id_mode.php');
        $migration->up();
        $migration->down();

        $this->assertSame('amount_unique', $setting->fresh()->binance_verification_mode);
        $this->assertNull($setting->fresh()->binance_order_id_compatibility_ready_at);
    }
}
