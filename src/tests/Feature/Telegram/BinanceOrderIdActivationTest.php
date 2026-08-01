<?php

namespace Tests\Feature\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
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
        config()->set('services.telegram.admin_user_id', '777');
        $setting = ManualPaymentSetting::create(['is_active' => true]);
        $admin = Customer::factory()->create(['telegram_id' => 777]);
        $order = Order::factory()->for($admin)->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        Payment::factory()->for($order)->create([
            'provider' => 'manual',
            'method' => 'BINANCE_PAY_ID',
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
            'binance_verification_mode' => 'order_id',
            'binance_order_id' => '445011487581044736',
            'binance_order_id_submitted_at' => now()->subMinute(),
            'binance_verification_status' => 'confirmed',
            'provider_reference' => '445011487581044736',
            'binance_receiver_type' => 'pay_id',
            'binance_receiver_value' => '987654321',
        ]);

        $this->artisan('binance:mark-order-id-ready', ['--confirm-canary' => true])
            ->assertSuccessful();

        $fresh = $setting->fresh();
        $this->assertSame('order_id', $fresh->binance_verification_mode);
        $this->assertNotNull($fresh->binance_order_id_compatibility_ready_at);
        $this->artisan('ops:health', ['--binance-canary' => true])
            ->expectsOutputToContain('Binance Order ID global readiness: ready')
            ->expectsOutputToContain('Binance Order ID recent admin canary: ok')
            ->assertSuccessful();
    }

    public function test_readiness_command_refuses_activation_without_persisted_canary_evidence(): void
    {
        config()->set('services.binance.api_key', 'key');
        config()->set('services.binance.api_secret', 'secret');
        config()->set('services.binance.pay_id', '987654321');
        config()->set('services.telegram.admin_user_id', '777');
        $setting = ManualPaymentSetting::create(['is_active' => true]);

        $this->artisan('binance:mark-order-id-ready', ['--confirm-canary' => true])
            ->expectsOutputToContain('No recent successful admin Binance Order ID canary evidence was found.')
            ->assertFailed();

        $this->assertSame('amount_unique', $setting->fresh()->binance_verification_mode);
        $this->assertNull($setting->fresh()->binance_order_id_compatibility_ready_at);
        $this->artisan('ops:health', ['--binance-canary' => true])
            ->expectsOutputToContain('Binance Order ID global readiness: compatibility')
            ->expectsOutputToContain('Binance Order ID recent admin canary: missing or stale')
            ->assertFailed();
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
