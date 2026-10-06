<?php

namespace Tests\Feature;

use App\Enums\CustomerUsdtFundingAvailabilityReason;
use App\Enums\CustomerUsdtFundingPauseScope;
use App\Enums\CustomerUsdtFundingRail;
use App\Enums\CustomerUsdtFundingReleaseKind;
use App\Enums\CustomerUsdtFundingReservationOutcome;
use App\Enums\CustomerUsdtFundingReservationState;
use App\Enums\UserRole;
use App\Exceptions\CustomerUsdtFundingReservationConflict;
use App\Exceptions\CustomerUsdtFundingUnavailable;
use App\Models\Customer;
use App\Models\CustomerUsdtBalanceEntry;
use App\Models\CustomerUsdtFundingReservation;
use App\Models\User;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtBalanceMutationResult;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\UsdtPurchase;
use App\Services\UsdtBalance\VerifiedUsdtFunding;
use App\Services\UsdtFunding\CustomerUsdtFundingGate;
use App\Services\UsdtFunding\CustomerUsdtFundingPolicyManager;
use App\Services\UsdtFunding\CustomerUsdtFundingRelease;
use App\Services\UsdtFunding\CustomerUsdtFundingReservationResult;
use App\Services\UsdtFunding\CustomerUsdtFundingReservationService;
use App\Services\UsdtFunding\UsdtFundingRailReadiness;
use App\Services\UsdtFunding\UsdtFundingRailReadinessObservation;
use App\Services\UsdtFunding\UsdtFundingRailReadinessRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

