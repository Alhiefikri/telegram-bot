<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exceptions\BalanceAdjustmentRejected;
use App\Exceptions\InvalidOrderTransition;
use App\Exceptions\StoreBalanceProjectionMismatchException;
use App\Jobs\SendBalanceAdjustmentNotice;
use App\Jobs\SendStoreBalanceOwnerCompensationNotification;
use App\Models\Customer;
use App\Models\CustomerBalanceAccount;
use App\Models\TelegramOutboundEffect;
use App\Models\User;
use App\Services\MoneyReceipts\MoneyReceiptIdentity;
use App\Services\MoneyReceipts\MoneyReceiptTarget;
use App\Services\MoneyReceipts\VerifiedMoneyReceipt;
use App\Services\StoreBalance\ApprovedBalanceAdjustment;
use App\Services\StoreBalance\BalanceAdjustmentService;
use App\Services\StoreBalance\StoreBalanceActivityService;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\StoreBalance\StoreBalanceOwnerCompensationService;
use App\Services\Telegram\BalanceAdjustmentNoticeDeliveryPipeline;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Warranty\WarrantySettlementService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\BuildsBalanceAdjustmentSources;
use Tests\Support\NonTransactionalDatabaseTestCase;

#[Group('postgres-release')]
final class AdminBalanceAdjustmentTest extends NonTransactionalDatabaseTestCase
{
    use BuildsBalanceAdjustmentSources;

