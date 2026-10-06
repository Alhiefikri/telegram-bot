<?php

namespace Tests\Feature;

use App\Enums\BalanceAlertState;
use App\Enums\BalanceOperationMetric;
use App\Enums\CustomerUsdtFundingPauseScope;
use App\Enums\CustomerUsdtFundingRail;
use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Enums\UsdtBalanceUnfreezeStatus;
use App\Enums\UsdtReconciliationVerdict;
use App\Enums\UserRole;
use App\Exceptions\CustomerUsdtFundingUnavailable;
use App\Models\BalanceOperationEvent;
use App\Models\BalanceOperationsAlertEpisode;
use App\Models\Customer;
use App\Models\CustomerUsdtFundingReservation;
use App\Models\TelegramOutboundEffect;
use App\Models\UsdtBalanceReconciliationRun;
use App\Models\User;
use App\Services\Operations\BalanceOperationsAlertService;
use App\Services\Operations\BalanceRestoreGateService;
use App\Services\Operations\UsdtBalanceAccountUnfreeze;
use App\Services\Telegram\TelegramOutboundEffectLedgerProtocol;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\VerifiedUsdtFunding;
use App\Services\UsdtFunding\CustomerUsdtFundingPolicyManager;
use App\Services\UsdtFunding\CustomerUsdtFundingReservationService;
use App\Services\UsdtFunding\UsdtFundingRailReadiness;
use App\Services\UsdtFunding\UsdtFundingRailReadinessObservation;
use App\Services\UsdtFunding\UsdtFundingRailReadinessRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RunsPostgresWorkers;
use Tests\TestCase;

/**
 * O2 #558 process-level PostgreSQL races: the operational controls must stay
 * coherent when several workers or schedulers act at once. SQLite proves none
 * of this; the disposable PostgreSQL lane does.
 */
#[Group('concurrency')]
#[Group('postgres-release')]
final class BalanceOperationsPostgresConcurrencyTest extends TestCase
{
    use RunsPostgresWorkers;

