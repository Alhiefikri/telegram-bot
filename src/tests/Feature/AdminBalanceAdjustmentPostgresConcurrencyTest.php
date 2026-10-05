<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exceptions\BalanceAdjustmentRejected;
use App\Exceptions\InvalidOrderTransition;
use App\Exceptions\StoreBalanceCompensationRejected;
use App\Models\Customer;
use App\Models\ExternalMoneyReceiptReservation;
use App\Models\TelegramOutboundEffect;
use App\Models\User;
use App\Services\MoneyReceipts\ExternalMoneyReceiptWriter;
use App\Services\MoneyReceipts\MoneyReceiptTarget;
use App\Services\MoneyReceipts\VerifiedMoneyReceipt;
use App\Services\StoreBalance\BalanceAdjustmentService;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\Warranty\WarrantySettlementService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\BuildsBalanceAdjustmentSources;
use Tests\Support\NonTransactionalDatabaseTestCase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\RunsPostgresRace;

#[Group('concurrency')]
#[Group('postgres-release')]
final class AdminBalanceAdjustmentPostgresConcurrencyTest extends NonTransactionalDatabaseTestCase
{
    use BuildsBalanceAdjustmentSources;
    use RunsPostgresRace;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        if (DB::getDriverName() !== 'pgsql' || (string) env('RUN_CONCURRENCY_TESTS', '') !== '1') {
            $this->markTestSkipped('Adjustment races require the disposable PostgreSQL gate.');
        }
    }

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql' && (string) env('RUN_CONCURRENCY_TESTS', '') === '1') {
            PostgresTestDatabase::migrateFresh();
        }
        parent::tearDown();
    }

    public function test_approve_approve_and_changed_payload_races_have_one_money_effect(): void
    {
        [$owner, $customer, $service, $preview] = $this->fixture();
        $results = $this->race([
            fn () => $this->attempt(fn () => $service->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password')),
            fn () => $this->attempt(fn () => $service->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password')),
        ]);
        $this->assertContains('applied', $results);
        $this->assertSame(1, count(array_filter($results, fn ($result) => $result === 'applied')));
        $this->assertDatabaseCount('store_balance_entries', 1);
        $this->assertDatabaseCount('balance_adjustment_accounting_events', 1);
        $this->assertDatabaseCount('telegram_outbound_effects', 2);
        $this->assertSame(5000, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        $replays = $this->race([
            fn () => $this->attempt(fn () => $service->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password')),
            fn () => $this->attempt(fn () => $service->confirm($owner, $preview->id, str_repeat('a', 64), 1, 'owner-password')),
        ]);
        $this->assertContains('replayed', $replays);
        $this->assertContains('preview_binding_mismatch', $replays);
    }

    public function test_approve_reject_race_resolves_once_without_partial_money(): void
    {
        [$owner, $customer, $service, $preview] = $this->fixture();
        $this->race([
            fn () => $this->attempt(fn () => $service->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password')),
            function () use ($service, $owner, $preview): string {
                try {
                    return $service->close($owner, $preview->id, $preview->fingerprint, 1, 'rejected')->state;
                } catch (BalanceAdjustmentRejected|StoreBalanceCompensationRejected $error) {
                    return $error->reason;
                }
            },
        ]);
        $state = $preview->fresh()->state;
        $this->assertContains($state, ['applied', 'rejected']);
        $count = $state === 'applied' ? 1 : 0;
        $this->assertDatabaseCount('store_balance_entries', $count);
        $this->assertDatabaseCount('balance_adjustment_accounting_events', $count);
        $this->assertSame($count * 5000, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
    }

    public function test_concurrent_wrong_passwords_cannot_lose_failures_or_duplicate_lockout_alerts(): void
    {
        [$owner, , $service, $preview] = $this->fixture();
        $wrong = fn () => $this->attempt(fn () => $service->confirm($owner, $preview->id, $preview->fingerprint, 1, 'wrong'));
        // Each round overlaps independent processes; a busy contender retries in the next round.
        for ($round = 0; $round < 5; $round++) {
            $this->race([$wrong, $wrong]);
        }
        $state = DB::table('balance_adjustment_auth_states')->sole();
        $this->assertCount(5, json_decode($state->failed_at, true));
        $this->assertNotNull($state->locked_until);
        $this->assertDatabaseCount('telegram_outbound_effects', 1);
        $this->assertDatabaseCount('store_balance_entries', 0);
        $this->assertSame('owner_locked', $this->attempt(fn () => $service->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password')));
    }

    public function test_postgres_forbids_adjustment_identity_posting_and_accounting_edits(): void
    {
        [$owner, , $service, $preview] = $this->fixture();
        $result = $service->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password');
        foreach ([
            fn () => DB::table('balance_adjustment_requests')->where('id', $preview->id)->update(['amount' => 9000]),
            fn () => DB::table('balance_adjustment_requests')->where('id', $preview->id)->delete(),
            fn () => DB::table('store_balance_entries')->where('id', $result->entryId)->update(['amount' => 9000]),
            fn () => DB::table('balance_adjustment_accounting_events')->update(['amount' => 9000]),
            fn () => DB::table('balance_adjustment_accounting_events')->delete(),
        ] as $mutation) {
            try {
                DB::transaction($mutation);
                $this->fail('Expected PostgreSQL immutable history guard.');
            } catch (QueryException $error) {
                $this->assertStringContainsString('immutable', $error->getMessage());
            }
        }
    }

    public function test_top_up_settlement_and_manual_recovery_share_one_resolution(): void
    {
        [$owner, $customer, $service] = $this->fixture();
        $topUp = $this->adjustmentTopUp($customer);
        $preview = $service->request($owner, (string) $customer->telegram_id, 5000, 'Original recovery', 'top_up_recovery', (string) $topUp->id);
        $settle = function () use ($topUp): string {
            $this->observeAdjustmentTopUp($topUp);

            return 'observed';
        };
        $this->race([
            fn () => $this->attempt(fn () => $service->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password')),
            $settle,
        ]);
        $settle(); // Late evidence stays linked even when compensation won.
        $this->assertDatabaseCount('store_balance_entries', 1);
        $this->assertSame(5000, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        $this->assertContains($topUp->fresh()->status->value, ['paid', 'compensated']);
        $compensated = $topUp->fresh()->status->value === 'compensated';
        $this->assertDatabaseCount('store_balance_compensations', $compensated ? 1 : 0);
        $this->assertDatabaseCount('balance_adjustment_accounting_events', $compensated ? 1 : 0);
        $this->assertDatabaseCount('external_money_receipts', 1);
        $this->assertNotNull(TelegramOutboundEffect::query()->where('target_type', 'customer')->first());
        if ($compensated) {
            $this->assertSame('applied', $preview->fresh()->state);
            $this->assertSame('replayed', $this->attempt(fn () => $service->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password')));
            $this->assertNull(ExternalMoneyReceiptReservation::query()->whereNull('resolved_at')->first());
        }
    }

    public function test_external_refund_and_manual_balance_recovery_share_one_original_entitlement(): void
    {
        [$owner, , $service] = $this->fixture();
        [$customer, , $claim, $refund] = $this->adjustmentRefund();
        $preview = $service->request($owner, (string) $customer->telegram_id, 8000, 'Customer chose balance', 'refund_recovery', $refund->id);
        $this->race([
            fn () => $this->attempt(fn () => $service->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password')),
            function () use ($owner, $refund): string {
                try {
                    app(WarrantySettlementService::class)->confirmExternalRefund($owner, $refund->id, 8000, 'IDR', 'external-original-refund', 'success');

                    return 'external';
                } catch (InvalidOrderTransition) {
                    return 'already_resolved';
                }
            },
        ]);
        $this->assertSame('confirmed', $refund->fresh()->state);
        $this->assertSame('refunded', $claim->fresh()->settlement_state);
        $balanceWon = $refund->fresh()->external_reference_hash === null;
        $this->assertDatabaseCount('store_balance_entries', $balanceWon ? 1 : 0);
        $this->assertDatabaseCount('balance_adjustment_accounting_events', $balanceWon ? 1 : 0);
        $this->assertSame($balanceWon ? 8000 : 0, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
    }

    public function test_adjustment_and_top_up_receipt_writers_cannot_consume_same_money(): void
    {
        [$owner, $customer, $service] = $this->fixture();
        $topUp = $this->adjustmentTopUp($customer);
        $writer = app(ExternalMoneyReceiptWriter::class);
        $topUpReceipt = $writer->receiptForStoreBalanceTopUp($topUp);
        $adjustmentReceipt = new VerifiedMoneyReceipt(
            $topUpReceipt->identity, $topUpReceipt->beneficiary, 'IDR', '5000', 0,
            MoneyReceiptTarget::balanceAdjustment('verification-context'),
            ['customer_id' => (int) $customer->id, 'ownership_verified' => true]);
        $preview = $service->request($owner, (string) $customer->telegram_id, 5000, 'Verified funding', 'funded_transfer', receipt: $adjustmentReceipt);
        $results = $this->race([
            fn () => $this->attempt(fn () => $service->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password', $adjustmentReceipt)),
            function () use ($writer, $topUpReceipt, $customer, $topUp): string {
                return DB::transaction(function () use ($writer, $topUpReceipt, $customer, $topUp): string {
                    Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
                    app(StoreBalanceLedger::class)->lockForTransaction($customer);
                    $outcome = $writer->claim($topUpReceipt, 'runtime', 'store_balance_top_up', (string) $topUp->id);
                    if ($outcome->allowsMoneyEffect()) {
                        app(StoreBalanceLedger::class)->creditTopUp($customer, 5000, (string) $topUp->id, 'receipt-race:'.$topUp->id);
                    }

                    return $outcome->value;
                }, 5);
            },
        ]);
        $this->assertContains($results[0], ['applied', 'receipt_unavailable']);
        $this->assertContains($results[1], ['claimed', 'conflict']);
        $this->assertDatabaseCount('external_money_receipts', 1);
        $this->assertDatabaseCount('external_money_receipt_reservations', 1);
        $this->assertDatabaseCount('store_balance_entries', 1);
        $this->assertSame(5000, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        $this->assertDatabaseCount('balance_adjustment_accounting_events', $results[0] === 'applied' ? 1 : 0);
    }

    private function fixture(): array
    {
        $owner = User::factory()->create(['role' => UserRole::Owner, 'password' => 'owner-password']);
        $this->actingAs($owner);
        $session = app('session')->driver();
        $session->start();
        request()->setLaravelSession($session);
        config(['services.telegram.admin_telegram_chat_id' => '998877']);
        $customer = Customer::factory()->create(['telegram_id' => 901543]);
        $service = app(BalanceAdjustmentService::class);
        $preview = $service->request($owner, (string) $customer->telegram_id, 5000, 'PostgreSQL fixture');

        return [$owner, $customer, $service, $preview];
    }

    private function attempt(callable $operation): string
    {
        try {
            $result = $operation();

            return $result->applied ? 'applied' : 'replayed';
        } catch (BalanceAdjustmentRejected|StoreBalanceCompensationRejected $error) {
            return $error->reason;
        }
    }
}
