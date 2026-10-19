<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\CustomerUsdtBalanceEntry;
use App\Models\CustomerUsdtFundingPolicy;
use App\Models\TelegramOutboundEffect;
use App\Models\User;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramOutboundEffectLedgerProtocol;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\Telegram\TelegramUsdtFundingActions;
use App\Services\Telegram\UsdtTopUpOutbound;
use App\Services\UsdtBalance\UsdtReceiptReconciliation;
use App\Services\UsdtFunding\CustomerUsdtFundingReservationService;
use App\Services\UsdtTopUps\UsdtTopUpPreparation;
use App\Services\UsdtTopUps\UsdtTopUpReview;
use App\Services\UsdtTopUps\UsdtTopUpSettlement;
use App\Services\UsdtTreasury\UsdtTreasuryAssetObservation;
use App\Services\UsdtTreasury\UsdtTreasuryReconciliation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BscUsdtRailRpcFixture;
use Tests\Support\UsdtTopUpTestCase;

class UsdtTopUpSettlementTest extends UsdtTopUpTestCase
{
    public function test_bsc_transfer_is_credited_automatically_without_customer_hash_submission(): void
    {
        $this->assertSame(0, DB::table('customer_usdt_top_up_inbox')->count());
        $this->rpc->logs = [$this->rpc->log()];
        $this->travel(10)->seconds();
        Artisan::call('usdt-funding:recover');
        $this->assertSame(1_000_000, (int) DB::table('customer_usdt_balance_accounts')->value('balance_micro'));
        $this->assertSame('settled', DB::table('customer_usdt_top_up_resolutions')->value('kind'));
        $this->travel(1)->minutes();
        Artisan::call('usdt-funding:recover');
        $this->assertSame(1, CustomerUsdtBalanceEntry::count());
    }

