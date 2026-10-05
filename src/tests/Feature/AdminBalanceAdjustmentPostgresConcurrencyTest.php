<?php

namespace Tests\Feature;

use App\Enums\StoreBalanceEntryKind;
use App\Enums\UserRole;
use App\Exceptions\BalanceAdjustmentRejected;
use App\Exceptions\InsufficientStoreBalance;
use App\Exceptions\InvalidOrderTransition;
use App\Exceptions\StoreBalanceCompensationRejected;
use App\Models\BalanceAdjustmentRequest;
use App\Models\Customer;
use App\Models\ExternalMoneyReceiptReservation;
use App\Models\StoreBalanceEntry;
use App\Models\TelegramOutboundEffect;
use App\Models\User;
use App\Services\MoneyReceipts\ExternalMoneyReceiptWriter;
use App\Services\MoneyReceipts\MoneyReceiptTarget;
use App\Services\MoneyReceipts\VerifiedMoneyReceipt;
use App\Services\StoreBalance\BalanceAdjustmentService;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\StoreBalance\StoreBalanceReconciliation;
use App\Services\Warranty\WarrantySettlementService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
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

    public function test_reversal_and_spend_race_never_commits_a_negative_balance(): void
    {
        [$owner, $customer, $service, $creditPreview] = $this->fixture();
        $service->confirm($owner, $creditPreview->id, $creditPreview->fingerprint, 1, 'owner-password');
        $reversal = $service->requestReversal($owner, (string) $customer->telegram_id, $creditPreview->id);

        $results = $this->race([
            fn () => $this->attempt(fn () => $service->confirm($owner, $reversal->id, $reversal->fingerprint, 1, 'owner-password')),
            function () use ($customer): string {
                try {
                    app(StoreBalanceLedger::class)->debit($customer, 5000, 'order', 'race-order-1', 'order:race-order-1:debit');

                    return 'debited';
                } catch (InsufficientStoreBalance) {
                    return 'insufficient';
                }
            },
        ]);

        $reversalWon = $results[0] === 'applied';
        $this->assertTrue(
            ($reversalWon && $results[1] === 'insufficient')
            || (! $reversalWon && $results[0] === 'reversal_insufficient_funds' && $results[1] === 'debited'),
            'Exactly one side wins the account lock: '.implode(',', $results),
        );
        $this->assertDatabaseCount('store_balance_entries', 2);
        $this->assertSame($reversalWon ? 1 : 0, StoreBalanceEntry::query()->where('kind', StoreBalanceEntryKind::AdminAdjustmentReversal)->count());
        $this->assertSame(0, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        $this->assertTrue(app(StoreBalanceReconciliation::class)->inspect($customer)->isConsistent());
    }

    public function test_double_reversal_race_posts_exactly_one_linked_debit(): void
    {
        [$owner, $customer, $service, $creditPreview] = $this->fixture();
        $service->confirm($owner, $creditPreview->id, $creditPreview->fingerprint, 1, 'owner-password');
        $first = $service->requestReversal($owner, (string) $customer->telegram_id, $creditPreview->id);

        // Two live previews for one entitlement: the ledger source claim must
        // still allow exactly one linked debit and one accounting counterpart.
        $second = new BalanceAdjustmentRequest;
        $second->forceFill([
            'id' => (string) Str::uuid(), 'customer_id' => $first->customer_id, 'telegram_id' => $first->telegram_id,
            'username' => $first->username, 'owner_user_id' => $first->owner_user_id,
            'session_binding' => $first->session_binding, 'credential_binding' => $first->credential_binding,
            'mode' => $first->mode, 'currency' => $first->currency, 'amount' => $first->amount, 'reason' => $first->reason,
            'category' => 'reversal', 'source_id' => $first->source_id, 'receipt_fingerprint' => null,
            'fingerprint' => hash('sha256', (string) Str::uuid()), 'version' => 1, 'state' => 'pending',
            'expires_at' => $first->expires_at,
        ])->save();

        $results = $this->race([
            fn () => $this->attempt(fn () => $service->confirm($owner, $first->id, $first->fingerprint, 1, 'owner-password')),
            fn () => $this->attempt(fn () => $service->confirm($owner, $second->id, $second->fingerprint, 1, 'owner-password')),
        ]);

        $this->assertSame(1, count(array_filter($results, fn (string $result): bool => $result === 'applied')), implode(',', $results));
        $this->assertDatabaseCount('store_balance_entries', 2);
        $this->assertDatabaseCount('balance_adjustment_accounting_events', 2);
        $this->assertSame(0, app(StoreBalanceLedger::class)->snapshot($customer)->balance());

        // A loser that was held out by the per-Owner verification lease still
        // resolves as already reversed on retry instead of posting again.
        $loser = $first->fresh()->state === 'applied' ? $second : $first;
        $this->assertSame('pending', $loser->fresh()->state);
        $this->assertSame('original_already_reversed', $this->attempt(
            fn () => $service->confirm($owner, $loser->id, $loser->fingerprint, 1, 'owner-password'),
        ));
        $this->assertDatabaseCount('store_balance_entries', 2);
        $this->assertDatabaseCount('balance_adjustment_accounting_events', 2);
    }

    public function test_reversal_and_late_top_up_settlement_never_recredit_compensated_money(): void
    {
        [$owner, $customer, $service] = $this->fixture();
        $topUp = $this->adjustmentTopUp($customer);
        $preview = $service->request($owner, (string) $customer->telegram_id, 5000, 'Original recovery', 'top_up_recovery', (string) $topUp->id);
        $service->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password');
        $reversal = $service->requestReversal($owner, (string) $customer->telegram_id, $preview->id);

        $this->race([
            fn () => $this->attempt(fn () => $service->confirm($owner, $reversal->id, $reversal->fingerprint, 1, 'owner-password')),
            fn () => $this->observeAdjustmentTopUp($topUp),
        ]);
        $this->observeAdjustmentTopUp($topUp); // Late evidence still resolves the original without a second credit.

        $this->assertSame('applied', $reversal->fresh()->state);
        $this->assertSame(0, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        $this->assertDatabaseCount('store_balance_entries', 2);
        $this->assertDatabaseCount('external_money_receipts', 1);
        $this->assertSame('compensated', $topUp->fresh()->status->value);
        $this->assertNull(ExternalMoneyReceiptReservation::query()->whereNull('resolved_at')->first());
    }

    public function test_postgres_forbids_reversal_identity_and_entry_edits(): void
    {
        [$owner, $customer, $service, $creditPreview] = $this->fixture();
        $service->confirm($owner, $creditPreview->id, $creditPreview->fingerprint, 1, 'owner-password');
        $reversal = $service->requestReversal($owner, (string) $customer->telegram_id, $creditPreview->id);
        $result = $service->confirm($owner, $reversal->id, $reversal->fingerprint, 1, 'owner-password');

        foreach ([
            fn () => DB::table('balance_adjustment_requests')->where('id', $reversal->id)->update(['source_id' => (string) Str::uuid()]),
            fn () => DB::table('balance_adjustment_requests')->where('id', $reversal->id)->delete(),
            fn () => DB::table('store_balance_entries')->where('id', $result->entryId)->update(['amount' => 4000]),
            fn () => DB::table('store_balance_entries')->where('id', $result->entryId)->delete(),
            fn () => DB::table('balance_adjustment_accounting_events')->where('ledger_entry_id', $result->entryId)->update(['amount' => 4000]),
            fn () => DB::table('balance_adjustment_accounting_events')->where('ledger_entry_id', $result->entryId)->delete(),
            fn () => DB::table('audit_logs')->where('action', 'balance.adjustment.applied')->update(['action' => 'tampered']),
            fn () => DB::table('audit_logs')->where('action', 'balance.adjustment.applied')->delete(),
        ] as $mutation) {
            try {
                DB::transaction($mutation);
                $this->fail('Expected PostgreSQL immutable reversal history guard.');
            } catch (QueryException $error) {
                $this->assertStringContainsString('immutable', $error->getMessage());
            }
        }
    }

    public function test_reversal_and_refund_credit_race_stays_consistent_without_negative_balance(): void
    {
        [$owner, $customer, $service, $creditPreview] = $this->fixture();
        $service->confirm($owner, $creditPreview->id, $creditPreview->fingerprint, 1, 'owner-password');
        app(StoreBalanceLedger::class)->debit($customer, 5000, 'order', 'racer-refund-order', 'order:racer-refund-order:debit');
        $reversal = $service->requestReversal($owner, (string) $customer->telegram_id, $creditPreview->id);

        $results = $this->race([
            fn () => $this->attempt(fn () => $service->confirm($owner, $reversal->id, $reversal->fingerprint, 1, 'owner-password')),
            function () use ($customer): string {
                app(StoreBalanceLedger::class)->refund($customer, 5000, 'order', 'racer-refund-order', 'order:racer-refund-order:refund');

                return 'refunded';
            },
        ]);

        $this->assertContains($results[0], ['applied', 'reversal_insufficient_funds']);
        $this->assertSame('refunded', $results[1]);
        $reversalApplied = $results[0] === 'applied';
        $this->assertSame(1, StoreBalanceEntry::query()->where('kind', StoreBalanceEntryKind::RefundCredit)->count());
        $this->assertSame($reversalApplied ? 1 : 0, StoreBalanceEntry::query()->where('kind', StoreBalanceEntryKind::AdminAdjustmentReversal)->count());
        $this->assertDatabaseCount('store_balance_entries', $reversalApplied ? 4 : 3);
        $this->assertSame($reversalApplied ? 0 : 5000, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        $this->assertTrue(app(StoreBalanceReconciliation::class)->inspect($customer)->isConsistent());
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