final class UsdtFundingReservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_policy_or_readiness_disables_new_targets_without_writing_rows(): void
    {
        $customer = Customer::factory()->create();
        $this->app->instance(UsdtFundingRailReadinessRegistry::class, new UsdtFundingRailReadinessRegistry([]));

        // Seeded policy is disabled; no readiness provider is registered.
        $this->assertUnavailable(
            fn () => $this->reserve($customer, 'intent-blocked'),
            CustomerUsdtFundingAvailabilityReason::FundingDisabled,
        );

        $this->enableFunding();
        $this->assertUnavailable(
            fn () => $this->reserve($customer, 'intent-blocked'),
            CustomerUsdtFundingAvailabilityReason::RailNotRegistered,
        );

        $this->assertSame(0, CustomerUsdtFundingReservation::query()->count());
        $this->assertSame(0, CustomerUsdtBalanceEntry::query()->count());
    }

    public function test_reserve_creates_a_single_durable_reservation_with_policy_snapshots(): void
    {
        $customer = Customer::factory()->create();
        $this->enableFunding(ttlSeconds: 600, feeMicro: 1_500_000);
        $this->registerReadiness(CustomerUsdtFundingRail::BscUsdt, ready: true);

        $result = $this->reserve($customer, 'intent-one');

        $this->assertSame(CustomerUsdtFundingReservationOutcome::Created, $result->outcome);
        $this->assertSame(1_000_000, $result->reservation->amountMicro);
        $this->assertSame(1_500_000, $result->reservation->feeMicro);
        $this->assertSame(CustomerUsdtFundingRail::BscUsdt, $result->reservation->rail);
        $this->assertSame(1, $result->reservation->policyVersion);
        $this->assertSame(2, $result->reservation->termsVersion);
        $this->assertSame(CustomerUsdtFundingReservationState::Reserved, $result->reservation->state);
        $this->assertSame('usdt_funding_intent', $result->reservation->sourceType);
        $this->assertSame('intent-one', $result->reservation->sourceId);

        $row = CustomerUsdtFundingReservation::query()->sole();
        $this->assertSame(600, (int) $row->reserved_at?->diffInSeconds($row->expires_at));
    }

    public function test_replay_returns_the_same_reservation_and_changed_payload_conflicts(): void
    {
        $customer = Customer::factory()->create();
        $this->enableFunding();
        $this->registerReadiness(CustomerUsdtFundingRail::BscUsdt, ready: true);
        $this->registerReadiness(CustomerUsdtFundingRail::BinancePay, ready: true);

        $created = $this->reserve($customer, 'intent-replay');
        $replayed = $this->reserve($customer, 'intent-replay');

        $this->assertSame(CustomerUsdtFundingReservationOutcome::Replayed, $replayed->outcome);
        $this->assertSame($created->reservation->reservationId, $replayed->reservation->reservationId);
        $this->assertSame(1, CustomerUsdtFundingReservation::query()->count());

        // Same source, changed amount.
        try {
            $this->reserve($customer, 'intent-replay', amountUnits: 2_000_000);
            $this->fail('A changed reservation amount was expected to conflict.');
        } catch (CustomerUsdtFundingReservationConflict) {
            // expected
        }

        // Same source, changed rail.
        try {
            $this->reserve($customer, 'intent-replay', rail: CustomerUsdtFundingRail::BinancePay);
            $this->fail('A changed reservation rail was expected to conflict.');
        } catch (CustomerUsdtFundingReservationConflict) {
            // expected
        }

        $this->assertSame(1, CustomerUsdtFundingReservation::query()->count());
        $this->assertSame(1_000_000, (int) CustomerUsdtFundingReservation::query()->sole()->amount_micro);
    }

    public function test_policy_change_between_attempts_requires_a_new_confirmation(): void
    {
        $customer = Customer::factory()->create();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->enableFunding();
        $this->registerReadiness(CustomerUsdtFundingRail::BscUsdt, ready: true);

        $this->reserve($customer, 'intent-stale');

        app(CustomerUsdtFundingPolicyManager::class)->change(
            true,
            900,
            0,
            'Syarat top-up pengujian versi dua.',
            1,
            $owner,
            null,
        );

        try {
            $this->reserve($customer, 'intent-stale');
            $this->fail('A stale policy snapshot was expected to conflict.');
        } catch (CustomerUsdtFundingReservationConflict) {
            // expected: the recorded target stays intact, the caller reads it
            // through findBySource() and asks for a new confirmation.
        }

        $existing = app(CustomerUsdtFundingReservationService::class)->findBySource('usdt_funding_intent', 'intent-stale');
        $this->assertNotNull($existing);
        $this->assertSame(1, $existing->reservation->policyVersion);
        $this->assertSame($existing->reservation->reservationId, (int) CustomerUsdtFundingReservation::query()->sole()->id);
    }

    public function test_second_source_returns_the_existing_active_target_across_rails(): void
    {
        $customer = Customer::factory()->create();
        $this->enableFunding();
        $this->registerReadiness(CustomerUsdtFundingRail::BscUsdt, ready: true);
        $this->registerReadiness(CustomerUsdtFundingRail::BinancePay, ready: true);

        $first = $this->reserve($customer, 'intent-first', rail: CustomerUsdtFundingRail::BscUsdt);
        $second = $this->reserve($customer, 'intent-second', rail: CustomerUsdtFundingRail::BinancePay);

        $this->assertSame(CustomerUsdtFundingReservationOutcome::ExistingActive, $second->outcome);
        $this->assertSame($first->reservation->reservationId, $second->reservation->reservationId);
        $this->assertSame(1, CustomerUsdtFundingReservation::query()->count());
    }

    public function test_expiry_holds_the_slot_and_late_settlement_still_resolves(): void
    {
        $customer = Customer::factory()->create();
        $this->enableFunding(ttlSeconds: 600);
        $this->registerReadiness(CustomerUsdtFundingRail::BscUsdt, ready: true);

        $created = $this->reserve($customer, 'intent-expiring');
        $this->travel(601)->seconds();

        $active = app(CustomerUsdtFundingReservationService::class)->activeForCustomer($customer);
        $this->assertNotNull($active);
        $this->assertSame(CustomerUsdtFundingReservationState::ExpiredUnresolved, $active->reservation->state);
        $this->assertSame($created->reservation->reservationId, $active->reservation->reservationId);

        // Expiry never releases: a new source still returns the held target.
        $second = $this->reserve($customer, 'intent-new');
        $this->assertSame(CustomerUsdtFundingReservationOutcome::ExistingActive, $second->outcome);
        $this->assertSame($created->reservation->reservationId, $second->reservation->reservationId);
        $this->assertSame(0, app(CustomerUsdtFundingReservationService::class)->holdExpired());

        // Late, verified settlement resolves the expired-but-held target.
        $credit = $this->credit($customer, 1_000_000, 'topup-expired', 'usdt:funding-expired');
        $settled = app(CustomerUsdtFundingReservationService::class)->markSettled(
            $customer,
            $created->reservation->reservationId,
            $credit->entryId,
            'topup-expired',
        );
        $this->assertSame(CustomerUsdtFundingReservationOutcome::Settled, $settled->outcome);
        $this->assertSame(CustomerUsdtFundingReservationState::Settled, $settled->reservation->state);
        $this->assertSame($credit->entryId, $settled->reservation->settledEntryId);

        // The settled slot is free for a new target.
        $after = $this->reserve($customer, 'intent-after');
        $this->assertSame(CustomerUsdtFundingReservationOutcome::Created, $after->outcome);
        $this->assertSame(2, CustomerUsdtFundingReservation::query()->count());
    }

    public function test_bounded_sweep_holds_due_reservations_and_command_reports_it(): void
    {
        $customer = Customer::factory()->create();
        $this->enableFunding();
        $this->registerReadiness(CustomerUsdtFundingRail::BscUsdt, ready: true);
        $this->reserve($customer, 'intent-sweep');
        $this->travel(601)->seconds();

        $this->artisan('usdt-funding:hold-expired-reservations', ['--limit' => 10, '--json' => true])
            ->expectsOutput('{"held":1}')
            ->assertExitCode(0);

        $this->assertSame(
            CustomerUsdtFundingReservationState::ExpiredUnresolved,
            CustomerUsdtFundingReservation::query()->sole()->state,
        );
        $this->assertSame(0, CustomerUsdtFundingReservation::query()->whereNotNull('resolved_at')->count());

        $this->artisan('usdt-funding:hold-expired-reservations', ['--limit' => 0])->assertExitCode(1);
        $this->artisan('usdt-funding:hold-expired-reservations', ['--customer' => 999_999])->assertExitCode(1);
    }

    public function test_settlement_requires_the_exact_funding_entry_and_commits_with_it(): void
    {
        $customer = Customer::factory()->create();
        $other = Customer::factory()->create();
        $this->enableFunding();
        $this->registerReadiness(CustomerUsdtFundingRail::BscUsdt, ready: true);

        $created = $this->reserve($customer, 'intent-settle');
        $reservationId = $created->reservation->reservationId;
        $service = app(CustomerUsdtFundingReservationService::class);

        // A failing resolution rolls the ledger posting back with it.
        try {
            DB::transaction(function () use ($customer, $reservationId): void {
                $credit = $this->credit($customer, 1_000_000, 'topup-atomic', 'usdt:funding-atomic');
                app(CustomerUsdtFundingReservationService::class)->markSettled(
                    $customer,
                    $reservationId,
                    $credit->entryId,
                    'topup-other-reference',
                );
            });
            $this->fail('A mismatched settlement reference was expected to conflict.');
        } catch (CustomerUsdtFundingReservationConflict) {
            // expected
        }

        $this->assertSame(0, CustomerUsdtBalanceEntry::query()->count());
        $this->assertSame(CustomerUsdtFundingReservationState::Reserved, CustomerUsdtFundingReservation::query()->sole()->state);

        // A matching entry resolves settlement and liability in one commit.
        $settled = DB::transaction(function () use ($customer, $reservationId) {
            $credit = $this->credit($customer, 1_000_000, 'topup-settled', 'usdt:funding-settled');

            return app(CustomerUsdtFundingReservationService::class)->markSettled(
                $customer,
                $reservationId,
                $credit->entryId,
                'topup-settled',
            );
        });
        $this->assertSame(CustomerUsdtFundingReservationOutcome::Settled, $settled->outcome);
        $this->assertSame(1, CustomerUsdtBalanceEntry::query()->count());

        $replay = $service->markSettled($customer, $reservationId, $settled->reservation->settledEntryId, 'topup-settled');
        $this->assertSame(CustomerUsdtFundingReservationOutcome::Replayed, $replay->outcome);

        // A different entry cannot resolve an already settled reservation.
        $otherReservation = $this->reserve($other, 'intent-settle-other');
        $foreignCredit = $this->credit($other, 1_000_000, 'topup-foreign', 'usdt:funding-foreign');
        try {
            $service->markSettled($customer, $reservationId, $foreignCredit->entryId, 'topup-foreign');
            $this->fail('A second settlement was expected to conflict.');
        } catch (CustomerUsdtFundingReservationConflict) {
            // expected
        }

        // A purchase debit can never stand in for a funding credit.
        $debit = app(CustomerUsdtBalanceLedger::class)->debitPurchase(
            $other,
            new UsdtPurchase((int) $other->id, UsdtMicroAmount::fromUnits(1_000_000), 'order-settle', 'payment-settle'),
            'order:funding-settle-debit',
        );
        try {
            $service->markSettled($other, $otherReservation->reservation->reservationId, $debit->entryId, 'topup-foreign');
            $this->fail('A debit entry was expected to be rejected as a settlement.');
        } catch (CustomerUsdtFundingReservationConflict) {
            // expected
        }
    }

    public function test_release_requires_a_typed_resolution_and_replays(): void
    {
        $customer = Customer::factory()->create();
        $this->enableFunding();
        $this->registerReadiness(CustomerUsdtFundingRail::BscUsdt, ready: true);

        $created = $this->reserve($customer, 'intent-release');
        $id = $created->reservation->reservationId;
        $service = app(CustomerUsdtFundingReservationService::class);

        $released = $service->release($customer, $id, CustomerUsdtFundingRelease::compensated('review-42', 'owner:7'));
        $this->assertSame(CustomerUsdtFundingReservationOutcome::Released, $released->outcome);
        $this->assertSame(CustomerUsdtFundingReservationState::Released, $released->reservation->state);

        $replay = $service->release($customer, $id, CustomerUsdtFundingRelease::compensated('review-42', 'owner:7'));
        $this->assertSame(CustomerUsdtFundingReservationOutcome::Replayed, $replay->outcome);

        try {
            $service->release($customer, $id, CustomerUsdtFundingRelease::externallyReturned('review-42', 'owner:7'));
            $this->fail('A different release resolution was expected to conflict.');
        } catch (CustomerUsdtFundingReservationConflict) {
            // expected
        }

        $this->assertSame(CustomerUsdtFundingReleaseKind::Compensated->value, (string) CustomerUsdtFundingReservation::query()->sole()->resolution_kind);

        $after = $this->reserve($customer, 'intent-after-release');
        $this->assertSame(CustomerUsdtFundingReservationOutcome::Created, $after->outcome);
    }

    public function test_pauses_block_new_targets_but_never_existing_targets_or_settlement(): void
    {
        $customer = Customer::factory()->create();
        $this->enableFunding();
        $this->registerReadiness(CustomerUsdtFundingRail::BscUsdt, ready: true);
        $this->registerReadiness(CustomerUsdtFundingRail::BinancePay, ready: true);

        $created = $this->reserve($customer, 'intent-paused');

        $credit = $this->credit($customer, 1_000_000, 'topup-paused', 'usdt:funding-paused');
        $settled = app(CustomerUsdtFundingReservationService::class)->markSettled(
            $customer,
            $created->reservation->reservationId,
            $credit->entryId,
            'topup-paused',
        );
        $this->assertSame(CustomerUsdtFundingReservationOutcome::Settled, $settled->outcome);

        $this->pause(CustomerUsdtFundingPauseScope::Funding, 'Insiden penyedia.');

        $this->assertUnavailable(
            fn () => $this->reserve($customer, 'intent-new-paused'),
            CustomerUsdtFundingAvailabilityReason::FundingPaused,
        );

        // The recorded target still replays while paused.
        $replay = $this->reserve($customer, 'intent-paused');
        $this->assertSame(CustomerUsdtFundingReservationOutcome::Replayed, $replay->outcome);
        $this->assertSame($created->reservation->reservationId, $replay->reservation->reservationId);

        $this->resume(CustomerUsdtFundingPauseScope::Funding);
        $this->pause(CustomerUsdtFundingPauseScope::RailBscUsdt, 'Rail sedang diverifikasi.');

        $this->assertUnavailable(
            fn () => $this->reserve($customer, 'intent-rail-paused'),
            CustomerUsdtFundingAvailabilityReason::RailPaused,
        );

        // A pause only blocks its own scope: the other rail still opens.
        $binance = $this->reserve($customer, 'intent-rail-other', rail: CustomerUsdtFundingRail::BinancePay);
        $this->assertSame(CustomerUsdtFundingReservationOutcome::Created, $binance->outcome);

        $this->resume(CustomerUsdtFundingPauseScope::RailBscUsdt);
        $this->assertSame(2, CustomerUsdtFundingReservation::query()->count());
    }

    public function test_spending_gate_uses_only_the_spending_pause(): void
    {
        $this->enableFunding();

        $gate = app(CustomerUsdtFundingGate::class);
        $this->pause(CustomerUsdtFundingPauseScope::Funding, 'Top-up dijeda.');
        $gate->assertSpendingAvailable();

        $this->pause(CustomerUsdtFundingPauseScope::Spending, 'Belanja dijeda.');
        try {
            $gate->assertSpendingAvailable();
            $this->fail('A spending pause was expected to block USDT spending.');
        } catch (CustomerUsdtFundingUnavailable $exception) {
            $this->assertSame(CustomerUsdtFundingAvailabilityReason::SpendingPaused, $exception->reason);
        }
    }

    public function test_source_and_release_identifiers_are_strict(): void
    {
        $customer = Customer::factory()->create();
        $this->enableFunding();
        $this->registerReadiness(CustomerUsdtFundingRail::BscUsdt, ready: true);
        $service = app(CustomerUsdtFundingReservationService::class);

        foreach (['Bad Type', '', 'spaces not allowed', str_repeat('a', 65)] as $sourceType) {
            try {
                $service->reserve($customer, CustomerUsdtFundingRail::BscUsdt, UsdtMicroAmount::fromUnits(1_000_000), $sourceType, 'intent-x');
                $this->fail("Source type [{$sourceType}] was expected to be rejected.");
            } catch (InvalidArgumentException) {
                // expected
            }
        }

        foreach (['bad ref', '', str_repeat('r', 192)] as $reference) {
            try {
                CustomerUsdtFundingRelease::compensated($reference, 'owner:1');
                $this->fail("Release reference [{$reference}] was expected to be rejected.");
            } catch (InvalidArgumentException) {
                // expected
            }
        }

        try {
            CustomerUsdtFundingRelease::compensated('review-1', 'bad actor!');
            $this->fail('A non-token release actor was expected to be rejected.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(0, CustomerUsdtFundingReservation::query()->count());
    }

    public function test_model_guards_reject_identity_changes_and_deletes(): void
    {
        $customer = Customer::factory()->create();
        $this->enableFunding();
        $this->registerReadiness(CustomerUsdtFundingRail::BscUsdt, ready: true);
        $this->reserve($customer, 'intent-guard');

        $reservation = CustomerUsdtFundingReservation::query()->sole();

        try {
            $reservation->forceFill(['amount_micro' => 5_000_000])->save();
            $this->fail('A reservation identity change was expected to be rejected.');
        } catch (\LogicException) {
            // expected
        }

        try {
            $reservation->delete();
            $this->fail('A reservation delete was expected to be rejected.');
        } catch (\LogicException) {
            // expected
        }

        app(CustomerUsdtFundingReservationService::class)->release(
            $customer,
            (int) $reservation->id,
            CustomerUsdtFundingRelease::neverExposed('recovery-1', 'system:rail-recovery'),
        );

        $resolved = CustomerUsdtFundingReservation::query()->sole();
        try {
            $resolved->forceFill(['resolution_actor' => 'owner:9'])->save();
            $this->fail('A resolved reservation was expected to be immutable.');
        } catch (\LogicException) {
            // expected
        }

        $this->assertSame('system:rail-recovery', (string) CustomerUsdtFundingReservation::query()->sole()->resolution_actor);
    }

    private function reserve(
        Customer $customer,
        string $sourceId,
        int $amountUnits = 1_000_000,
        CustomerUsdtFundingRail $rail = CustomerUsdtFundingRail::BscUsdt,
    ): CustomerUsdtFundingReservationResult {
        return app(CustomerUsdtFundingReservationService::class)->reserve(
            $customer,
            $rail,
            UsdtMicroAmount::fromUnits($amountUnits),
            'usdt_funding_intent',
            $sourceId,
        );
    }

    private function credit(Customer $customer, int $units, string $topUpId, string $idempotencyKey): UsdtBalanceMutationResult
    {
        return app(CustomerUsdtBalanceLedger::class)->creditVerifiedTopUp(
            $customer,
            new VerifiedUsdtFunding((int) $customer->id, UsdtMicroAmount::fromUnits($units), $topUpId, 'receipt-'.$topUpId, 'v1'),
            $idempotencyKey,
        );
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

    private function pause(CustomerUsdtFundingPauseScope $scope, string $reason): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        app(CustomerUsdtFundingPolicyManager::class)->pause($scope, $reason, $owner, null);
    }

    private function resume(CustomerUsdtFundingPauseScope $scope): void
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

    /**
     * @param  callable(): mixed  $callback
     */
    private function assertUnavailable(callable $callback, CustomerUsdtFundingAvailabilityReason $reason): void
    {
        try {
            $callback();
            $this->fail("Funding target creation was expected to be unavailable for [{$reason->value}].");
        } catch (CustomerUsdtFundingUnavailable $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }
}
