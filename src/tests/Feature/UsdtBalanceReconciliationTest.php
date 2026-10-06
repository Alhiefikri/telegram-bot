<?php

namespace Tests\Feature;

use App\Enums\UsdtTreasuryCoverageVerdict;
use App\Enums\UsdtTreasuryMovementDirection;
use App\Models\Customer;
use App\Models\CustomerUsdtBalanceAccount;
use App\Models\ExternalMoneyReceipt;
use App\Models\UsdtBalanceReconciliationRun;
use App\Models\UsdtTreasuryMovement;
use App\Services\MoneyReceipts\ExternalMoneyReceiptClaims;
use App\Services\MoneyReceipts\MoneyReceiptIdentity;
use App\Services\MoneyReceipts\MoneyReceiptTarget;
use App\Services\MoneyReceipts\VerifiedMoneyReceipt;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtAccountingAggregator;
use App\Services\UsdtBalance\UsdtBalanceDriftFreezer;
use App\Services\UsdtBalance\UsdtBalanceFreezeOutcome;
use App\Services\UsdtBalance\UsdtBalanceReconciliation;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\VerifiedUsdtFunding;
use App\Services\UsdtTreasury\UsdtTreasuryAssetObservation;
use App\Services\UsdtTreasury\UsdtTreasuryAssetProvider;
use App\Services\UsdtTreasury\UsdtTreasuryAssetRegistry;
use App\Services\UsdtTreasury\UsdtTreasuryMovementInput;
use App\Services\UsdtTreasury\UsdtTreasuryMovements;
use App\Services\UsdtTreasury\UsdtTreasuryMovementStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