    public function test_exact_final_receipt_commits_money_resolution_accounting_audit_and_outbound_once(): void
    {
        $snapshot = $this->target->fresh()->getAttributes();
        $this->settle();
        $this->assertSame(1_000_000, (int) DB::table('customer_usdt_balance_accounts')->value('balance_micro'));
        $this->assertSame('settled', DB::table('customer_usdt_top_up_settlement_work')->value('state'));
        foreach (['customer_usdt_balance_entries', 'customer_usdt_balance_accounting_events', 'customer_usdt_top_up_resolutions', 'external_money_receipts', 'telegram_outbound_effects'] as $table) {
            $this->assertSame(1, DB::table($table)->count(), $table);
        }
        $this->assertSame('settled', DB::table('customer_usdt_funding_reservations')->value('state'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'usdt_top_up.settled')->count());
        $this->assertSame($snapshot, $this->target->fresh()->getAttributes());
        $this->travel(2)->minutes();
        $this->settle();
        $this->assertSame(1, CustomerUsdtBalanceEntry::count());
        $this->assertSame(1, TelegramOutboundEffect::count());
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, DB::table('store_balance_entries')->count());
    }

    public function test_actual_transfer_after_deadline_settles_original_target_once(): void
    {
        $this->travel(20)->minutes();
        $this->rpc->timestamp = now()->timestamp;
        app(CustomerUsdtFundingReservationService::class)->holdExpired();
        $this->settle();
        $this->assertSame(1_000_000, (int) DB::table('customer_usdt_balance_accounts')->value('balance_micro'));
        $this->assertTrue((bool) DB::table('customer_usdt_top_up_resolutions')->value('late'));
        $this->assertStringContainsString('terlambat', TelegramOutboundEffect::sole()->outcome_metadata['message']);
    }

    public function test_incomplete_finality_has_no_credit_then_recovers_same_durable_hint(): void
    {
        $this->rpc->finalized = 99;
        $this->settle();
        $this->assertSame(0, CustomerUsdtBalanceEntry::count());
        $this->assertSame('pending', DB::table('customer_usdt_top_up_inbox')->value('state'));
        $this->rpc->finalized = 105;
        $this->travel(1)->minutes();
        app(UsdtTopUpSettlement::class)->recover();
        $this->assertSame(1, CustomerUsdtBalanceEntry::count());
    }

    public function test_mismatched_deposit_waits_for_finality_then_recovers_as_unresolved_money(): void
    {
        $this->rpc->receipt['logs'] = [$this->rpc->log(amount: '1250000000000000000')];
        $this->rpc->finalized = 99;
        $this->settle();
        $this->assertSame('pending', DB::table('customer_usdt_top_up_inbox')->value('state'));
        $this->assertSame(0, DB::table('external_money_receipts')->count());
        $this->rpc->finalized = 105;
        $this->travel(1)->minutes();
        app(UsdtTopUpSettlement::class)->recover();
        $this->assertSame('manual_review', DB::table('customer_usdt_top_up_settlement_work')->value('state'));
        $this->assertSame(0, CustomerUsdtBalanceEntry::count());
        $this->assertSame('1250000', app(UsdtReceiptReconciliation::class)->inspect(null, null, null, 100)->unresolvedDepositMicro->toString());
    }

    public function test_wrong_amount_is_review_not_available_money(): void
    {
        $this->rpc->receipt['logs'][0]['data'] = '0x'.str_pad(dechex(1), 64, '0', STR_PAD_LEFT);
        $this->settle();
        $this->assertSame('manual_review', DB::table('customer_usdt_top_up_settlement_work')->value('state'));
        $this->assertSame(0, CustomerUsdtBalanceEntry::count());
        $this->assertSame(0, DB::table('external_money_receipts')->count());
        $this->travel(20)->minutes();
        app(UsdtTopUpSettlement::class)->recover();
        $this->assertSame('manual_review', DB::table('customer_usdt_top_up_settlement_work')->value('state'));
    }

    #[DataProvider('mismatchedDeposits')]
    public function test_final_mismatched_deposit_is_unresolved_treasury_money_once(string $raw, string $micro): void
    {
        $this->rpc->receipt['logs'] = [$this->rpc->log(amount: $raw)];
        $this->settle();
        $this->assertSame('manual_review', DB::table('customer_usdt_top_up_settlement_work')->value('state'));
        $this->assertSame(0, CustomerUsdtBalanceEntry::count());
        $report = app(UsdtReceiptReconciliation::class)->inspect(null, null, null, 100);
        $this->assertSame($micro, $report->unresolvedDepositMicro->toString());
        $this->assertCount(1, $report->findings);
        $coverage = app(UsdtTreasuryReconciliation::class)->inspect(null, $report->unresolvedDepositMicro, $report->overcreditedMicro);
        $this->assertSame('unverifiable', $coverage->verdict->value);
        $custody = UsdtTreasuryAssetObservation::available('fixture_custody', $micro, now());
        $this->assertSame('consistent', app(UsdtTreasuryReconciliation::class)
            ->inspect(null, $report->unresolvedDepositMicro, $report->overcreditedMicro, [$custody])->verdict->value);
        // Replay the durable hint, as after recovery, without duplicating funds.
        DB::table('customer_usdt_top_up_inbox')->update(['state' => 'pending', 'next_check_at' => now()]);
        $this->travel(1)->minutes();
        $this->settle();
        $this->assertSame(1, DB::table('external_money_receipts')->count());
        $this->assertSame($micro, app(UsdtReceiptReconciliation::class)->inspect(null, null, null, 100)->unresolvedDepositMicro->toString());
        $this->assertSame(0, CustomerUsdtBalanceEntry::count());
    }

    public static function mismatchedDeposits(): array
    {
        return [
            'underpayment' => ['750000000000000000', '750000'],
            'overpayment' => ['1250000000000000000', '1250000'],
        ];
    }

    #[DataProvider('unsafeMismatchedDeposits')]
    public function test_mismatched_amount_does_not_bypass_authenticity_or_finality(string $mutation): void
    {
        $this->rpc->receipt['logs'] = [$this->rpc->log(amount: '1250000000000000000')];
        switch ($mutation) {
            case 'wrong_token': $this->rpc->receipt['logs'][0]['address'] = '0x'.str_repeat('1', 40);
                break;
            case 'wrong_receiver': $this->rpc->receipt['logs'][0]['topics'][2] = '0x'.str_repeat('0', 24).str_repeat('1', 40);
                break;
            case 'failed': $this->rpc->receipt['status'] = '0x0';
                break;
            case 'unfinalized': $this->rpc->finalized = 99;
                break;
            case 'reorg': $this->rpc->receipt['blockHash'] = $this->rpc->receipt['logs'][0]['blockHash'] = '0x'.str_repeat('1', 64);
                break;
            case 'multiple': $this->rpc->receipt['logs'][] = $this->rpc->log(index: 1);
                break;
            case 'before_invoice': $this->rpc->timestamp = $this->target->created_at->timestamp - 1;
                break;
        }
        $this->settle();
        $this->assertSame(0, DB::table('external_money_receipts')->count());
        $this->assertSame(0, CustomerUsdtBalanceEntry::count());
    }

    public static function unsafeMismatchedDeposits(): array
    {
        return array_combine(
            $cases = ['wrong_token', 'wrong_receiver', 'failed', 'unfinalized', 'reorg', 'multiple', 'before_invoice'],
            array_map(static fn (string $case): array => [$case], $cases),
        );
    }

    public function test_lost_or_expired_lease_rejects_result_and_recovers_after_worker_death(): void
    {
        $this->rpc->onCall = function (): void {
            $this->rpc->onCall = null;
            DB::table('customer_usdt_top_up_settlement_work')->update([
                'version' => DB::raw('version + 1'), 'lease_token' => str_repeat('e', 32),
            ]);
        };
        $this->settle();
        $this->assertSame(0, CustomerUsdtBalanceEntry::count());
        $this->assertSame(0, DB::table('customer_usdt_top_up_evidence')->count());
        $this->travel(3)->minutes();
        app(UsdtTopUpSettlement::class)->recover();
        $this->assertSame(1, CustomerUsdtBalanceEntry::count());
    }

    public function test_expired_lease_without_replacement_is_also_rejected(): void
    {
        $this->rpc->onCall = function (): void {
            $this->rpc->onCall = null;
            $this->travel(3)->minutes();
        };
        $this->settle();
        $this->assertSame(0, CustomerUsdtBalanceEntry::count());
        app(UsdtTopUpSettlement::class)->recover();
        $this->assertSame(1, CustomerUsdtBalanceEntry::count());
    }

    public function test_ingestion_version_change_during_network_fences_authoritative_result(): void
    {
        $this->rpc->onCall = function (): void {
            $this->rpc->onCall = null;
            DB::table('usdt_bsc_scan_checkpoints')->update(['version' => DB::raw('version + 1')]);
        };
        $this->settle();
        $this->assertSame(0, CustomerUsdtBalanceEntry::count());
        $this->assertSame('evidence_version_changed', DB::table('customer_usdt_top_up_settlement_work')->value('last_error'));
        $this->travel(1)->minutes();
        app(UsdtTopUpSettlement::class)->recover();
        $this->assertSame(1, CustomerUsdtBalanceEntry::count());
    }

    public function test_cross_actor_hash_submission_is_rejected_without_persistence(): void
    {
        try {
            app(UsdtTopUpSettlement::class)->submit(Customer::factory()->create(), $this->target->id, BscUsdtRailRpcFixture::TX);
            $this->fail('Foreign actor accepted.');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('customer_usdt_top_up_inbox')->count());
            $this->assertSame(0, CustomerUsdtBalanceEntry::count());
        }
    }

    public function test_outbound_persistence_failure_rolls_back_money_receipt_and_resolution(): void
    {
        DB::unprepared("CREATE TRIGGER reject_usdt_outbound BEFORE INSERT ON telegram_outbound_effects BEGIN SELECT RAISE(ABORT, 'outbound failed'); END;");
        try {
            $this->settle();
            $this->fail('Money committed without outbound.');
        } catch (QueryException) {
            foreach (['customer_usdt_balance_entries', 'external_money_receipts', 'customer_usdt_top_up_resolutions', 'customer_usdt_top_up_evidence'] as $table) {
                $this->assertSame(0, DB::table($table)->count());
            }
        }
        DB::unprepared('DROP TRIGGER reject_usdt_outbound');
        $this->travel(3)->minutes();
        app(UsdtTopUpSettlement::class)->recover();
        $this->assertSame(1, CustomerUsdtBalanceEntry::count());
    }

    public function test_compensated_target_retains_late_receipt_without_second_credit_or_reopen(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner, 'password' => 'owner-password']);
        $this->actingAs($owner);
        $session = app('session')->driver();
        $session->start();
        request()->setLaravelSession($session);
        config(['services.telegram.admin_telegram_chat_id' => '998877']);
        $review = app(UsdtTopUpReview::class);
        $preview = $review->request($owner, $this->target->id, 'resolved_by_compensation', '1', BscUsdtRailRpcFixture::TX, 'Authentic deposit recovery');
        $review->confirm($owner, $preview->id, $preview->fingerprint, 1, 'owner-password');
        $this->settle();
        $this->assertSame('resolved_by_compensation', DB::table('customer_usdt_top_up_settlement_work')->value('state'));
        $this->assertSame(1, CustomerUsdtBalanceEntry::count());
        $this->assertSame(1, DB::table('external_money_receipts')->count());
        $this->assertSame('review', DB::table('customer_usdt_top_up_inbox')->value('state'));
    }

    public function test_ambiguous_telegram_delivery_preserves_money_and_requires_existing_review_protocol(): void
    {
        $this->settle();
        $bot = \Mockery::mock(TelegramBotClient::class);
        $bot->shouldReceive('sendMessage')->once()->andThrow(new ConnectionException('connection interrupted'));
        $this->app->instance(TelegramBotClient::class, $bot);
        $this->app->forgetInstance(UsdtTopUpOutbound::class);
        app(UsdtTopUpOutbound::class)->deliver(TelegramOutboundEffect::sole());
        $effect = TelegramOutboundEffect::sole();
        $this->assertSame('unknown', $effect->state->value);
        $this->assertSame(1_000_000, (int) DB::table('customer_usdt_balance_accounts')->value('balance_micro'));
        $this->assertSame(1, CustomerUsdtBalanceEntry::count());
        $this->travel(1)->hours();
        $this->assertSame(0, app(UsdtTopUpOutbound::class)->recover());
    }

    public function test_lost_telegram_ack_is_not_blindly_retried_and_cannot_repeat_credit(): void
    {
        $this->settle();
        $protocol = app(TelegramOutboundEffectLedgerProtocol::class);
        $claim = $protocol->claim(TelegramOutboundEffect::sole()->id);
        $this->assertNotNull($claim);
        $this->travel(1)->hours();
        $protocol->recoverExpiredLeases();
        $this->assertSame('unknown', TelegramOutboundEffect::sole()->state->value);
        $this->assertSame(0, app(UsdtTopUpOutbound::class)->recover());
        $this->settle();
        $this->assertSame(1, CustomerUsdtBalanceEntry::count());
        $this->assertSame(1_000_000, (int) DB::table('customer_usdt_balance_accounts')->value('balance_micro'));
    }

    public function test_application_fee_is_accounted_separately_without_customer_overcredit_or_false_drift(): void
    {
        $this->settle();
        $this->travel(20)->minutes();
        CustomerUsdtFundingPolicy::current()->update(['application_fee_micro' => 100_000, 'version' => 2]);
        $actions = app(TelegramUsdtFundingActions::class);
        $this->target = $actions->openConfirmedIntent('951001', '951001', 'private', 100, '1')->topUp;
        app(UsdtTopUpPreparation::class)->prepare($this->target->id);
        $this->rpc->timestamp = now()->timestamp + 5;
        $hash = '0x'.str_repeat('c', 64);
        $this->rpc->receipt['transactionHash'] = $hash;
        $this->rpc->receipt['logs'] = [$this->rpc->log(amount: '1100000000000000000')];
        $this->rpc->receipt['logs'][0]['transactionHash'] = $hash;
        $this->settle($hash);
        $this->assertSame(2_000_000, (int) DB::table('customer_usdt_balance_accounts')->value('balance_micro'));
        $this->assertSame(100_000, (int) DB::table('customer_usdt_top_up_fee_events')->value('amount_micro'));
        $report = app(UsdtReceiptReconciliation::class)->inspect(null, null, null, 100);
        $this->assertSame([], $report->findings);
        $custody = UsdtTreasuryAssetObservation::available('fixture_custody', '2100000', now());
        $coverage = app(UsdtTreasuryReconciliation::class)
            ->inspect(null, $report->unresolvedDepositMicro, $report->overcreditedMicro, [$custody]);
        $this->assertSame('consistent', $coverage->verdict->value);
        $this->assertSame('100000', $coverage->accountedFundingFeeMicro->toString());
    }

    public function test_additional_receipt_after_settlement_is_unresolved_not_matched_to_original_credit(): void
    {
        $this->settle();
        $hash = '0x'.str_repeat('c', 64);
        $this->rpc->receipt['transactionHash'] = $hash;
        $this->rpc->receipt['logs'][0]['transactionHash'] = $hash;
        $this->travel(1)->minutes();
        app(UsdtTopUpSettlement::class)->submit($this->customer, $this->target->id, $hash);
        app(UsdtTopUpSettlement::class)->check($this->target->id);
        $this->assertSame(1, CustomerUsdtBalanceEntry::count());
        $this->assertSame(2, DB::table('external_money_receipts')->count());
        $report = app(UsdtReceiptReconciliation::class)->inspect($this->customer->id, null, null, 100);
        $this->assertSame('1000000', $report->unresolvedDepositMicro->toString());
        $this->assertCount(1, $report->findings);
        $other = Customer::factory()->create();
        $scoped = app(UsdtReceiptReconciliation::class)->inspect($other->id, null, null, 100);
        $this->assertSame([], $scoped->findings);
        $this->assertSame('settled', DB::table('customer_usdt_top_up_settlement_work')->value('state'));
    }

    public function test_operator_explicit_resend_retains_receipt_snapshot_without_repeating_money(): void
    {
        $this->settle();
        $original = TelegramOutboundEffect::sole();
        $protocol = app(TelegramOutboundEffectLedgerProtocol::class);
        $claim = $protocol->claim($original->id);
        $protocol->finalizeUnknown($original->id, $claim->claim_token, new ConnectionException('connection interrupted'));
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $resend = app(TelegramOutboundEffectService::class)->resend($original->fresh(), $owner);
        $bot = \Mockery::mock(TelegramBotClient::class);
        $bot->shouldReceive('sendMessage')->once()->with((string) $this->customer->telegram_id,
            $original->outcome_metadata['message'], [], ['parse_mode' => null])
            ->andReturn(['ok' => true, 'result' => ['message_id' => 9001]]);
        $this->app->instance(TelegramBotClient::class, $bot);
        app(UsdtTopUpOutbound::class)->deliver($resend);
        $this->assertSame('sent', $resend->fresh()->state->value);
        $this->assertSame('unknown', $original->fresh()->state->value);
        $this->assertSame($original->id, $resend->supersedes_effect_id);
        $this->assertSame(1, CustomerUsdtBalanceEntry::count());
    }

    public function test_resolved_invoice_allows_next_target_and_old_poll_cannot_claim_its_new_receipt(): void
    {
        $this->settle();
        $original = $this->target;
        $actions = app(TelegramUsdtFundingActions::class);
        $this->target = $actions->openConfirmedIntent('951001', '951001', 'private', 100, '1')->topUp;
        app(UsdtTopUpPreparation::class)->prepare($this->target->id);
        $this->assertSame(2, DB::table('usdt_bsc_rail_invoices')->count());
        $hash = '0x'.str_repeat('d', 64);
        $this->rpc->receipt['transactionHash'] = $hash;
        $this->rpc->receipt['logs'][0]['transactionHash'] = $hash;
        $this->travel(1)->minutes();
        $service = app(UsdtTopUpSettlement::class);
        $service->submit($this->customer, $original->id, $hash);
        $service->check($original->id);
        $this->assertSame(1, DB::table('external_money_receipts')->count());
        $this->assertSame(1, CustomerUsdtBalanceEntry::count());
        $service->submit($this->customer, $this->target->id, $hash);
        $service->check($this->target->id);
        $this->assertSame(2, CustomerUsdtBalanceEntry::count());
        $this->assertSame(2_000_000, (int) DB::table('customer_usdt_balance_accounts')->value('balance_micro'));
        $this->assertSame(2, DB::table('customer_usdt_top_up_resolutions')->count());
    }
}