    public function test_owner_preview_has_no_money_effect_and_confirmation_posts_once(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner, 'password' => 'owner-password']);
        $this->actingAs($owner);
        $session = app('session')->driver();
        $session->start();
        request()->setLaravelSession($session);
        config(['services.telegram.admin_telegram_chat_id' => '998877']);
        $customer = Customer::factory()->create(['telegram_id' => 901543]);
        $service = app(BalanceAdjustmentService::class);
        $preview = $service->request($owner, '901543', 25000, 'Dana diterima di luar sistem');
        $this->assertSame(0, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        $this->assertSame('pending', $preview->state);
        $result = $service->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password');
        $replay = $service->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password');
        $this->assertSame($result->entryId, $replay->entryId);
        $this->assertFalse($replay->applied);
        $this->assertSame(25000, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        $this->assertDatabaseCount('balance_adjustment_accounting_events', 1);
        $this->assertDatabaseCount('telegram_outbound_effects', 2);
    }

    public function test_five_failed_passwords_are_durable_and_one_alert_is_created(): void
    {
        [$owner, $customer, $service] = $this->fixture();
        $preview = $service->request($owner, (string) $customer->telegram_id, 5000, 'Alasan');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->rejects('invalid_password', fn () => $service->confirm($owner, $preview->id, $preview->fingerprint, 1, 'wrong'));
        }
        $this->rejects('owner_locked', fn () => $service->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password'));
        $this->assertDatabaseCount('store_balance_entries', 0);
        $this->assertDatabaseCount('telegram_outbound_effects', 1);
        $this->assertCount(5, json_decode(DB::table('balance_adjustment_auth_states')->value('failed_at'), true));
        $this->travel(16)->minutes();
        $fresh = $service->request($owner, (string) $customer->telegram_id, 5000, 'Alasan');
        $service->confirm($owner, $fresh->id, $fresh->fingerprint, 1, 'owner-password');
        $this->assertSame(5000, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
    }

    public function test_changed_payload_version_session_password_or_role_cannot_post(): void
    {
        [$owner, $customer, $service] = $this->fixture();
        $preview = $service->request($owner, (string) $customer->telegram_id, 5000, 'Alasan');
        $this->rejects('preview_binding_mismatch', fn () => $service->confirm($owner, $preview->id, str_repeat('0', 64), 1, 'owner-password'));
        $this->rejects('preview_binding_mismatch', fn () => $service->confirm($owner, $preview->id, $preview->fingerprint, 2, 'owner-password'));
        request()->session()->migrate();
        $this->rejects('preview_binding_mismatch', fn () => $service->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password'));
        $fresh = $service->request($owner, (string) $customer->telegram_id, 5000, 'Alasan');
        $owner->update(['password' => 'new-password']);
        $this->rejects('preview_binding_mismatch', fn () => $service->confirm($owner, $fresh->id, $fresh->fingerprint, 1, 'new-password'));
        $owner->update(['role' => UserRole::Admin]);
        $this->rejects('owner_required', fn () => $service->confirm($owner, $fresh->id, $fresh->fingerprint, 1, 'new-password'));
        $this->assertDatabaseCount('store_balance_entries', 0);
    }

    public function test_reject_cancel_and_expiry_never_credit(): void
    {
        [$owner, $customer, $service] = $this->fixture();
        foreach (['rejected', 'cancelled', 'expired'] as $decision) {
            $preview = $service->request($owner, (string) $customer->telegram_id, 5000, 'Alasan');
            if ($decision === 'expired') {
                $this->travel(5)->minutes();
            }
            $closed = $service->close($owner, $preview->id, $preview->fingerprint, 1, $decision);
            $this->assertSame($decision, $closed->state);
            $this->rejects('preview_unavailable', fn () => $service->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password'));
        }
        $this->assertDatabaseCount('store_balance_entries', 0);
        $this->assertDatabaseCount('balance_adjustment_accounting_events', 0);
        $this->assertDatabaseCount('telegram_outbound_effects', 0);
    }

    public function test_exact_lookup_never_creates_customers_and_pending_cannot_credit_directly(): void
    {
        [$owner, $customer, $service] = $this->fixture();
        foreach (['@name', '0901543', '901543.0', '9e5', '9223372036854775808'] as $id) {
            $this->rejects('invalid_telegram_id', fn () => $service->request($owner, $id, 5000, 'Alasan'));
        }
        $this->rejects('customer_not_registered', fn () => $service->request($owner, '123456', 5000, 'Alasan'));
        $this->assertDatabaseCount('customers', 1);
        $preview = $service->request($owner, (string) $customer->telegram_id, 5000, 'Alasan');
        $this->rejects('stale_authentication', fn () => ApprovedBalanceAdjustment::fromVerifiedLease($owner, $preview, (string) Str::uuid()));
    }

    public function test_money_accounting_audit_and_outbound_inserts_rollback_together(): void
    {
        [$owner, $customer, $service] = $this->fixture();
        foreach (['balance_adjustment_accounting_events', 'audit_logs', 'telegram_outbound_effects'] as $table) {
            $preview = $service->request($owner, (string) $customer->telegram_id, 5000, 'Alasan');
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE FUNCTION fail_adjustment_insert() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'injected failure'; END \$\$; CREATE TRIGGER fail_adjustment_insert BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION fail_adjustment_insert()");
            } else {
                DB::unprepared("CREATE TRIGGER fail_adjustment_insert BEFORE INSERT ON {$table} BEGIN SELECT RAISE(ABORT, 'injected failure'); END");
            }
            try {
                $service->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password');
                $this->fail('Expected insert failure.');
            } catch (QueryException $error) {
                $this->assertStringContainsString('injected failure', $error->getMessage());
            } finally {
                if (DB::getDriverName() === 'pgsql') {
                    DB::unprepared("DROP TRIGGER fail_adjustment_insert ON {$table}; DROP FUNCTION fail_adjustment_insert()");
                } else {
                    DB::unprepared('DROP TRIGGER fail_adjustment_insert');
                }
            }
            $this->assertSame('pending', $preview->fresh()->state);
            $this->assertSame(0, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
            $this->assertDatabaseCount('store_balance_entries', 0);
            $this->assertDatabaseCount('balance_adjustment_accounting_events', 0);
            $this->assertDatabaseCount('telegram_outbound_effects', 0);
        }
    }

    public function test_funded_adjustment_claims_verified_customer_receipt_and_reuse_is_held(): void
    {
        [$owner, $customer, $service] = $this->fixture();
        $receipt = $this->receipt($customer);
        $preview = $service->request($owner, (string) $customer->telegram_id, 5000, 'Verified transfer', 'funded_transfer', receipt: $receipt);
        $service->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password', $receipt);
        $second = $service->request($owner, (string) $customer->telegram_id, 5000, 'Same physical funds', 'funded_transfer', receipt: $receipt);
        $this->rejects('receipt_unavailable', fn () => $service->confirm($owner, $second->id, $second->fingerprint, 1, 'owner-password', $receipt));
        $this->assertDatabaseCount('external_money_receipts', 1);
        $this->assertDatabaseCount('external_money_receipt_reservations', 1);
        $this->assertDatabaseCount('store_balance_entries', 1);
        $this->assertSame(5000, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        $this->assertSame('pending', $second->fresh()->state);
        $foreign = Customer::factory()->create();
        $this->rejects('receipt_customer_or_money_mismatch', fn () => $service->request($owner, (string) $customer->telegram_id, 5000, 'Stolen proof', 'funded_transfer', receipt: $this->receipt($foreign)));
    }

    public function test_unrecorded_attestation_creates_honest_counterpart_and_history_is_readable(): void
    {
        [$owner, $customer, $service] = $this->fixture();
        $preview = $service->request($owner, (string) $customer->telegram_id, 5000, 'Received outside system');
        $result = $service->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password');
        $this->assertDatabaseCount('external_money_receipts', 0);
        $this->assertDatabaseHas('balance_adjustment_accounting_events', ['debit_account' => 'owner_attested_adjustment_suspense', 'credit_account' => 'customer_idr_liability', 'amount' => 5000]);
        $page = app(StoreBalanceActivityService::class)->firstPage($customer);
        $this->assertSame('adjustment', $page->items[0]->kind);
        $this->assertSame(5000, $page->items[0]->signedAmount);
        $this->assertNotNull(app(StoreBalanceActivityService::class)->detail($customer, 'entry', $result->entryId));
        $this->expectException(\LogicException::class);
        $preview->fresh()->forceFill(['amount' => 8000])->save();
    }

    public function test_recorded_refund_resolves_original_entitlement_and_external_replay_cannot_pay_again(): void
    {
        [$owner, , $service] = $this->fixture();
        Queue::fake();
        [$customer, $unit, $claim, $refund] = $this->adjustmentRefund();
        $payment = $unit->order->payments()->sole();
        $preview = $service->request($owner, (string) $customer->telegram_id, 8000, 'Customer chose balance refund', 'refund_recovery', $refund->id);
        $result = $service->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password');
        $this->assertSame(8000, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        $this->assertRecoveryEntryImmutable($result->entryId);
        $this->assertSame('confirmed', $refund->fresh()->state);
        $this->assertSame('refunded', $claim->fresh()->settlement_state);
        $this->assertSame('paid', $payment->fresh()->status->value);
        $this->assertDatabaseHas('store_balance_entries', ['id' => $result->entryId, 'source_type' => 'order_refund', 'source_id' => $refund->id]);
        $this->rejects('invalid_original_refund', fn () => $service->request($owner, (string) $customer->telegram_id, 8000, 'Replay', 'refund_recovery', $refund->id));
        try {
            app(WarrantySettlementService::class)->confirmExternalRefund($owner, $refund->id, 8000, 'IDR', 'external-transfer', 'success');
            $this->fail('Expected original resolution to block second refund.');
        } catch (InvalidOrderTransition) {
            $this->assertDatabaseCount('store_balance_entries', 1);
        }
    }

    public function test_top_up_recovery_rechecks_customer_amount_and_currency_and_preserves_original_resolution(): void
    {
        [$owner, $customer, $service] = $this->fixture();
        $topUp = $this->adjustmentTopUp($customer);
        foreach ([['amount' => 9000], ['customer_id' => Customer::factory()->create()->id], ['currency' => 'USD']] as $change) {
            $preview = $service->request($owner, (string) $customer->telegram_id, 5000, 'Original recovery', 'top_up_recovery', (string) $topUp->id);
            $topUp->forceFill($change)->save();
            $this->rejects('original_top_up_changed', fn () => $service->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password'));
            $topUp->forceFill(['amount' => 5000, 'customer_id' => $customer->id, 'currency' => 'IDR'])->save();
        }
        $preview = $service->request($owner, (string) $customer->telegram_id, 5000, 'Original recovery', 'top_up_recovery', (string) $topUp->id);
        $result = $service->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password');
        $this->assertSame('compensated', $topUp->fresh()->status->value);
        $this->assertRecoveryEntryImmutable($result->entryId);
        $this->assertSame(5000, $result->amount);
        $this->assertDatabaseCount('store_balance_entries', 1);
        $this->assertDatabaseCount('store_balance_compensations', 1);
        $this->assertDatabaseCount('balance_adjustment_accounting_events', 1);
        Queue::fake(); // Simulate losing the after-commit wake-up.
        $this->artisan('telegram:recover-outbound')->assertSuccessful();
        Queue::assertPushed(SendStoreBalanceOwnerCompensationNotification::class, 1);
        Queue::assertPushed(SendBalanceAdjustmentNotice::class, 1);
        $replay = app(StoreBalanceOwnerCompensationService::class)->release($topUp->id, $owner, 'different-ref', 'Legacy replay');
        $this->assertFalse($replay->applied);
        $this->assertDatabaseCount('store_balance_entries', 1);
        $this->observeAdjustmentTopUp($topUp);
        $this->assertDatabaseCount('external_money_receipts', 1);
        $this->assertDatabaseCount('store_balance_entries', 1);
    }

    public function test_exact_amounts_reject_float_or_overflow_and_projection_drift_fails_closed(): void
    {
        [$owner, $customer, $service] = $this->fixture();
        foreach ([1.5, true, '1e5', '05', '-1', '9223372036854775808'] as $amount) {
            $this->rejects('invalid_amount_or_reason', fn () => $service->request($owner, (string) $customer->telegram_id, $amount, 'Alasan'));
        }
        $ledger = app(StoreBalanceLedger::class);
        $ledger->creditTopUp($customer, PHP_INT_MAX, 'overflow-fixture', 'overflow-fixture');
        $preview = $service->request($owner, (string) $customer->telegram_id, '1', 'Overflow');
        try {
            $service->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password');
            $this->fail('Expected integer overflow.');
        } catch (\InvalidArgumentException) {
            $this->assertSame(PHP_INT_MAX, $ledger->snapshot($customer)->balance());
        }
        CustomerBalanceAccount::query()->where('customer_id', $customer->id)->update(['balance' => 0]);
        try {
            $service->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password');
            $this->fail('Expected projection mismatch.');
        } catch (StoreBalanceProjectionMismatchException) {
            $this->assertFalse($ledger->snapshot($customer)->isAvailable());
            $this->assertDatabaseCount('balance_adjustment_accounting_events', 0);
        }
    }

    public function test_notification_recovery_dispatches_committed_obligations_without_repeating_money(): void
    {
        [$owner, $customer, $service] = $this->fixture();
        $preview = $service->request($owner, (string) $customer->telegram_id, 5000, 'Alasan');
        $service->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password');
        $this->artisan('telegram:recover-outbound')->assertSuccessful();
        Queue::assertPushed(SendBalanceAdjustmentNotice::class, 2);
        $effect = TelegramOutboundEffect::query()->where('target_type', 'customer')->sole();
        $this->assertSame('Your IDR balance increased by Rp 5.000.', $effect->outcome_metadata['message']);
        $bot = \Mockery::mock(TelegramBotClient::class);
        $bot->shouldReceive('sendMessage')->once()->andReturn(['ok' => true, 'result' => ['message_id' => 543]]);
        app()->instance(TelegramBotClient::class, $bot);
        $pipeline = app(BalanceAdjustmentNoticeDeliveryPipeline::class);
        $pipeline->deliver($effect);
        $pipeline->deliver($effect->fresh());
        $this->assertSame('sent', $effect->fresh()->state->value);
        $this->assertSame(5000, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        $this->assertDatabaseCount('store_balance_entries', 1);
    }

    private function receipt(Customer $customer): VerifiedMoneyReceipt
    {
        return new VerifiedMoneyReceipt(
            MoneyReceiptIdentity::providerReference('klikqris', 'merchant:543', 'TRANSFER-543'),
            'merchant:543', 'IDR', '5000', 0, MoneyReceiptTarget::balanceAdjustment('verification-context'),
            ['customer_id' => (int) $customer->id, 'ownership_verified' => true],
        );
    }

    private function assertRecoveryEntryImmutable(int $entryId): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        try {
            DB::transaction(fn () => DB::table('store_balance_entries')->where('id', $entryId)->update(['amount' => 9000]));
            $this->fail('Expected PostgreSQL recovery history protection.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('immutable', $error->getMessage());
        }
    }

    private function fixture(): array
    {
        $owner = User::factory()->create(['role' => UserRole::Owner, 'password' => 'owner-password']);
        $this->actingAs($owner);
        $session = app('session')->driver();
        $session->start();
        request()->setLaravelSession($session);
        config(['services.telegram.admin_telegram_chat_id' => '998877']);
        $customer = Customer::factory()->create(['telegram_id' => 901543, 'locale' => 'en']);

        Queue::fake();

        return [$owner, $customer, app(BalanceAdjustmentService::class)];
    }

    private function rejects(string $reason, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected rejection: '.$reason);
        } catch (BalanceAdjustmentRejected $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }
}
