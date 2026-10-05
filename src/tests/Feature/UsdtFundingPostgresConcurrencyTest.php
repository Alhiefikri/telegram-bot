<?php

namespace Tests\Feature;

use App\Enums\CustomerUsdtFundingPauseScope;
use App\Enums\CustomerUsdtFundingRail;
use App\Enums\CustomerUsdtFundingReservationState;
use App\Enums\UserRole;
use App\Exceptions\CustomerUsdtFundingPolicyConflict;
use App\Exceptions\CustomerUsdtFundingReservationConflict;
use App\Exceptions\CustomerUsdtFundingUnavailable;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerUsdtBalanceEntry;
use App\Models\CustomerUsdtFundingReservation;
use App\Models\Order;
use App\Models\User;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\VerifiedUsdtFunding;
use App\Services\UsdtFunding\CustomerUsdtFundingPolicyManager;
use App\Services\UsdtFunding\CustomerUsdtFundingRelease;
use App\Services\UsdtFunding\CustomerUsdtFundingReservationService;
use App\Services\UsdtFunding\UsdtFundingRailReadiness;
use App\Services\UsdtFunding\UsdtFundingRailReadinessObservation;
use App\Services\UsdtFunding\UsdtFundingRailReadinessRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RunsPostgresWorkers;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
final class UsdtFundingPostgresConcurrencyTest extends TestCase
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

        $this->assertSafeConcurrencyDatabase('Customer USDT funding');
        if (! Schema::hasTable('customer_usdt_funding_reservations')) {
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        }
    }

    protected function tearDown(): void
    {
        $this->cleanupFixtures();

        parent::tearDown();
    }

    public function test_postgres_concurrent_reservations_open_exactly_one_target(): void
    {
        $this->requirePostgresConcurrency('Customer USDT funding');

        $customer = $this->createCustomer(907101);
        $this->enableFunding();
        $this->registerReadiness(CustomerUsdtFundingRail::BscUsdt, ready: true);

        $results = $this->runConcurrentWorkers(3, function (int $index) use ($customer): string {
            $result = app(CustomerUsdtFundingReservationService::class)->reserve(
                Customer::query()->findOrFail($customer->id),
                CustomerUsdtFundingRail::BscUsdt,
                UsdtMicroAmount::fromUnits(1_000_000),
                'usdt_funding_intent',
                'intent-race-'.$index,
            );

            return $result->outcome->value;
        });

        $this->assertWorkersSucceeded($results);
        $outcomes = array_map(static fn (array $result): string => (string) ($result['value'] ?? ''), $results);
        $this->assertSame(1, count(array_filter($outcomes, static fn (string $outcome): bool => $outcome === 'created')), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(2, count(array_filter($outcomes, static fn (string $outcome): bool => $outcome === 'existing_active')), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, CustomerUsdtFundingReservation::query()->count());
        $this->assertSame(1, CustomerUsdtFundingReservation::query()->whereNull('resolved_at')->count());
    }

    public function test_postgres_stale_admin_updates_allow_exactly_one_winner(): void
    {
        $this->requirePostgresConcurrency('Customer USDT funding');

        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $manager = app(CustomerUsdtFundingPolicyManager::class);
        $base = $manager->current();
        $auditsBefore = AuditLog::query()->where('action', 'customer_usdt.funding_policy.updated')->count();

        $results = $this->runConcurrentWorkers(2, function (int $index) use ($owner, $manager, $base): string {
            try {
                $change = $manager->change(
                    ! $base->fundingEnabled,
                    600 + ($index * 60),
                    $base->applicationFeeMicro,
                    $manager->termsBody($base->termsVersion),
                    $base->version,
                    $owner,
                    null,
                );

                return $change->changed ? 'Applied' : 'Noop';
            } catch (CustomerUsdtFundingPolicyConflict) {
                return 'Conflict';
            }
        });

        $this->assertWorkersSucceeded($results);
        $outcomes = array_map(static fn (array $result): string => (string) ($result['value'] ?? ''), $results);
        sort($outcomes);
        $this->assertSame(['Applied', 'Conflict'], $outcomes, json_encode($results, JSON_THROW_ON_ERROR));

        $current = $manager->current();
        $this->assertSame($base->version + 1, $current->version);
        $this->assertSame($auditsBefore + 1, AuditLog::query()->where('action', 'customer_usdt.funding_policy.updated')->count());
        $this->assertSame(0, CustomerUsdtFundingReservation::query()->count());
        $this->assertSame(0, CustomerUsdtBalanceEntry::query()->count());
    }

    public function test_postgres_pause_and_reserve_are_serialized(): void
    {
        $this->requirePostgresConcurrency('Customer USDT funding');

        $customer = $this->createCustomer(907103);
        $this->enableFunding();
        $this->registerReadiness(CustomerUsdtFundingRail::BscUsdt, ready: true);
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        // Deterministic half: a committed pause blocks every new target.
        app(CustomerUsdtFundingPolicyManager::class)->pause(
            CustomerUsdtFundingPauseScope::Funding,
            'Insiden penyedia.',
            $owner,
            null,
        );

        $blocked = $this->runConcurrentWorkers(2, function (int $index) use ($customer): string {
            try {
                app(CustomerUsdtFundingReservationService::class)->reserve(
                    Customer::query()->findOrFail($customer->id),
                    CustomerUsdtFundingRail::BscUsdt,
                    UsdtMicroAmount::fromUnits(1_000_000),
                    'usdt_funding_intent',
                    'intent-blocked-'.$index,
                );

                return 'Created';
            } catch (CustomerUsdtFundingUnavailable $exception) {
                return 'Blocked:'.$exception->reason->value;
            }
        });

        $this->assertWorkersSucceeded($blocked);
        $this->assertSame(['Blocked:funding_paused', 'Blocked:funding_paused'], array_map(
            static fn (array $result): string => (string) ($result['value'] ?? ''),
            $blocked,
        ));
        $this->assertSame(0, CustomerUsdtFundingReservation::query()->count());

        // Racing half: pause vs reserve must never leave a second target open.
        app(CustomerUsdtFundingPolicyManager::class)->resume(CustomerUsdtFundingPauseScope::Funding, $owner, null);

        $race = $this->runConcurrentWorkers(2, function (int $index) use ($customer, $owner): string {
            if ($index === 0) {
                $applied = app(CustomerUsdtFundingPolicyManager::class)->pause(
                    CustomerUsdtFundingPauseScope::Funding,
                    'Jeda balapan.',
                    $owner,
                    null,
                );

                return $applied ? 'Paused' : 'AlreadyPaused';
            }

            try {
                $result = app(CustomerUsdtFundingReservationService::class)->reserve(
                    Customer::query()->findOrFail($customer->id),
                    CustomerUsdtFundingRail::BscUsdt,
                    UsdtMicroAmount::fromUnits(1_000_000),
                    'usdt_funding_intent',
                    'intent-race-pause',
                );

                return 'Created:'.$result->outcome->value;
            } catch (CustomerUsdtFundingUnavailable $exception) {
                return 'Blocked:'.$exception->reason->value;
            }
        });

        $this->assertWorkersSucceeded($race);
        $this->assertLessThanOrEqual(1, CustomerUsdtFundingReservation::query()->whereNull('resolved_at')->count());
        $this->assertSame(1, DB::table('customer_usdt_funding_pauses')->where('scope', 'funding')->count());
    }

    public function test_postgres_settle_and_release_race_keep_one_resolution(): void
    {
        $this->requirePostgresConcurrency('Customer USDT funding');

        $customer = $this->createCustomer(907104);
        $this->enableFunding();
        $this->registerReadiness(CustomerUsdtFundingRail::BscUsdt, ready: true);

        $created = app(CustomerUsdtFundingReservationService::class)->reserve(
            $customer,
            CustomerUsdtFundingRail::BscUsdt,
            UsdtMicroAmount::fromUnits(1_000_000),
            'usdt_funding_intent',
            'intent-settle-release-race',
        );
        $reservationId = $created->reservation->reservationId;

        $credit = app(CustomerUsdtBalanceLedger::class)->creditVerifiedTopUp(
            $customer,
            new VerifiedUsdtFunding((int) $customer->id, UsdtMicroAmount::fromUnits(1_000_000), 'topup-settle-release-race', 'receipt-settle-release-race', 'v1'),
            'usdt:funding-settle-release-race',
        );

        $results = $this->runConcurrentWorkers(2, function (int $index) use ($customer, $reservationId, $credit): string {
            try {
                if ($index === 0) {
                    $settled = app(CustomerUsdtFundingReservationService::class)->markSettled(
                        Customer::query()->findOrFail($customer->id),
                        $reservationId,
                        $credit->entryId,
                        'topup-settle-release-race',
                    );

                    return 'Settled:'.$settled->outcome->value;
                }

                $released = app(CustomerUsdtFundingReservationService::class)->release(
                    Customer::query()->findOrFail($customer->id),
                    $reservationId,
                    CustomerUsdtFundingRelease::compensated('review-race', 'owner:1'),
                );

                return 'Released:'.$released->outcome->value;
            } catch (CustomerUsdtFundingReservationConflict) {
                return 'Conflict';
            }
        });

        $this->assertWorkersSucceeded($results);
        $values = array_map(static fn (array $result): string => (string) ($result['value'] ?? ''), $results);
        $conflicts = array_filter($values, static fn (string $value): bool => $value === 'Conflict');
        $resolutions = array_values(array_filter($values, static fn (string $value): bool => str_starts_with($value, 'Settled:') || str_starts_with($value, 'Released:')));
        $this->assertCount(1, $conflicts, json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertCount(1, $resolutions, json_encode($results, JSON_THROW_ON_ERROR));

        $reservation = CustomerUsdtFundingReservation::query()->sole();
        if (str_starts_with($resolutions[0], 'Settled:')) {
            $this->assertSame(CustomerUsdtFundingReservationState::Settled, $reservation->state);
            $this->assertSame($credit->entryId, (int) $reservation->settled_entry_id);
        } else {
            $this->assertSame(CustomerUsdtFundingReservationState::Released, $reservation->state);
            $this->assertNull($reservation->settled_entry_id);
        }

        $this->assertSame(1, CustomerUsdtBalanceEntry::query()->count());
    }

    public function test_postgres_expiry_never_releases_the_slot_even_with_late_money(): void
    {
        $this->requirePostgresConcurrency('Customer USDT funding');

        $customer = $this->createCustomer(907105);
        $this->enableFunding(ttlSeconds: 60);
        $this->registerReadiness(CustomerUsdtFundingRail::BscUsdt, ready: true);

        $created = app(CustomerUsdtFundingReservationService::class)->reserve(
            $customer,
            CustomerUsdtFundingRail::BscUsdt,
            UsdtMicroAmount::fromUnits(1_000_000),
            'usdt_funding_intent',
            'intent-expiry-race',
        );

        $this->travel(120)->seconds();

        $held = app(CustomerUsdtFundingReservationService::class)->holdExpired(10);
        $this->assertSame(1, $held);
        $this->assertSame(
            CustomerUsdtFundingReservationState::ExpiredUnresolved,
            CustomerUsdtFundingReservation::query()->sole()->state,
        );

        // A new target attempts the same slot and must receive the held target.
        $attempt = app(CustomerUsdtFundingReservationService::class)->reserve(
            $customer,
            CustomerUsdtFundingRail::BscUsdt,
            UsdtMicroAmount::fromUnits(1_000_000),
            'usdt_funding_intent',
            'intent-after-expiry-race',
        );
        $this->assertSame('existing_active', $attempt->outcome->value);
        $this->assertSame($created->reservation->reservationId, $attempt->reservation->reservationId);
        $this->assertSame(1, CustomerUsdtFundingReservation::query()->count());

        // Late verified money still resolves the held target and frees the slot.
        $entry = app(CustomerUsdtBalanceLedger::class)->creditVerifiedTopUp(
            $customer,
            new VerifiedUsdtFunding((int) $customer->id, UsdtMicroAmount::fromUnits(1_000_000), 'topup-expiry-race', 'receipt-expiry-race', 'v1'),
            'usdt:funding-expiry-race',
        );
        $settled = app(CustomerUsdtFundingReservationService::class)->markSettled(
            $customer,
            $created->reservation->reservationId,
            $entry->entryId,
            'topup-expiry-race',
        );
        $this->assertSame(CustomerUsdtFundingReservationState::Settled, $settled->reservation->state);
    }

    public function test_postgres_constraints_and_triggers_protect_funding_state(): void
    {
        $this->requirePostgresConcurrency('Customer USDT funding');

        $customer = $this->createCustomer(907106);
        $this->enableFunding();
        $this->registerReadiness(CustomerUsdtFundingRail::BscUsdt, ready: true);

        $created = app(CustomerUsdtFundingReservationService::class)->reserve(
            $customer,
            CustomerUsdtFundingRail::BscUsdt,
            UsdtMicroAmount::fromUnits(1_000_000),
            'usdt_funding_intent',
            'intent-constraints',
        );
        $reservationId = $created->reservation->reservationId;

        $baseRow = [
            'customer_id' => $customer->id,
            'source_type' => 'usdt_funding_intent',
            'rail' => 'bsc_usdt',
            'amount_micro' => 1_000_000,
            'fee_micro' => 0,
            'policy_version' => 1,
            'terms_version' => 1,
            'request_fingerprint' => str_repeat('a', 64),
            'state' => 'reserved',
            'reserved_at' => now(),
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $this->assertDatabaseConstraintRejects(
            fn () => DB::table('customer_usdt_funding_reservations')->insert($baseRow + ['source_id' => 'intent-second-open']),
            'customer_usdt_funding_reservations_active_customer_unique',
        );

        $this->assertDatabaseConstraintRejects(
            fn () => DB::table('customer_usdt_funding_reservations')->insert(['amount_micro' => -1] + $baseRow + ['source_id' => 'intent-bad-amount']),
            'customer_usdt_funding_reservations_amount_positive',
        );

        $this->assertDatabaseConstraintRejects(
            fn () => DB::table('customer_usdt_funding_reservations')->insert(['rail' => 'bitcoin'] + $baseRow + ['source_id' => 'intent-bad-rail']),
            'customer_usdt_funding_reservations_rail_check',
        );

        $this->assertDatabaseConstraintRejects(
            fn () => DB::table('customer_usdt_funding_reservations')->insert([
                'source_id' => 'intent-bad-state',
                'state' => 'settled',
                'resolution_kind' => 'settled',
            ] + $baseRow),
            'customer_usdt_funding_reservations_state_check',
        );

        $this->assertDatabaseConstraintRejects(
            fn () => DB::table('customer_usdt_funding_reservations')->where('id', $reservationId)->update(['amount_micro' => 2_000_000]),
            'immutable',
        );

        $this->assertDatabaseConstraintRejects(
            fn () => DB::table('customer_usdt_funding_reservations')->where('id', $reservationId)->delete(),
            'cannot be deleted',
        );

        $this->assertDatabaseConstraintRejects(
            fn () => DB::table('customer_usdt_funding_reservations')->where('id', $reservationId)->update([
                'state' => 'settled',
                'resolution_kind' => 'settled',
            ]),
            'customer_usdt_funding_reservations_state_check',
        );

        $this->assertDatabaseConstraintRejects(
            fn () => DB::table('customer_usdt_funding_pauses')->insert([
                'scope' => 'wallet_backdoor',
                'reason' => 'Scope tidak dikenal.',
                'paused_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'customer_usdt_funding_pauses_scope_check',
        );

        $this->assertDatabaseConstraintRejects(
            fn () => DB::table('customer_usdt_funding_terms')->where('version', 1)->update(['body' => 'diubah']),
            'immutable',
        );

        $this->assertDatabaseConstraintRejects(
            fn () => DB::table('customer_usdt_funding_terms')->where('version', 1)->delete(),
            'immutable',
        );

        $this->assertSame(1, CustomerUsdtFundingReservation::query()->count());
        $this->assertSame(1_000_000, (int) CustomerUsdtFundingReservation::query()->sole()->amount_micro);
    }

    private function cleanupFixtures(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Truncate bypasses the row-level guards, which is the only supported
        // way to remove append-only test rows.
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
            Order::query()->whereIn('customer_id', $this->createdCustomerIds)->delete();
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

    private function enableFunding(int $ttlSeconds = 600): void
    {
        $manager = app(CustomerUsdtFundingPolicyManager::class);
        $state = $manager->current();
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $manager->change(
            true,
            $ttlSeconds,
            $state->applicationFeeMicro,
            $manager->termsBody($state->termsVersion),
            $state->version,
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
    }
}
