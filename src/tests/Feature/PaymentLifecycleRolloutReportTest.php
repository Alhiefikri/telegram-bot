<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Services\PaymentLifecycleRollout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentLifecycleRolloutReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_legacy_uncertainty_and_blocks_contract_cleanup(): void
    {
        Payment::factory()->create(['provider_create_state' => 'outcome_unknown']);

        $this->artisan('ops:payment-lifecycle-rollout-report --require-contract-safe')
            ->expectsOutputToContain('provider_uncertainty')
            ->assertExitCode(1);
    }

    public function test_it_reports_a_clean_expand_phase_as_contract_safe(): void
    {
        $this->artisan('ops:payment-lifecycle-rollout-report --require-contract-safe')
            ->expectsOutputToContain('contract_safe')
            ->assertSuccessful();
    }

    public function test_it_reports_order_payment_state_mismatches_without_mutating_legacy_rows(): void
    {
        $payment = Payment::factory()->create();
        Order::query()->whereKey($payment->order_id)->update(['payment_status' => 'paid']);

        $this->artisan('ops:payment-lifecycle-rollout-report')
            ->expectsOutputToContain('lifecycle_state_mismatches')
            ->assertSuccessful();

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending']);
    }

    public function test_canary_selection_is_stable_and_rollback_never_selects_the_hardened_path(): void
    {
        $payment = Payment::factory()->create();
        config()->set('services.payment_lifecycle_rollout.mode', 'canary');
        config()->set('services.payment_lifecycle_rollout.canary_percentage', 50);

        $rollout = app(PaymentLifecycleRollout::class);
        $this->assertSame($rollout->usesHardenedPath($payment), $rollout->usesHardenedPath($payment));

        config()->set('services.payment_lifecycle_rollout.mode', 'rollback');
        $this->assertFalse($rollout->usesHardenedPath($payment));
    }
}
