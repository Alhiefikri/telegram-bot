<?php

namespace Tests\Feature;

use App\Enums\UsdtBalanceUnfreezeStatus;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerUsdtBalanceAccount;
use App\Services\Operations\UsdtBalanceAccountUnfreeze;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\VerifiedUsdtFunding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * O2 #558 operator unfreeze: a drift freeze may only be cleared when a fresh
 * re-check proves the drift is gone, and a reorg freeze is never cleared here.
 */
final class UsdtBalanceUnfreezeTest extends TestCase
{
    use RefreshDatabase;

    public function test_unfreeze_clears_a_drift_freeze_only_when_no_drift_remains(): void
    {
        $customer = $this->customerWithBalance(1_000_000);
        $account = $this->freeze($customer, 'reconciliation_drift:balance_mismatch');

        $result = app(UsdtBalanceAccountUnfreeze::class)->unfreeze($customer, 'drift repaired', 'operator-a');

        $this->assertSame(UsdtBalanceUnfreezeStatus::Unfrozen, $result->status);
        $this->assertSame('reconciliation_drift:balance_mismatch', $result->frozenReason);
        $this->assertNull($account->refresh()->frozen_reason);
        $this->assertSame(1_000_000, (int) $account->refresh()->balance_micro, 'Unfreeze never rewrites money.');
        $this->assertSame(1, AuditLog::query()->where('action', 'usdt_balance.account_unfrozen')->count());
    }

    public function test_unfreeze_refuses_while_the_drift_is_still_present(): void
    {
        $customer = $this->customerWithBalance(1_000_000);
        DB::table('customer_usdt_balance_accounts')->where('customer_id', $customer->id)->update(['balance_micro' => 999_000]);
        $account = $this->freeze($customer, 'reconciliation_drift:balance_mismatch');

        $result = app(UsdtBalanceAccountUnfreeze::class)->unfreeze($customer, 'attempt', 'operator-a');

        $this->assertSame(UsdtBalanceUnfreezeStatus::DriftRemains, $result->status);
        $this->assertContains('balance_mismatch', $result->issues);
        $this->assertSame('reconciliation_drift:balance_mismatch', $account->refresh()->frozen_reason);
        $this->assertSame(999_000, (int) $account->refresh()->balance_micro, 'The drifted projection is never repaired.');
    }

    public function test_unfreeze_never_clears_a_reorg_freeze(): void
    {
        $customer = $this->customerWithBalance(1_000_000);
        $account = $this->freeze($customer, 'usdt_reorg_incident:7');

        $result = app(UsdtBalanceAccountUnfreeze::class)->unfreeze($customer, 'attempt', 'operator-a');

        $this->assertSame(UsdtBalanceUnfreezeStatus::ReorgHold, $result->status);
        $this->assertSame('usdt_reorg_incident:7', $account->refresh()->frozen_reason);
    }

    public static function nonDriftFreezeReasons(): array
    {
        return [
            'unknown safety hold' => ['security_review:7'],
            'unrecognized drift cause' => ['reconciliation_drift:unknown'],
            'empty reason' => [''],
        ];
    }

    #[DataProvider('nonDriftFreezeReasons')]
    public function test_unfreeze_refuses_any_unrecognized_freeze_reason(string $reason): void
    {
        $customer = $this->customerWithBalance(1_000_000);
        $account = $this->freeze($customer, $reason);
        foreach ([true, false] as $dryRun) {
            $result = app(UsdtBalanceAccountUnfreeze::class)->unfreeze($customer, 'attempt', 'operator-a', dryRun: $dryRun);
            $this->assertSame(UsdtBalanceUnfreezeStatus::UnsupportedFreeze, $result->status);
            $this->assertSame($reason, $account->refresh()->frozen_reason);
        }
        $this->assertSame(0, AuditLog::query()->where('action', 'usdt_balance.account_unfrozen')->count());
    }

