<?php

namespace Tests\Feature;

use App\Enums\CustomerUsdtFundingAvailabilityReason;
use App\Enums\CustomerUsdtFundingRail;
use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Enums\UserRole;
use App\Exceptions\CustomerUsdtFundingUnavailable;
use App\Models\Customer;
use App\Models\CustomerUsdtBalanceAccount;
use App\Models\CustomerUsdtBalanceEntry;
use App\Models\ExternalMoneyReceipt;
use App\Models\TelegramOutboundEffect;
use App\Models\UsdtBalanceReconciliationRun;
use App\Models\User;
use App\Services\Operations\BalanceRestoreGateReader;
use App\Services\Operations\BalanceRestoreGateService;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\VerifiedUsdtFunding;
use App\Services\UsdtFunding\CustomerUsdtFundingGate;
use App\Services\UsdtFunding\CustomerUsdtFundingPolicyManager;
use App\Services\UsdtFunding\CustomerUsdtFundingReservationService;
use App\Services\UsdtFunding\UsdtFundingRailReadiness;
use App\Services\UsdtFunding\UsdtFundingRailReadinessObservation;
use App\Services\UsdtFunding\UsdtFundingRailReadinessRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

final class BalanceRestoreGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_gate_blocks_new_funding_and_spending_and_keeps_reconciliation_running(): void
    {
        $customer = Customer::factory()->create();
        $this->enableFunding();
        $this->registerReadiness(CustomerUsdtFundingRail::BscUsdt, ready: true);

        $gate = app(BalanceRestoreGateService::class);
        $opened = $gate->open(now()->subHours(2), now()->subHour(), 'pitr drill', 'operator-a');

        $this->assertTrue($opened->isOpen());
        $this->assertSame('open', $opened->state->value);

        $availability = app(CustomerUsdtFundingGate::class)->availability(CustomerUsdtFundingRail::BscUsdt);
        $this->assertFalse($availability->available);
        $this->assertSame(CustomerUsdtFundingAvailabilityReason::RestoreGateOpen, $availability->reason);

        $spending = app(CustomerUsdtFundingGate::class)->spendingAvailability();
        $this->assertFalse($spending->available);
        $this->assertSame(CustomerUsdtFundingAvailabilityReason::RestoreGateOpen, $spending->reason);

        try {
            app(CustomerUsdtFundingReservationService::class)->reserve(
                $customer,
                CustomerUsdtFundingRail::BscUsdt,
                UsdtMicroAmount::fromUnits(1_000_000),
                'usdt_funding_intent',
                'intent-under-restore',
            );
            $this->fail('A new funding target must not start while the restore gate is open.');
        } catch (CustomerUsdtFundingUnavailable $exception) {
            $this->assertSame(CustomerUsdtFundingAvailabilityReason::RestoreGateOpen, $exception->reason);
        }

        // Reconciliation is never blocked by the gate; it records its run.
        $this->artisan('usdt-balance:reconcile', ['--json' => true])->assertExitCode(0);
        $this->assertSame(1, UsdtBalanceReconciliationRun::query()->count());
        $this->assertSame(0, CustomerUsdtBalanceAccount::query()->count());
    }

    public function test_restore_cannot_reopen_before_replay_and_a_clean_post_replay_run(): void
    {
        $gate = app(BalanceRestoreGateService::class);
        $gate->open(now()->subHours(2), now()->subHour(), 'pitr drill', 'operator-a');

        try {
            $gate->close('operator-a');
            $this->fail('Closing without a replay must be refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('external evidence replay', $exception->getMessage());
        }

        $dryRun = $gate->replay(25, 'operator-a', null, dryRun: true);
        $this->assertSame(0, $dryRun->settlementChecked);
        $this->assertNull($dryRun->replayCompletedAt, 'A dry run must not record replay completion.');

        $gate->replay(25, 'operator-a');

        try {
            $gate->close('operator-a');
            $this->fail('Closing without a post-replay reconciliation run must be refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('usdt-balance:reconcile', $exception->getMessage());
        }

        $this->recordRun(['verdict' => 'findings', 'account_finding_count' => 1]);

        try {
            $gate->close('operator-a');
            $this->fail('Closing with unexplained drift must be refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('unexplained drift', $exception->getMessage());
        }

        DB::table('usdt_balance_reconciliation_runs')->delete();
        $this->recordRun(['verdict' => 'consistent']);

        $closed = $gate->close('operator-a');
        $this->assertSame('cleared', $closed->state->value);
        $this->assertSame('operator-a', $closed->cleared_by);
        $this->assertIsArray($closed->cleared_evidence);
        $this->assertSame('consistent', $closed->cleared_evidence['reconciliation_verdict']);
        $this->assertSame(0, $closed->cleared_evidence['unexplained_micro']);

        // The restore window and replay evidence stay recorded after closing.
        $this->assertNotNull($closed->window_start);
        $this->assertNotNull($closed->replay_completed_at);

        $this->assertTrue(app(CustomerUsdtFundingGate::class)->spendingAvailability()->available);
    }

    public function test_replay_quarantines_ambiguous_balance_deliveries_without_touching_money(): void
    {
        $customer = Customer::factory()->create();
        $this->enableFunding();
        $this->registerReadiness(CustomerUsdtFundingRail::BscUsdt, ready: true);
        app(CustomerUsdtBalanceLedger::class)->creditVerifiedTopUp(
            $customer,
            new VerifiedUsdtFunding((int) $customer->id, UsdtMicroAmount::fromUnits(1_000_000), 'topup-restore', 'receipt-restore', 'v1'),
            'usdt:topup-restore',
        );
        $this->claimReceipt('topup-restore');

        $balanceReceipt = $this->effect(TelegramOutboundEffectType::UsdtTopUpReceipt, 'usdt-receipt-restore');
        $alert = $this->effect(TelegramOutboundEffectType::BalanceOperationsAlert, 'balance-alert-restore');
        $unrelated = $this->effect(TelegramOutboundEffectType::ReceiptCredentials, 'order-receipt-restore');

        $entries = CustomerUsdtBalanceEntry::query()->count();
        $receipts = ExternalMoneyReceipt::query()->count();

        $gate = app(BalanceRestoreGateService::class);
        $gate->open(now()->subHours(2), now()->subHour(), 'pitr drill', 'operator-a');
        $result = $gate->replay(25, 'operator-a');

        $this->assertSame(2, $result->outboundQuarantined);

        foreach ([$balanceReceipt, $alert] as $effect) {
            $effect->refresh();
            $this->assertSame(TelegramOutboundEffectState::Unknown, $effect->state);
            $this->assertSame('restore_ambiguous_delivery', $effect->last_error_code);
            $this->assertNull($effect->claim_token);
            $this->assertNull($effect->lease_until);
            $this->assertNotNull($effect->unknown_at);
        }

        $unrelated->refresh();
        $this->assertSame(TelegramOutboundEffectState::Pending, $unrelated->state);
        $this->assertNull($unrelated->last_error_code);

        // A restore replay never deletes or rewrites ledger rows, receipts or claims.
        $this->assertSame($entries, CustomerUsdtBalanceEntry::query()->count());
        $this->assertSame($receipts, ExternalMoneyReceipt::query()->count());
        $this->assertSame(1_000_000, (int) CustomerUsdtBalanceAccount::query()->where('customer_id', $customer->id)->sole()->balance_micro);
    }

    public function test_replay_dry_run_reports_without_writing_and_failed_scan_blocks_reopen(): void
    {
        $effect = $this->effect(TelegramOutboundEffectType::UsdtRefundReceipt, 'usdt-refund-restore');

        $gate = app(BalanceRestoreGateService::class);
        $gate->open(now()->subHours(2), now()->subHour(), 'pitr drill', 'operator-a');

        $dry = $gate->replay(25, 'operator-a', ['status' => 'completed', 'requests' => 3], dryRun: true);
        $this->assertSame(1, $dry->outboundQuarantined);
        $this->assertNull($dry->replayCompletedAt);
        $this->assertSame(TelegramOutboundEffectState::Pending, $effect->refresh()->state);

        $failedScan = $gate->replay(25, 'operator-a', ['status' => 'failed', 'requests' => 0]);
        $this->assertSame('failed', $failedScan->scanStatus);

        try {
            $gate->close('operator-a');
            $this->fail('A failed receiver scan must keep the gate closed.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('receiver evidence scan failed', $exception->getMessage());
        }
    }

    public function test_open_refuses_a_second_window_and_invalid_evidence(): void
    {
        $gate = app(BalanceRestoreGateService::class);
        $gate->open(now()->subHours(2), now()->subHour(), 'pitr drill', 'operator-a');

        $this->expectException(InvalidArgumentException::class);
        $gate->open(now()->subHour(), now(), 'second window', 'operator-a');
    }

    public function test_open_validates_window_order_and_required_reason(): void
    {
        $gate = app(BalanceRestoreGateService::class);

        try {
            $gate->open(now(), now()->subHour(), 'pitr drill', 'operator-a');
            $this->fail('A window that ends before it starts must be refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('must not precede', $exception->getMessage());
        }

        try {
            $gate->open(now()->subHours(2), now(), '   ', 'operator-a');
            $this->fail('An empty reason must be refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('reason', $exception->getMessage());
        }

        $this->assertSame('cleared', app(BalanceRestoreGateReader::class)->status()['state']);
    }

    public function test_reopen_is_refused_while_ambiguous_deliveries_remain_unquarantined(): void
    {
        $first = $this->effect(TelegramOutboundEffectType::UsdtTopUpReceipt, 'usdt-receipt-bounded-a');
        $second = $this->effect(TelegramOutboundEffectType::UsdtRefundReceipt, 'usdt-refund-bounded-b');

        $gate = app(BalanceRestoreGateService::class);
        $gate->open(now()->subHours(2), now()->subHour(), 'pitr drill', 'operator-a');

        // A bounded replay quarantines one effect and reports the remainder.
        $bounded = $gate->replay(1, 'operator-a');
        $this->assertSame(1, $bounded->outboundQuarantined);

        $evidence = app(BalanceRestoreGateReader::class)->status()['replay_evidence'];
        $this->assertSame(1, $evidence['outbound']['remaining']);

        $this->recordRun(['verdict' => 'consistent']);

        try {
            $gate->close('operator-a');
            $this->fail('A restore must not reopen while an ambiguous delivery is still dispatchable.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('still unquarantined', $exception->getMessage());
        }

        // The remaining effect must still be pending: nothing was delivered and
        // nothing was dropped while the gate stayed open.
        $this->assertSame(TelegramOutboundEffectState::Pending, $second->refresh()->state);

        $gate->replay(25, 'operator-a');
        $closed = $gate->close('operator-a');

        $this->assertSame('cleared', $closed->state->value);
        $this->assertSame(0, $closed->cleared_evidence['unquarantined_deliveries']);
        $this->assertSame(TelegramOutboundEffectState::Unknown, $first->refresh()->state);
        $this->assertSame(TelegramOutboundEffectState::Unknown, $second->refresh()->state);
    }

    public function test_complete_reconciliation_can_close_a_clean_restore(): void
    {
        $gate = app(BalanceRestoreGateService::class);
        $gate->open(now()->subHours(2), now()->subHour(), 'complete clean drill', 'operator-a');
        $gate->replay(25, 'operator-a');

        $this->artisan('usdt-balance:reconcile', [
            '--complete' => true,
            '--limit' => 1,
            '--no-freeze' => true,
        ])->assertSuccessful();

        $closed = $gate->close('operator-a');

        $this->assertSame('cleared', $closed->state->value);
        $this->assertSame(0, $closed->cleared_evidence['unquarantined_deliveries']);
    }

    /** @param  array<string, mixed>  $overrides */
    private function recordRun(array $overrides): void
    {
        UsdtBalanceReconciliationRun::query()->create([
            'scope' => 'full',
            'limit' => 200,
            'checked_at' => now()->addSecond(),
            'created_at' => now()->addSecond(),
            'verdict' => 'consistent',
            'accounts_inspected' => 0,
            'findings' => ['currency' => 'USDT', 'accounts' => ['has_more' => false], 'receipts' => ['receipts_has_more' => false, 'credits_have_more' => false], 'treasury' => ['unexplained_micro' => '0', 'verdict' => 'not_required']],
        ] + $overrides);
    }

    private function claimReceipt(string $topUpId): void
    {
        ExternalMoneyReceipt::query()->create([
            'canonical_key' => 'bsc:56:tx-restore:0',
            'provider' => 'bsc',
            'identity_kind' => 'bsc_log',
            'account_namespace' => 'operator',
            'reference' => 'tx-restore',
            'chain_id' => 56,
            'tx_hash' => '0x'.str_repeat('a', 64),
            'log_index' => 0,
            'beneficiary' => 'customer:'.$topUpId,
            'currency' => 'USDT',
            'amount_units' => '1000000000000000000',
            'decimals' => 18,
            'status' => 'claimed',
            'target_type' => 'customer_usdt_top_up',
            'target_id' => $topUpId,
            'fingerprint' => str_repeat('b', 64),
            'claimed_at' => now(),
        ]);
    }

    private function effect(TelegramOutboundEffectType $type, string $key): TelegramOutboundEffect
    {
        return TelegramOutboundEffect::query()->create([
            'effect_key' => $key,
            'effect_group_key' => $key,
            'sequence' => 1,
            'effect_type' => $type->value,
            'source_type' => Customer::class,
            'source_id' => $key,
            'target_type' => 'customer',
            'telegram_chat_id' => '910001',
            'queue' => 'notifications',
            'telegram_method' => 'sendMessage',
            'locale' => 'id',
            'template_version' => 'fixture-v1',
            'state' => TelegramOutboundEffectState::Pending->value,
            'attempts' => 0,
            'next_attempt_at' => now(),
            'outcome_metadata' => ['message' => 'fixture'],
        ]);
    }

    private function enableFunding(int $ttlSeconds = 600, int $feeMicro = 0): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        app(CustomerUsdtFundingPolicyManager::class)->change(
            true,
            $ttlSeconds,
            $feeMicro,
            'Syarat top-up pengujian.',
            0,
            $owner,
            null,
        );
    }

    private function registerReadiness(CustomerUsdtFundingRail $rail, bool $ready, ?string $reason = null): void
    {
        $alias = 'testing.usdt-funding-readiness.'.$rail->value;
        $provider = new class($rail->value, $ready, $reason) implements UsdtFundingRailReadiness
        {
            public function __construct(
                private readonly string $key,
                private readonly bool $ready,
                private readonly ?string $reason,
            ) {}

            public function railKey(): string
            {
                return $this->key;
            }

            public function assertRail(CustomerUsdtFundingRail $rail): void {}

            public function readiness(): UsdtFundingRailReadinessObservation
            {
                return $this->ready
                    ? UsdtFundingRailReadinessObservation::ready($this->key)
                    : UsdtFundingRailReadinessObservation::unavailable($this->key, $this->reason ?? 'not_ready');
            }
        };

        $this->app->instance($alias, $provider);
        $this->app->tag([$alias], 'usdt-funding-rail-readiness');
        $this->app->forgetInstance(UsdtFundingRailReadinessRegistry::class);
        app()->forgetInstance(CustomerUsdtFundingGate::class);
    }
}