final class UsdtBalanceReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_empty_store_reports_consistent_without_custody_input(): void
    {
        [$exit, $report] = $this->reconcileJson();

        $this->assertSame(0, $exit);
        $this->assertSame('consistent', $report['verdict']);
        $this->assertSame('not_required', $report['treasury']['verdict']);
        $this->assertSame('missing', $report['treasury']['custody_state']);
        $this->assertNull($report['treasury']['custody_assets_micro']);
        $this->assertSame('0', $report['treasury']['ledger_liability_micro']);
        $this->assertTrue($report['accounting']['consistent']);

        $run = UsdtBalanceReconciliationRun::query()->sole();
        $this->assertSame('consistent', $run->verdict->value);
        $this->assertSame('missing', $run->custody_state);
        $this->assertNull($run->custody_assets_micro);
    }

    public function test_a_fully_backed_liability_is_consistent_and_exits_zero(): void
    {
        $customer = $this->customer(910001);
        $this->creditWithReceipt($customer, 2_500_000, 'topup-backed');

        $this->bindCustody([['test_custody', 2_500_000]]);

        [$exit, $report] = $this->reconcileJson();

        $this->assertSame(0, $exit);
        $this->assertSame('consistent', $report['treasury']['verdict']);
        $this->assertSame('test_custody', $report['treasury']['custody'][0]['provider']);
        $this->assertSame('2500000', $report['treasury']['custody_assets_micro']);
        $this->assertSame('2500000', $report['treasury']['ledger_liability_micro']);
        $this->assertSame('2500000', $report['treasury']['projected_liability_micro']);
    }

    public function test_exact_sum_larger_than_a_signed_integer_stays_exact(): void
    {
        $first = $this->customer(910002);
        $second = $this->customer(910003);
        $this->creditWithReceipt($first, 4_000_000_000_000_000_000, 'topup-large-a');
        $this->creditWithReceipt($second, 4_000_000_000_000_000_000, 'topup-large-b');

        $this->bindCustody([['test_custody', 8_000_000_000_000_000_000]]);

        [$exit, $report] = $this->reconcileJson();

        $this->assertSame(0, $exit);
        $this->assertSame('8000000000000000000', $report['treasury']['ledger_liability_micro']);
        $this->assertSame('8000000000000000000', $report['treasury']['custody_assets_micro']);
        $this->assertSame('consistent', $report['treasury']['verdict']);
    }

    public function test_missing_custody_input_is_unverifiable_and_never_zero(): void
    {
        $customer = $this->customer(910004);
        $this->credit($customer, 1_000_000, 'topup-unbacked');

        [$exit, $report] = $this->reconcileJson();

        $this->assertSame(1, $exit);
        $this->assertSame('findings', $report['verdict']);
        $this->assertSame('unverifiable', $report['treasury']['verdict']);
        $this->assertSame('missing', $report['treasury']['custody_state']);
        $this->assertNull($report['treasury']['custody_assets_micro']);
        $this->assertNull($report['treasury']['unexplained_micro']);
        $this->assertContains('missing_custody_input', $report['treasury']['findings']);

        $run = UsdtBalanceReconciliationRun::query()->sole();
        $this->assertSame('findings', $run->verdict->value);
        $this->assertNull($run->custody_assets_micro);
        $this->assertSame(1_000_000, (int) $run->liability_micro);
    }

    public function test_a_custody_deficit_and_surplus_are_both_findings(): void
    {
        $customer = $this->customer(910005);
        $this->credit($customer, 1_000_000, 'topup-coverage');

        $this->bindCustody([['test_custody', 700_000]]);
        [$exit, $report] = $this->reconcileJson();
        $this->assertSame(1, $exit);
        $this->assertSame('deficit', $report['treasury']['verdict']);
        $this->assertSame('-300000', $report['treasury']['unexplained_micro']);

        $this->bindCustody([['test_custody', 1_400_000]]);
        [$exit, $report] = $this->reconcileJson();
        $this->assertSame(1, $exit);
        $this->assertSame('surplus', $report['treasury']['verdict']);
        $this->assertSame('400000', $report['treasury']['unexplained_micro']);
        $this->assertContains('unexplained_variance', $report['treasury']['findings']);
    }

    public function test_an_unavailable_adapter_is_not_proven_zero(): void
    {
        $customer = $this->customer(910006);
        $this->credit($customer, 1_000_000, 'topup-unavailable');

        $provider = new class implements UsdtTreasuryAssetProvider
        {
            public function providerKey(): string
            {
                return 'test_custody';
            }

            public function observe(): UsdtTreasuryAssetObservation
            {
                return UsdtTreasuryAssetObservation::unavailable('test_custody', 'rpc_unreachable');
            }
        };
        $this->app->instance(UsdtTreasuryAssetRegistry::class, new UsdtTreasuryAssetRegistry([$provider]));

        [$exit, $report] = $this->reconcileJson();

        $this->assertSame(1, $exit);
        $this->assertSame('unavailable', $report['treasury']['custody_state']);
        $this->assertNull($report['treasury']['custody_assets_micro']);
        $this->assertSame('unverifiable', $report['treasury']['verdict']);
        $this->assertSame('rpc_unreachable', $report['treasury']['custody'][0]['reason']);
    }

    public function test_a_throwing_custody_adapter_is_unverifiable_not_zero(): void
    {
        $customer = $this->customer(910028);
        $this->credit($customer, 1_000_000, 'topup-adapter-error');

        $provider = new class implements UsdtTreasuryAssetProvider
        {
            public function providerKey(): string
            {
                return 'test_custody';
            }

            public function observe(): UsdtTreasuryAssetObservation
            {
                throw new \RuntimeException('custody transport failed');
            }
        };
        $this->app->instance(UsdtTreasuryAssetRegistry::class, new UsdtTreasuryAssetRegistry([$provider]));

        [$exit, $report] = $this->reconcileJson();

        $this->assertSame(1, $exit);
        $this->assertSame('unverifiable', $report['treasury']['verdict']);
        $this->assertNull($report['treasury']['custody_assets_micro']);
        $this->assertSame('adapter_error', $report['treasury']['custody'][0]['reason']);
    }

    public function test_audited_movements_explain_custody_variance(): void
    {
        $customer = $this->customer(910007);
        $this->creditWithReceipt($customer, 1_000_000, 'topup-movement');
        $this->movement('in', 500_000, '0xmovement-in');
        $this->movement('out', 200_000, '0xmovement-out');

        // liability 1.000.000 + in 500.000 - out 200.000 = 1.300.000
        $this->bindCustody([['test_custody', 1_300_000]]);
        [$exit, $report] = $this->reconcileJson();
        $this->assertSame(0, $exit);
        $this->assertSame('consistent', $report['treasury']['verdict']);
        $this->assertSame('500000', $report['treasury']['movement_in_micro']);
        $this->assertSame('200000', $report['treasury']['movement_out_micro']);
        $this->assertSame(2, $report['treasury']['movement_count']);

        // The same movement totals without matching custody stay visible.
        $this->bindCustody([['test_custody', 1_000_000]]);
        [$exit, $report] = $this->reconcileJson();
        $this->assertSame(1, $exit);
        $this->assertSame('deficit', $report['treasury']['verdict']);
        $this->assertSame('-300000', $report['treasury']['unexplained_micro']);
    }

    public function test_a_projection_drift_is_detected_frozen_and_never_repaired(): void
    {
        $customer = $this->customer(910008);
        $this->credit($customer, 1_000_000, 'topup-drift');
        DB::table('customer_usdt_balance_accounts')
            ->where('customer_id', $customer->id)
            ->update(['balance_micro' => 999_000]);

        [$exit, $report] = $this->reconcileJson();

        $this->assertSame(1, $exit);
        $this->assertSame('findings', $report['verdict']);
        $this->assertCount(1, $report['accounts']['findings']);
        $finding = $report['accounts']['findings'][0];
        $this->assertSame('balance_mismatch', $finding['issues'][0]);
        $this->assertSame('999000', $finding['projected_micro']);
        $this->assertSame('1000000', $finding['ledger_micro']);
        $this->assertSame('frozen', $finding['freeze']);

        $account = CustomerUsdtBalanceAccount::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame('reconciliation_drift:balance_mismatch', $account->frozen_reason);
        $this->assertSame(999_000, (int) $account->balance_micro, 'The drifted projection is never repaired.');

        $run = UsdtBalanceReconciliationRun::query()->sole();
        $this->assertSame(1, (int) $run->account_finding_count);
        $this->assertSame(1, (int) $run->drift_frozen_count);
    }

    public function test_no_freeze_reports_the_drift_without_touching_the_account(): void
    {
        $customer = $this->customer(910009);
        $this->credit($customer, 1_000_000, 'topup-no-freeze');
        DB::table('customer_usdt_balance_accounts')
            ->where('customer_id', $customer->id)
            ->update(['version' => 9]);

        [$exit, $report] = $this->reconcileJson(['--no-freeze' => true]);

        $this->assertSame(1, $exit);
        $this->assertSame('cursor_mismatch', $report['accounts']['findings'][0]['issues'][0]);
        $this->assertNull($report['accounts']['findings'][0]['freeze']);
        $account = CustomerUsdtBalanceAccount::query()->where('customer_id', $customer->id)->sole();
        $this->assertNull($account->frozen_reason);
        $this->assertSame(0, (int) UsdtBalanceReconciliationRun::query()->sole()->drift_frozen_count);
    }

    public function test_a_history_gap_is_detected_even_when_the_sum_and_cursor_agree(): void
    {
        $customer = $this->customer(910010);
        $this->credit($customer, 100_000, 'topup-gap');
        $account = CustomerUsdtBalanceAccount::query()->where('customer_id', $customer->id)->sole();

        $entryId = DB::table('customer_usdt_balance_entries')->insertGetId([
            'customer_usdt_balance_account_id' => $account->id,
            'account_sequence' => 3,
            'kind' => 'funding_credit',
            'amount_micro' => 50_000,
            'balance_after_micro' => 150_000,
            'source_type' => 'customer_usdt_top_up',
            'source_id' => 'topup-gap-2',
            'idempotency_key' => 'usdt:gap-2',
            'request_fingerprint' => str_repeat('a', 64),
            'created_at' => now(),
        ]);
        DB::table('customer_usdt_balance_accounts')->where('id', $account->id)->update([
            'balance_micro' => 150_000,
            'version' => 3,
            'last_entry_id' => $entryId,
        ]);

        [$exit, $report] = $this->reconcileJson();

        $this->assertSame(1, $exit);
        $this->assertSame(['history_gap'], $report['accounts']['findings'][0]['issues']);
        $this->assertSame('150000', $report['treasury']['projected_liability_micro']);
    }

    public function test_a_freeze_is_scoped_and_idempotent_across_runs(): void
    {
        $drifted = $this->customer(910011);
        $healthy = $this->customer(910012);
        $this->credit($drifted, 1_000_000, 'topup-scope-a');
        $this->credit($healthy, 2_000_000, 'topup-scope-b');
        DB::table('customer_usdt_balance_accounts')
            ->where('customer_id', $drifted->id)
            ->update(['balance_micro' => 1]);

        [, $first] = $this->reconcileJson();
        [, $second] = $this->reconcileJson();

        $this->assertSame('frozen', $first['accounts']['findings'][0]['freeze']);
        $this->assertSame('already_frozen', $second['accounts']['findings'][0]['freeze']);
        $this->assertCount(1, $first['accounts']['findings']);
        $this->assertSame(
            'reconciliation_drift:balance_mismatch',
            (string) CustomerUsdtBalanceAccount::query()->where('customer_id', $drifted->id)->sole()->frozen_reason,
        );
        $this->assertNull(
            CustomerUsdtBalanceAccount::query()->where('customer_id', $healthy->id)->sole()->frozen_reason,
        );

        $runs = UsdtBalanceReconciliationRun::query()->orderBy('id')->get();
        $this->assertCount(2, $runs);
        $this->assertSame(1, (int) $runs[0]->drift_frozen_count);
        $this->assertSame(0, (int) $runs[1]->drift_frozen_count, 'An already frozen account is not frozen twice.');
    }

    public function test_a_stale_finding_never_freezes_a_repaired_account(): void
    {
        $customer = $this->customer(910013);
        $this->credit($customer, 1_000_000, 'topup-stale');
        DB::table('customer_usdt_balance_accounts')
            ->where('customer_id', $customer->id)
            ->update(['balance_micro' => 999_000]);

        $finding = app(UsdtBalanceReconciliation::class)->inspect(null, null, 10)->findings[0];
        DB::table('customer_usdt_balance_accounts')
            ->where('customer_id', $customer->id)
            ->update(['balance_micro' => 1_000_000]);

        $outcome = app(UsdtBalanceDriftFreezer::class)->freeze($finding);

        $this->assertSame(UsdtBalanceFreezeOutcome::Stale, $outcome);
        $this->assertNull(
            CustomerUsdtBalanceAccount::query()->where('customer_id', $customer->id)->sole()->frozen_reason,
        );
    }

    public function test_an_orphan_claimed_receipt_is_visible_with_its_exact_amount(): void
    {
        $this->claimTopUpReceipt('topup-orphan', '1500000');

        [$exit, $report] = $this->reconcileJson();

        $this->assertSame(1, $exit);
        $this->assertSame(1, $report['receipts']['inspected']);
        $this->assertSame('orphan_claimed_receipt', $report['receipts']['findings'][0]['kind']);
        $this->assertSame('1500000', $report['receipts']['findings'][0]['receipt_micro']);
        $this->assertNull($report['receipts']['findings'][0]['entry_id']);
        $this->assertSame('1500000', $report['receipts']['unresolved_deposit_micro']);
        $this->assertSame('unverifiable', $report['treasury']['verdict']);
        $this->assertSame('1500000', $report['treasury']['unresolved_deposit_micro']);

        $run = UsdtBalanceReconciliationRun::query()->sole();
        $this->assertSame(1_500_000, (int) $run->unresolved_deposit_micro);
    }

    public function test_a_held_receipt_is_reported_separately_from_an_orphan(): void
    {
        $receipt = $this->claimTopUpReceipt('topup-held', '2000000');
        ExternalMoneyReceipt::query()->whereKey($receipt)->update([
            'status' => 'review',
            'review_reason' => 'test_hold',
        ]);

        [$exit, $report] = $this->reconcileJson();

        $this->assertSame(1, $exit);
        $this->assertSame('held_receipt', $report['receipts']['findings'][0]['kind']);
        $this->assertSame('2000000', $report['receipts']['unresolved_deposit_micro']);
    }

    public function test_a_held_receipt_with_a_settled_credit_is_still_a_finding(): void
    {
        $customer = $this->customer(910029);
        $this->credit($customer, 1_000_000, 'topup-held-settled', 'receipt-held-settled');
        $receiptId = $this->claimTopUpReceipt('topup-held-settled', '1000000');
        ExternalMoneyReceipt::query()->whereKey($receiptId)->update([
            'status' => 'review',
            'review_reason' => 'test_hold',
        ]);

        [$exit, $report] = $this->reconcileJson();

        $this->assertSame(1, $exit);
        $this->assertSame('held_receipt', $report['receipts']['findings'][0]['kind']);
        $this->assertNotNull($report['receipts']['findings'][0]['entry_id']);
        $this->assertSame('0', $report['receipts']['unresolved_deposit_micro']);
    }

    public function test_a_credit_without_a_claimed_receipt_is_detected(): void
    {
        $customer = $this->customer(910014);
        $this->credit($customer, 1_200_000, 'topup-no-receipt');

        [$exit, $report] = $this->reconcileJson();

        $this->assertSame(1, $exit);
        $this->assertSame(1, $report['receipts']['unreceipted_credits_inspected']);
        $this->assertSame('credit_without_receipt', $report['receipts']['findings'][0]['kind']);
        $this->assertSame('topup-no-receipt', $report['receipts']['findings'][0]['target_id']);
        $this->assertSame((string) $customer->id, (string) $report['receipts']['findings'][0]['customer_id']);
        $this->assertSame('1200000', $report['receipts']['findings'][0]['credited_micro']);
    }

    public function test_a_settled_receipt_with_a_difference_stays_visible(): void
    {
        $customer = $this->customer(910015);
        $this->credit($customer, 900_000, 'topup-short', 'receipt-short');
        $this->claimTopUpReceipt('topup-short', '1000000');

        [$exit, $report] = $this->reconcileJson();

        $this->assertSame(1, $exit);
        $finding = $report['receipts']['findings'][0];
        $this->assertSame('unsettled_difference', $finding['kind']);
        $this->assertSame('1000000', $finding['receipt_micro']);
        $this->assertSame('900000', $finding['credited_micro']);
        $this->assertSame('100000', $finding['difference_micro']);
        $this->assertSame('100000', $report['receipts']['unresolved_deposit_micro']);
    }

    public function test_an_overcredited_receipt_is_detected(): void
    {
        $customer = $this->customer(910016);
        $this->credit($customer, 1_100_000, 'topup-over', 'receipt-over');
        $this->claimTopUpReceipt('topup-over', '1000000');

        [$exit, $report] = $this->reconcileJson();

        $this->assertSame(1, $exit);
        $finding = $report['receipts']['findings'][0];
        $this->assertSame('overcredited_receipt', $finding['kind']);
        $this->assertSame('100000', $finding['difference_micro']);
        $this->assertSame('100000', $report['receipts']['overcredited_micro']);
        $this->assertSame('0', $report['receipts']['unresolved_deposit_micro']);
    }

    public function test_an_exact_settled_receipt_is_not_a_finding(): void
    {
        $customer = $this->customer(910017);
        $this->credit($customer, 1_000_000, 'topup-exact', 'receipt-exact');
        $this->claimTopUpReceipt('topup-exact', '1000000');
        $this->bindCustody([['test_custody', 1_000_000]]);

        [$exit, $report] = $this->reconcileJson();

        $this->assertSame(0, $exit);
        $this->assertSame(1, $report['receipts']['inspected']);
        $this->assertSame(0, $report['receipts']['unreceipted_credits_inspected']);
        $this->assertSame([], $report['receipts']['findings']);
        $this->assertSame('1000000', $report['treasury']['ledger_liability_micro']);
    }

    public function test_an_unrepresentable_or_wrong_currency_receipt_is_visible(): void
    {
        $this->claimTopUpReceipt('topup-dust', '1', decimals: 18);
        $this->claimTopUpReceipt('topup-currency', '5000000', currency: 'BUSD');

        [$exit, $report] = $this->reconcileJson();

        $this->assertSame(1, $exit);
        $kinds = array_column($report['receipts']['findings'], 'kind');
        $this->assertContains('receipt_amount_unrepresentable', $kinds);
        $this->assertContains('currency_mismatch', $kinds);
        $this->assertSame('0', $report['receipts']['unresolved_deposit_micro'], 'Unprovable money is never summed as an exact amount.');
    }

    public function test_the_scan_is_bounded_by_limit_and_cursor(): void
    {
        foreach ([910018, 910019, 910020] as $index => $telegramId) {
            $this->credit($this->customer($telegramId), 1_000_000, 'topup-page-'.$index);
        }

        [$exit, $report] = $this->reconcileJson(['--limit' => 2]);
        $this->assertSame(1, $exit);
        $this->assertSame(2, $report['accounts']['inspected']);
        $this->assertTrue($report['accounts']['has_more']);
        $this->assertNotNull($report['accounts']['next_cursor']);

        [$exit, $report] = $this->reconcileJson([
            '--limit' => 2,
            '--cursor' => $report['accounts']['next_cursor'],
        ]);
        $this->assertSame(1, $exit);
        $this->assertSame(1, $report['accounts']['inspected']);
        $this->assertFalse($report['accounts']['has_more']);
        $this->assertNull($report['accounts']['next_cursor']);
    }

    public function test_scoped_pages_retain_drift_detection_and_empty_accounts(): void
    {
        $healthy = $this->customer(910030);
        $drifted = $this->customer(910031);
        $empty = $this->customer(910032);
        $this->credit($healthy, 1_000_000, 'page-healthy');
        $this->credit($drifted, 2_000_000, 'page-drifted');
        $driftedAccount = CustomerUsdtBalanceAccount::query()->where('customer_id', $drifted->id)->sole();
        DB::table('customer_usdt_balance_accounts')->where('id', $driftedAccount->id)->update(['balance_micro' => 1]);
        DB::table('customer_usdt_balance_accounts')->insert([
            'customer_id' => $empty->id, 'currency' => 'USDT', 'balance_micro' => 0,
            'version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $reader = app(UsdtBalanceReconciliation::class);
        $first = $reader->inspect(null, null, 1);
        $this->assertSame(1, $first->accountsInspected);
        $this->assertTrue($first->isConsistent());
        $this->assertTrue($first->hasMore);
        $second = $reader->inspect(null, $first->nextCursor, 1);
        $this->assertSame(1, $second->accountsInspected);
        $this->assertCount(1, $second->findings);
        $this->assertSame((int) $drifted->id, $second->findings[0]->customerId);
        $third = $reader->inspect(null, $second->nextCursor, 1);
        $this->assertSame(1, $third->accountsInspected);
        $this->assertTrue($third->isConsistent());
        $this->assertFalse($third->hasMore);
        $this->assertTrue($reader->inspect((int) $healthy->id, null, 1000)->isConsistent());
        $this->assertNotNull($reader->inspectAccount((int) $driftedAccount->id));
    }

    public function test_customer_scoping_limits_the_report_without_hiding_orphan_receipts(): void
    {
        $scoped = $this->customer(910021);
        $other = $this->customer(910022);
        $this->credit($scoped, 1_000_000, 'topup-scope-scoped');
        $this->credit($other, 2_000_000, 'topup-scope-other');
        $this->claimTopUpReceipt('topup-scope-orphan', '500000');

        [$exit, $report] = $this->reconcileJson(['--customer' => $scoped->id]);

        $this->assertSame(1, $exit);
        $this->assertSame('customer', $report['scope']['mode']);
        $this->assertSame(1, $report['accounts']['inspected']);
        $this->assertSame('1000000', $report['treasury']['ledger_liability_micro']);
        // Orphan receipts cannot be attributed to a Customer yet; they stay visible.
        $this->assertSame('orphan_claimed_receipt', $report['receipts']['findings'][0]['kind']);
        $this->assertSame('not_evaluated', $report['treasury']['verdict']);
    }

    public function test_receipts_and_credits_paginate_with_their_own_cursors(): void
    {
        $customer = $this->customer(910023);
        $this->credit($customer, 1_000_000, 'topup-credit-page-1');
        $this->credit($customer, 2_000_000, 'topup-credit-page-2');
        $this->claimTopUpReceipt('topup-receipt-page-1', '3000000');
        $this->claimTopUpReceipt('topup-receipt-page-2', '4000000');

        [$exit, $first] = $this->reconcileJson(['--limit' => 1]);
        $this->assertSame(1, $exit);
        $this->assertSame(1, $first['receipts']['inspected']);
        $this->assertSame(1, $first['receipts']['unreceipted_credits_inspected']);
        $this->assertTrue($first['receipts']['receipts_has_more']);
        $this->assertTrue($first['receipts']['credits_have_more']);

        [$exit, $second] = $this->reconcileJson([
            '--limit' => 1,
            '--receipt-cursor' => $first['receipts']['next_receipt_cursor'],
            '--credit-cursor' => $first['receipts']['next_credit_cursor'],
        ]);
        $this->assertSame(1, $exit);
        $this->assertSame(1, $second['receipts']['inspected']);
        $this->assertSame(1, $second['receipts']['unreceipted_credits_inspected']);
        $this->assertFalse($second['receipts']['receipts_has_more']);
        $this->assertFalse($second['receipts']['credits_have_more']);
        $this->assertNotSame(
            $first['receipts']['findings'][0]['receipt_id'],
            $second['receipts']['findings'][0]['receipt_id'],
        );
    }

    public function test_the_report_is_sanitized_and_rejects_invalid_arguments(): void
    {
        $customer = Customer::factory()->create([
            'telegram_id' => 910024,
            'first_name' => 'SensitiveBuyerName',
            'username' => 'sensitive_buyer_handle',
        ]);
        $this->credit($customer, 1_000_000, 'topup-sanitized');
        $this->claimTopUpReceipt('topup-sanitized', '1000000', reference: '0xdeadbeefdeadbeefdeadbeefdeadbeef');

        [, $report] = $this->reconcileJson(['--actor' => 'operator-7']);
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('SensitiveBuyerName', $encoded);
        $this->assertStringNotContainsString('sensitive_buyer_handle', $encoded);
        $this->assertStringNotContainsString('0xdeadbeef', $encoded);
        $this->assertStringNotContainsString((string) $customer->telegram_id, $encoded);

        $this->artisan('usdt-balance:reconcile', ['--limit' => 0])
            ->expectsOutputToContain('limit must be between 1 and 1000')
            ->assertFailed();
        $this->artisan('usdt-balance:reconcile', ['--customer' => 'abc'])
            ->expectsOutputToContain('must be a non-negative integer')
            ->assertFailed();
        $this->artisan('usdt-balance:reconcile', ['--customer' => 999_999_999])
            ->expectsOutputToContain('Customer not found')
            ->assertFailed();
    }

    public function test_accounting_aggregates_are_idempotent_and_exact(): void
    {
        $customer = $this->customer(910025);
        $this->credit($customer, 5_000_000, 'topup-aggregate');

        $first = Artisan::call('usdt-balance:aggregate-accounting');
        $this->assertSame(0, $first);
        $status = app(UsdtAccountingAggregator::class)->status('reporting-v1');
        $this->assertSame(1, $status->claimedEvents);
        $this->assertSame(1, $status->aggregatedEvents);
        $this->assertSame(0, $status->pendingEvents);
        $this->assertTrue($status->isConsistent());
        $this->assertSame(1, DB::table('usdt_balance_accounting_aggregates')->count());
        $this->assertSame(
            ['usdt_settlement_asset', 'customer_usdt_liability'],
            [(string) DB::table('usdt_balance_accounting_aggregates')->value('debit_account'), (string) DB::table('usdt_balance_accounting_aggregates')->value('credit_account')],
        );

        $second = Artisan::call('usdt-balance:aggregate-accounting');
        $this->assertSame(0, $second);
        $this->assertSame(1, (int) DB::table('usdt_balance_accounting_aggregates')->value('entries_count'));
        $this->assertSame(5_000_000, (int) DB::table('usdt_balance_accounting_aggregates')->value('amount_micro'));

        [, $report] = $this->reconcileJson();
        $this->assertTrue($report['accounting']['consistent']);
        $this->assertSame(0, $report['accounting']['pending_events']);
    }

    public function test_a_mismatched_accounting_aggregate_is_a_finding(): void
    {
        $customer = $this->customer(910026);
        $this->credit($customer, 1_000_000, 'topup-aggregate-mismatch');
        Artisan::call('usdt-balance:aggregate-accounting');
        DB::table('usdt_balance_accounting_aggregates')->update(['entries_count' => 5]);

        [$exit, $report] = $this->reconcileJson();

        $this->assertSame(1, $exit);
        $this->assertFalse($report['accounting']['consistent']);
        $this->assertSame(1, $report['accounting']['claimed_events']);
        $this->assertSame(5, $report['accounting']['aggregated_events']);
    }

    public function test_a_restore_replay_is_idempotent_and_keeps_unexplained_drift_visible(): void
    {
        $customer = $this->customer(910027);
        $this->credit($customer, 1_000_000, 'topup-restore');
        Artisan::call('usdt-balance:aggregate-accounting');

        // A restore that lost the derived claim rows but kept aggregate rows
        // must not double count silently: replay reports the divergence.
        DB::table('usdt_balance_accounting_aggregate_claims')->delete();
        Artisan::call('usdt-balance:aggregate-accounting');
        [, $divergent] = $this->reconcileJson();
        $this->assertFalse($divergent['accounting']['consistent']);
        $this->assertSame(2, $divergent['accounting']['aggregated_events']);
        $this->assertSame(1, $divergent['accounting']['claimed_events']);

        // Operator recovery rebuilds only derived rows from the immutable
        // events, and the next replay reproduces the same evidence.
        DB::table('usdt_balance_accounting_aggregates')->delete();
        DB::table('usdt_balance_accounting_aggregate_claims')->delete();
        Artisan::call('usdt-balance:aggregate-accounting');
        DB::table('customer_usdt_balance_accounts')->where('customer_id', $customer->id)->update(['balance_micro' => 1]);

        [$exit, $replayed] = $this->reconcileJson();
        $this->assertSame(1, $exit);
        $this->assertTrue($replayed['accounting']['consistent']);
        $this->assertSame('balance_mismatch', $replayed['accounts']['findings'][0]['issues'][0]);
        $this->assertSame('reconciliation_drift:balance_mismatch', (string) CustomerUsdtBalanceAccount::query()->where('customer_id', $customer->id)->sole()->frozen_reason);

        $this->assertSame(2, UsdtBalanceReconciliationRun::query()->count());
    }

    public function test_a_movement_is_recorded_once_and_conflicts_on_changed_values(): void
    {
        $movements = app(UsdtTreasuryMovements::class);
        $input = new UsdtTreasuryMovementInput(
            direction: UsdtTreasuryMovementDirection::Out,
            amount: UsdtMicroAmount::fromDecimalString('10.5'),
            reference: '0xsigned-sweep',
            reason: 'external_return',
            actor: 'owner',
            occurredAt: now(),
        );

        $applied = $movements->record($input);
        $replayed = $movements->record($input);

        $this->assertSame(UsdtTreasuryMovementStatus::Applied, $applied->status);
        $this->assertSame(UsdtTreasuryMovementStatus::Replayed, $replayed->status);
        $this->assertSame($applied->movementId, $replayed->movementId);
        $this->assertSame(1, UsdtTreasuryMovement::query()->count());
        $this->assertSame(10_500_000, (int) UsdtTreasuryMovement::query()->sole()->amount_micro);

        $this->expectException(InvalidArgumentException::class);
        $movements->record(new UsdtTreasuryMovementInput(
            direction: UsdtTreasuryMovementDirection::Out,
            amount: UsdtMicroAmount::fromDecimalString('11'),
            reference: '0xsigned-sweep',
            reason: 'external_return',
            actor: 'owner',
            occurredAt: now(),
        ));
    }

    public function test_the_movement_command_validates_input(): void
    {
        $this->artisan('usdt-balance:record-treasury-movement')
            ->assertFailed();

        $this->artisan('usdt-balance:record-treasury-movement', [
            '--direction' => 'sideways',
            '--amount' => '1',
            '--reference' => 'ref',
            '--reason' => 'reason',
            '--actor' => 'owner',
        ])->expectsOutputToContain('--direction')->assertFailed();

        $this->artisan('usdt-balance:record-treasury-movement', [
            '--direction' => 'in',
            '--amount' => '0',
            '--reference' => 'ref',
            '--reason' => 'reason',
            '--actor' => 'owner',
        ])->assertFailed();

        $this->artisan('usdt-balance:record-treasury-movement', [
            '--direction' => 'in',
            '--amount' => '1.25',
            '--reference' => 'ref',
            '--reason' => 'gas_topup',
            '--actor' => 'owner',
        ])->assertSuccessful();
        $this->assertSame(1_250_000, (int) UsdtTreasuryMovement::query()->sole()->amount_micro);
    }

    public function test_the_verdict_enum_marks_only_real_divergence_as_findings(): void
    {
        $this->assertFalse(UsdtTreasuryCoverageVerdict::Consistent->isFinding());
        $this->assertFalse(UsdtTreasuryCoverageVerdict::NotRequired->isFinding());
        $this->assertFalse(UsdtTreasuryCoverageVerdict::NotEvaluated->isFinding());
        $this->assertTrue(UsdtTreasuryCoverageVerdict::Deficit->isFinding());
        $this->assertTrue(UsdtTreasuryCoverageVerdict::Surplus->isFinding());
        $this->assertTrue(UsdtTreasuryCoverageVerdict::Unverifiable->isFinding());
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function reconcileJson(array $options = []): array
    {
        $exit = Artisan::call('usdt-balance:reconcile', $options + ['--json' => true]);
        $output = Artisan::output();
        $report = $this->jsonPayload($output);

        return [$exit, $report];
    }

    /** @return array<string, mixed> */
    private function jsonPayload(string $output): array
    {
        foreach (explode("\n", trim($output)) as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] === '{') {
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        $this->fail("No JSON payload found in command output: {$output}");
    }

    private function customer(int $telegramId): Customer
    {
        return Customer::factory()->create(['telegram_id' => $telegramId]);
    }

    private function credit(Customer $customer, int $micro, string $topUpId, string $receiptId = 'receipt-fixture'): void
    {
        app(CustomerUsdtBalanceLedger::class)->creditVerifiedTopUp(
            $customer,
            new VerifiedUsdtFunding((int) $customer->id, UsdtMicroAmount::fromUnits($micro), $topUpId, $receiptId, 'v1'),
            'usdt:'.$topUpId,
        );
    }

    private function creditWithReceipt(Customer $customer, int $micro, string $topUpId): void
    {
        $receiptId = $this->claimTopUpReceipt($topUpId, (string) $micro);
        app(CustomerUsdtBalanceLedger::class)->creditVerifiedTopUp(
            $customer,
            new VerifiedUsdtFunding((int) $customer->id, UsdtMicroAmount::fromUnits($micro), $topUpId, (string) $receiptId, 'v1'),
            'usdt:'.$topUpId,
        );
    }

    private function claimTopUpReceipt(
        string $topUpId,
        string $amountUnits,
        int $decimals = 6,
        string $currency = 'USDT',
        string $provider = 'bsc',
        ?string $reference = null,
    ): int {
        $receipt = new VerifiedMoneyReceipt(
            identity: MoneyReceiptIdentity::providerReference($provider, 'test-receiver', $reference ?? 'ref-'.$topUpId),
            beneficiary: 'store-receiver',
            currency: $currency,
            amountUnits: $amountUnits,
            decimals: $decimals,
            target: MoneyReceiptTarget::customerUsdtTopUp($topUpId),
            evidence: ['tx_hash' => '0xdeadbeefdeadbeef'],
        );

        $outcome = app(ExternalMoneyReceiptClaims::class)->claim($receipt);
        $this->assertSame('claimed', $outcome->value, 'The receipt fixture must be claimed successfully.');

        return (int) ExternalMoneyReceipt::query()->where('target_id', $topUpId)->sole()->id;
    }

    /**
     * @param  list<array{0: string, 1: int}>  $observations
     */
    private function bindCustody(array $observations): void
    {
        $providers = [];
        foreach ($observations as [$key, $assetsMicro]) {
            $providers[] = new class($key, $assetsMicro) implements UsdtTreasuryAssetProvider
            {
                public function __construct(private string $key, private int $assetsMicro) {}

                public function providerKey(): string
                {
                    return $this->key;
                }

                public function observe(): UsdtTreasuryAssetObservation
                {
                    return UsdtTreasuryAssetObservation::available($this->key, $this->assetsMicro, now());
                }
            };
        }

        $this->app->instance(UsdtTreasuryAssetRegistry::class, new UsdtTreasuryAssetRegistry($providers));
    }

    private function movement(string $direction, int $micro, string $reference): void
    {
        app(UsdtTreasuryMovements::class)->record(new UsdtTreasuryMovementInput(
            direction: UsdtTreasuryMovementDirection::from($direction),
            amount: UsdtMicroAmount::fromUnits($micro),
            reference: $reference,
            reason: 'test_movement',
            actor: 'owner',
            occurredAt: now(),
        ));
    }
}