    public function test_unfreeze_refuses_receiver_reorg_marker_even_without_evidence(): void
    {
        $customer = $this->customerWithBalance(1_000_000);
        $account = $this->freeze($customer, 'usdt_receiver_reorg:7');
        foreach ([true, false] as $dryRun) {
            $result = app(UsdtBalanceAccountUnfreeze::class)->unfreeze($customer, 'attempt', 'operator-a', dryRun: $dryRun);
            $this->assertSame(UsdtBalanceUnfreezeStatus::ReorgHold, $result->status);
            $this->assertSame('usdt_receiver_reorg:7', $account->refresh()->frozen_reason);
        }
    }

    public function test_dry_run_proves_eligibility_without_clearing_the_freeze(): void
    {
        $customer = $this->customerWithBalance(1_000_000);
        $account = $this->freeze($customer, 'reconciliation_drift:balance_mismatch');

        $result = app(UsdtBalanceAccountUnfreeze::class)->unfreeze($customer, 'preview', 'operator-a', dryRun: true);

        $this->assertSame(UsdtBalanceUnfreezeStatus::DryRunEligible, $result->status);
        $this->assertSame('reconciliation_drift:balance_mismatch', $account->refresh()->frozen_reason);
        $this->assertSame(0, AuditLog::query()->where('action', 'usdt_balance.account_unfrozen')->count());
    }

    public function test_unfreeze_reports_missing_and_unfrozen_accounts(): void
    {
        $customer = Customer::factory()->create();
        $service = app(UsdtBalanceAccountUnfreeze::class);

        $this->assertSame(UsdtBalanceUnfreezeStatus::MissingAccount, $service->unfreeze($customer, 'attempt', 'operator-a')->status);

        $this->customerWithBalance(1_000_000, $customer);
        $this->assertSame(UsdtBalanceUnfreezeStatus::NotFrozen, $service->unfreeze($customer, 'attempt', 'operator-a')->status);
    }

    public function test_command_reports_eligibility_and_fails_closed_on_remaining_drift(): void
    {
        $eligible = $this->customerWithBalance(1_000_000);
        $this->freeze($eligible, 'reconciliation_drift:balance_mismatch');

        $this->artisan('usdt-balance:unfreeze', [
            '--customer' => (string) $eligible->id,
            '--reason' => 'drill',
            '--actor' => 'operator-a',
            '--dry-run' => true,
        ])->assertExitCode(0);

        $drifted = $this->customerWithBalance(1_000_000);
        DB::table('customer_usdt_balance_accounts')->where('customer_id', $drifted->id)->update(['balance_micro' => 1]);
        $this->freeze($drifted, 'reconciliation_drift:balance_mismatch');

        $this->artisan('usdt-balance:unfreeze', [
            '--customer' => (string) $drifted->id,
            '--reason' => 'drill',
            '--actor' => 'operator-a',
        ])->assertExitCode(1);

        $this->artisan('usdt-balance:unfreeze', [
            '--customer' => 'not-a-number',
            '--reason' => 'drill',
            '--actor' => 'operator-a',
        ])->assertExitCode(1);
    }

    private function customerWithBalance(int $micro, ?Customer $customer = null): Customer
    {
        $customer ??= Customer::factory()->create();
        app(CustomerUsdtBalanceLedger::class)->creditVerifiedTopUp(
            $customer,
            new VerifiedUsdtFunding((int) $customer->id, UsdtMicroAmount::fromUnits($micro), 'topup-'.$customer->id, 'receipt-'.$customer->id, 'v1'),
            'usdt:topup-'.$customer->id,
        );

        return $customer;
    }

    private function freeze(Customer $customer, string $reason): CustomerUsdtBalanceAccount
    {
        DB::table('customer_usdt_balance_accounts')
            ->where('customer_id', $customer->id)
            ->update(['frozen_reason' => $reason]);

        return CustomerUsdtBalanceAccount::query()->where('customer_id', $customer->id)->sole();
    }
}
