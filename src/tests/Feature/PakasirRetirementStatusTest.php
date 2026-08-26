<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\ManualPaymentSetting;
use App\Models\PakasirRetirementApproval;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\ProviderCancellationAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PakasirRetirementStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_cutover_migration_moves_active_pakasir_setting_to_klikqris_without_dropping_expand_column(): void
    {
        $migration = require database_path('migrations/2026_08_25_000002_retire_pakasir_payment_setting.php');
        $migration->down();

        $setting = ManualPaymentSetting::create([
            'payment_mode' => 'pakasir',
            'payment_provider_version' => 4,
            'is_active' => true,
        ]);
        $setting->forceFill(['pakasir_checkout_flow' => 'api_qris'])->save();

        $migration->up();

        $this->assertTrue(Schema::hasColumn('manual_payment_settings', 'pakasir_checkout_flow'));
        $this->assertDatabaseHas('manual_payment_settings', [
            'id' => $setting->id,
            'payment_mode' => 'klikqris',
            'payment_provider_version' => 5,
            'is_active' => true,
        ]);
        $audit = AuditLog::query()
            ->where('action', 'payment_provider.switched')
            ->where('subject_id', (string) $setting->id)
            ->sole();
        $this->assertSame('pakasir', $audit->old_values['provider']);
        $this->assertSame('klikqris', $audit->new_values['provider']);
    }

    public function test_cutover_migration_does_not_relabel_historical_payment_event_or_audit_provenance(): void
    {
        $payment = Payment::factory()->create([
            'provider' => 'pakasir',
            'merchant_ref' => 'PAKASIR-HISTORICAL-REF',
            'provider_payment_method' => 'qris',
            'provider_fee' => 227,
            'provider_total_payment' => null,
            'provider_payload' => ['payment' => ['payment_number' => 'snapshot']],
            'status' => PaymentStatus::Paid,
        ]);
        $event = PaymentEvent::factory()->for($payment)->create([
            'provider' => 'pakasir',
            'merchant_ref' => $payment->merchant_ref,
        ]);
        $audit = AuditLog::factory()->create([
            'action' => 'payment_provider.observed',
            'subject_type' => Payment::class,
            'subject_id' => (string) $payment->id,
            'old_values' => ['provider' => 'pakasir'],
            'new_values' => ['provider' => 'pakasir'],
        ]);

        $migration = require database_path('migrations/2026_08_25_000002_retire_pakasir_payment_setting.php');
        $migration->up();

        $this->assertSame('pakasir', $payment->fresh()->provider);
        $this->assertSame('PAKASIR-HISTORICAL-REF', $payment->fresh()->merchant_ref);
        $this->assertSame('pakasir', $event->fresh()->provider);
        $this->assertSame((string) $payment->id, (string) $event->fresh()->payment_id);
        $this->assertSame(Payment::class, $audit->fresh()->subject_type);
        $this->assertSame((string) $payment->id, (string) $audit->fresh()->subject_id);
        $this->assertSame('pakasir', $audit->fresh()->old_values['provider']);
    }

    public function test_cutover_migration_is_idempotent_and_leaves_inactive_settings_untouched(): void
    {
        $active = ManualPaymentSetting::create([
            'payment_mode' => 'pakasir',
            'payment_provider_version' => 2,
            'is_active' => true,
        ]);
        $inactive = ManualPaymentSetting::create([
            'payment_mode' => 'pakasir',
            'payment_provider_version' => 9,
            'is_active' => false,
        ]);

        $migration = require database_path('migrations/2026_08_25_000002_retire_pakasir_payment_setting.php');
        $migration->up();
        $migration->up();

        $this->assertDatabaseHas('manual_payment_settings', [
            'id' => $active->id,
            'payment_mode' => 'klikqris',
            'payment_provider_version' => 3,
        ]);
        $this->assertDatabaseHas('manual_payment_settings', [
            'id' => $inactive->id,
            'payment_mode' => 'pakasir',
            'payment_provider_version' => 9,
        ]);
        $this->assertSame(1, AuditLog::query()->where('action', 'payment_provider.switched')->count());
    }

    public function test_cutover_migration_moves_old_pakasir_cancellation_payloads_to_a_supervised_queue(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'payments',
            'payload' => '{"data":{"command":"App\\\\Jobs\\\\ProcessProviderCancellationAttempt"}}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        $migration = require database_path('migrations/2026_08_25_000002_retire_pakasir_payment_setting.php');
        $migration->up();

        $this->assertSame('notifications', DB::table('jobs')->value('queue'));
    }

    public function test_retirement_status_blocks_when_a_pakasir_payment_is_non_terminal(): void
    {
        Payment::factory()->create([
            'provider' => 'pakasir',
            'status' => PaymentStatus::Pending,
        ]);

        $this->artisan('pakasir:retirement-status')
            ->expectsOutputToContain('Pakasir retirement settlement: blocked')
            ->expectsOutputToContain('Non-terminal Payments: 1')
            ->assertExitCode(1);
    }

    public function test_retirement_status_blocks_when_cancellation_work_is_retryable(): void
    {
        $payment = Payment::factory()->create([
            'provider' => 'pakasir',
            'status' => PaymentStatus::Expired,
        ]);

        ProviderCancellationAttempt::create([
            'order_id' => $payment->order_id,
            'payment_id' => $payment->id,
            'provider' => 'pakasir',
            'state' => ProviderCancellationAttempt::STATE_RETRYABLE_FAILURE,
            'attempt_count' => 1,
        ]);

        $this->artisan('pakasir:retirement-status')
            ->expectsOutputToContain('Pakasir retirement settlement: blocked')
            ->expectsOutputToContain('Dispatchable cancellation work: 1')
            ->assertExitCode(1);
    }

    public function test_retirement_status_blocks_until_legacy_pakasir_queue_payloads_are_consumed(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'notifications',
            'payload' => '{"data":{"command":"App\\\\Jobs\\\\ProcessProviderCancellationAttempt"}}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        $this->artisan('pakasir:retirement-status')
            ->expectsOutputToContain('Pakasir retirement settlement: blocked')
            ->expectsOutputToContain('Legacy queue payloads: 1')
            ->assertExitCode(1);
    }

    public function test_retirement_status_requires_owner_approval_after_pakasir_settlement_is_ready(): void
    {
        Payment::factory()->create([
            'provider' => 'pakasir',
            'status' => PaymentStatus::Paid,
        ]);

        $this->artisan('pakasir:retirement-status')
            ->expectsOutputToContain('Pakasir retirement settlement: awaiting_owner_approval')
            ->expectsOutputToContain('Non-terminal Payments: 0')
            ->expectsOutputToContain('Dispatchable cancellation work: 0')
            ->expectsOutputToContain('Owner approval: missing')
            ->expectsOutputToContain('Owner approval must be recorded before deleting the compatibility lane.')
            ->assertExitCode(1);
    }

    public function test_retirement_status_is_not_ready_until_an_owner_approval_is_recorded(): void
    {
        Payment::factory()->create([
            'provider' => 'pakasir',
            'status' => PaymentStatus::Paid,
        ]);

        $this->artisan('pakasir:retirement-status')
            ->expectsOutputToContain('Pakasir retirement settlement: awaiting_owner_approval')
            ->expectsOutputToContain('Owner approval: missing')
            ->assertExitCode(1);
    }

    public function test_owner_approval_completes_the_retirement_gate(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $approval = PakasirRetirementApproval::query()->firstOrFail();
        $approval->forceFill([
            'approved_by' => $owner->id,
            'approved_at' => now(),
        ])->save();

        $this->artisan('pakasir:retirement-status')
            ->expectsOutputToContain('Pakasir retirement settlement: ready_for_contract')
            ->expectsOutputToContain('Owner approval: recorded')
            ->assertSuccessful();
    }

    public function test_retirement_approval_command_requires_and_records_an_owner(): void
    {
        $owner = User::factory()->create([
            'name' => 'Retirement Owner',
            'email' => 'retirement-owner@example.test',
            'role' => 'owner',
        ]);

        $this->artisan('pakasir:retirement-approve', ['owner' => $owner->email])
            ->expectsOutputToContain('Pakasir retirement owner approval recorded.')
            ->assertSuccessful();

        $this->assertDatabaseHas('pakasir_retirement_approvals', [
            'singleton_key' => PakasirRetirementApproval::SINGLETON_KEY,
            'approved_by' => $owner->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'pakasir.retirement.approved',
            'user_id' => $owner->id,
        ]);
    }

    public function test_retirement_approval_command_rejects_non_owner(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->artisan('pakasir:retirement-approve', ['owner' => $admin->id])
            ->expectsOutputToContain('not an owner')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('pakasir_retirement_approvals', [
            'approved_by' => $admin->id,
        ]);
    }

    public function test_contract_migration_keeps_legacy_column_when_retirement_gate_is_blocked(): void
    {
        $migration = require database_path('contract-migrations/2026_08_26_000001_drop_pakasir_checkout_flow.php');

        $this->expectException(\RuntimeException::class);
        try {
            $migration->up();
        } finally {
            $this->assertTrue(Schema::hasColumn('manual_payment_settings', 'pakasir_checkout_flow'));
        }
    }

    public function test_contract_migration_drops_legacy_column_only_after_gate_is_satisfied(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        PakasirRetirementApproval::query()->firstOrFail()->forceFill([
            'approved_by' => $owner->id,
            'approved_at' => now(),
        ])->save();

        $migration = require database_path('contract-migrations/2026_08_26_000001_drop_pakasir_checkout_flow.php');
        $migration->up();

        $this->assertFalse(Schema::hasColumn('manual_payment_settings', 'pakasir_checkout_flow'));
        $migration->down();
        $this->assertTrue(Schema::hasColumn('manual_payment_settings', 'pakasir_checkout_flow'));
    }
}
