<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\ManualPaymentSetting;
use App\Models\Payment;
use App\Models\ProviderCancellationAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PakasirRetirementStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_cutover_migration_moves_active_pakasir_setting_to_klikqris_and_audits_it(): void
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

        $this->assertFalse(Schema::hasColumn('manual_payment_settings', 'pakasir_checkout_flow'));
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
            ->expectsOutputToContain('Owner approval must be recorded before deleting the compatibility lane.')
            ->assertSuccessful();
    }
}