    /** @var list<int> */
    private array $createdCustomerIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql' || (string) env('RUN_CONCURRENCY_TESTS', '') !== '1') {
            return;
        }

        $this->assertSafeConcurrencyDatabase('Balance operations');
        Queue::fake();
        config()->set('services.telegram.admin_telegram_chat_id', '900100200');

        if (! Schema::hasTable('balance_operation_events')) {
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        }
    }

    protected function tearDown(): void
    {
        $this->cleanupFixtures();

        parent::tearDown();
    }

    public function test_postgres_restore_gate_blocks_concurrent_reservations(): void
    {
        $this->requirePostgresConcurrency('Balance operations');

        $customer = $this->createCustomer(908201);
        $this->enableFunding();
        $this->registerReadiness(CustomerUsdtFundingRail::BscUsdt, ready: true);
        app(BalanceRestoreGateService::class)->open(now()->subHours(2), now()->subHour(), 'pg race drill', 'operator-a');

        $results = $this->runConcurrentWorkers(3, function (int $index) use ($customer): string {
            try {
                app(CustomerUsdtFundingReservationService::class)->reserve(
                    Customer::query()->findOrFail($customer->id),
                    CustomerUsdtFundingRail::BscUsdt,
                    UsdtMicroAmount::fromUnits(1_000_000),
                    'usdt_funding_intent',
                    'race-restore-'.$index,
                );

                return 'reserved';
            } catch (CustomerUsdtFundingUnavailable $exception) {
                return 'blocked:'.$exception->reason->value;
            }
        });

        $this->assertWorkersSucceeded($results);
        foreach ($results as $result) {
            $this->assertSame('blocked:restore_gate_open', $result['value'] ?? null);
        }
        $this->assertSame(0, CustomerUsdtFundingReservation::query()->count());
    }

    public function test_postgres_delivery_pause_defers_concurrent_claims(): void
    {
        $this->requirePostgresConcurrency('Balance operations');

        $effect = $this->pendingEffect('pg-pause-receipt');
        $this->pauseScope(CustomerUsdtFundingPauseScope::Delivery, 'delivery race drill');

        $results = $this->runConcurrentWorkers(2, function () use ($effect): string {
            return app(TelegramOutboundEffectLedgerProtocol::class)->claim((string) $effect->id) === null
                ? 'deferred'
                : 'claimed';
        });

        $this->assertWorkersSucceeded($results);
        foreach ($results as $result) {
            $this->assertSame('deferred', $result['value'] ?? null);
        }

        $effect->refresh();
        $this->assertSame(TelegramOutboundEffectState::Pending, $effect->state);
        $this->assertSame(0, (int) $effect->attempts);

        $this->resumeScope(CustomerUsdtFundingPauseScope::Delivery);

        $claimed = app(TelegramOutboundEffectLedgerProtocol::class)->claim((string) $effect->id);
        $this->assertNotNull($claimed, 'The deferred effect must be deliverable after the pause resumes.');
        $this->assertSame(1, (int) $claimed->attempts);
    }

    public function test_postgres_concurrent_alert_evaluation_creates_one_episode_and_effect(): void
    {
        $this->requirePostgresConcurrency('Balance operations');

        UsdtBalanceReconciliationRun::query()->create([
            'scope' => 'full',
            'limit' => 200,
            'checked_at' => now(),
            'created_at' => now(),
            'verdict' => UsdtReconciliationVerdict::Findings->value,
            'account_finding_count' => 1,
            'findings' => ['currency' => 'USDT', 'treasury' => ['unexplained_micro' => '0']],
        ]);

        $results = $this->runConcurrentWorkers(3, function (): string {
            $evaluation = app(BalanceOperationsAlertService::class)->evaluate();

            return 'notified:'.$evaluation->notified;
        });

        $this->assertWorkersSucceeded($results);
        $this->assertSame(1, BalanceOperationsAlertEpisode::query()->count());
        $this->assertSame(BalanceAlertState::Open, BalanceOperationsAlertEpisode::query()->sole()->state);
        $this->assertSame(
            1,
            TelegramOutboundEffect::query()
                ->where('effect_type', TelegramOutboundEffectType::BalanceOperationsAlert->value)
                ->count(),
        );
    }

    public function test_postgres_unfreeze_race_clears_the_freeze_once(): void
    {
        $this->requirePostgresConcurrency('Balance operations');

        $customer = $this->createCustomer(908202);
        app(CustomerUsdtBalanceLedger::class)->creditVerifiedTopUp(
            $customer,
            new VerifiedUsdtFunding((int) $customer->id, UsdtMicroAmount::fromUnits(1_000_000), 'pg-unfreeze', 'pg-unfreeze-receipt', 'v1'),
            'usdt:pg-unfreeze',
        );
        DB::table('customer_usdt_balance_accounts')
            ->where('customer_id', $customer->id)
            ->update(['frozen_reason' => 'reconciliation_drift:balance_mismatch']);

        $results = $this->runConcurrentWorkers(2, function () use ($customer): string {
            return app(UsdtBalanceAccountUnfreeze::class)
                ->unfreeze(Customer::query()->findOrFail($customer->id), 'race drill', 'operator-a')
                ->status
                ->value;
        });

        $this->assertWorkersSucceeded($results);
        $statuses = array_map(static fn (array $result): string => (string) $result['value'], $results);
        sort($statuses);
        $this->assertSame([UsdtBalanceUnfreezeStatus::NotFrozen->value, UsdtBalanceUnfreezeStatus::Unfrozen->value], $statuses);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'usdt_balance.account_unfrozen')->count());
        $this->assertNull(DB::table('customer_usdt_balance_accounts')->where('customer_id', $customer->id)->value('frozen_reason'));
    }

    public function test_postgres_metric_events_reject_update_and_allow_retention_prune(): void
    {
        $this->requirePostgresConcurrency('Balance operations');

        $event = BalanceOperationEvent::query()->create([
            'metric' => BalanceOperationMetric::LedgerOutcome,
            'outcome' => 'posted',
            'bucket' => null,
            'value' => null,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        $this->assertDatabaseConstraintRejects(
            fn () => DB::table('balance_operation_events')->where('id', $event->id)->update(['outcome' => 'replayed']),
            'append-only',
        );

        // DELETE stays available for the bounded retention prune.
        $this->assertSame(1, DB::table('balance_operation_events')->where('id', $event->id)->delete());
    }

    private function cleanupFixtures(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::table('balance_operation_events')->delete();
        DB::table('balance_operations_alert_episodes')->delete();
        DB::table('balance_restore_gates')->update([
            'state' => 'cleared',
            'window_start' => null,
            'window_end' => null,
            'reason' => null,
            'opened_by' => null,
            'opened_at' => null,
            'replay_completed_at' => null,
            'replay_by' => null,
            'replay_evidence' => null,
            'cleared_at' => null,
            'cleared_by' => null,
            'cleared_evidence' => null,
            'updated_at' => now(),
        ]);
        DB::table('telegram_outbound_effects')->where('source_type', Customer::class)->delete();
        DB::statement('TRUNCATE customer_usdt_funding_reservations RESTART IDENTITY CASCADE');
        DB::table('customer_usdt_funding_pauses')->delete();
        DB::table('customer_usdt_funding_policies')->update([
            'funding_enabled' => false,
            'confirmation_ttl_seconds' => 600,
            'application_fee_micro' => 0,
            'terms_version' => 1,
            'version' => 0,
            'updated_at' => now(),
        ]);

        if ($this->createdCustomerIds !== []) {
            DB::statement('TRUNCATE customer_usdt_balance_accounting_events, customer_usdt_balance_entries, customer_usdt_balance_accounts RESTART IDENTITY CASCADE');
            Customer::query()->whereIn('id', $this->createdCustomerIds)->delete();
            $this->createdCustomerIds = [];
        }
    }

    private function createCustomer(int $telegramId): Customer
    {
        $customer = Customer::factory()->create(['telegram_id' => $telegramId]);
        $this->createdCustomerIds[] = (int) $customer->id;

        return $customer;
    }

    private function pendingEffect(string $key): TelegramOutboundEffect
    {
        return TelegramOutboundEffect::query()->create([
            'effect_key' => $key,
            'effect_group_key' => $key,
            'sequence' => 1,
            'effect_type' => TelegramOutboundEffectType::UsdtTopUpReceipt->value,
            'source_type' => Customer::class,
            'source_id' => $key,
            'target_type' => 'customer',
            'telegram_chat_id' => '908299',
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

    private function enableFunding(): void
    {
        $manager = app(CustomerUsdtFundingPolicyManager::class);
        $state = $manager->current();
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $manager->change(
            true,
            $state->confirmationTtlSeconds,
            $state->applicationFeeMicro,
            $manager->termsBody($state->termsVersion),
            $state->version,
            $owner,
            null,
        );
    }

    private function pauseScope(CustomerUsdtFundingPauseScope $scope, string $reason): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        app(CustomerUsdtFundingPolicyManager::class)->pause($scope, $reason, $owner, null);
    }

    private function resumeScope(CustomerUsdtFundingPauseScope $scope): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        app(CustomerUsdtFundingPolicyManager::class)->resume($scope, $owner, null);
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
    }
}
