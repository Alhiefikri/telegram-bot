<?php

namespace Tests\Feature;

use App\Enums\StoreBalanceEntryKind;
use App\Models\Customer;
use App\Models\CustomerBalanceAccount;
use App\Models\StoreBalanceEntry;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\StoreBalance\StoreBalanceReconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class StoreBalanceReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_consistent_projection_reports_no_mismatch(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 902101]);
        $ledger = app(StoreBalanceLedger::class);
        $ledger->credit($customer, 50000, StoreBalanceEntryKind::TopUpCredit, 'balance_top_up', '21', 'balance_top_up:21:credit');
        $ledger->debit($customer, 20000, 'order', '301', 'order:301:debit');

        $report = app(StoreBalanceReconciliation::class)->inspect();

        $this->assertTrue($report->isConsistent());
        $this->assertSame(1, $report->accountsInspected);
        $this->assertSame(30000, $ledger->snapshot($customer)->balance());

        $this->artisan('store-balance:check')
            ->expectsOutputToContain('consistent=true accounts_inspected=1 mismatches=0')
            ->assertSuccessful();
    }

    public function test_a_projection_mismatch_is_detected_without_changing_money(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 902102]);
        app(StoreBalanceLedger::class)->credit($customer, 5000, StoreBalanceEntryKind::TopUpCredit, 'balance_top_up', '22', 'balance_top_up:22:credit');
        CustomerBalanceAccount::query()->where('customer_id', $customer->id)->update(['balance' => 9000]);

        $report = app(StoreBalanceReconciliation::class)->inspect();

        $this->assertFalse($report->isConsistent());
        $this->assertCount(1, $report->mismatches);
        $this->assertSame($customer->id, $report->mismatches[0]->customerId);
        $this->assertSame(9000, $report->mismatches[0]->projectedBalance);
        $this->assertSame(5000, $report->mismatches[0]->ledgerBalance);

        $this->artisan('store-balance:check')
            ->expectsOutputToContain('projected=9000 ledger=5000')
            ->assertFailed();

        $this->assertSame(9000, CustomerBalanceAccount::query()->where('customer_id', $customer->id)->value('balance'));
        $this->assertSame(1, StoreBalanceEntry::query()->count());
    }

    public function test_the_check_scopes_to_one_customer_with_a_bounded_limit(): void
    {
        $first = Customer::factory()->create(['telegram_id' => 902103]);
        $second = Customer::factory()->create(['telegram_id' => 902104]);
        $ledger = app(StoreBalanceLedger::class);
        $ledger->credit($first, 4000, StoreBalanceEntryKind::TopUpCredit, 'balance_top_up', '23', 'balance_top_up:23:credit');
        $ledger->credit($second, 6000, StoreBalanceEntryKind::TopUpCredit, 'balance_top_up', '24', 'balance_top_up:24:credit');
        CustomerBalanceAccount::query()->where('customer_id', $second->id)->update(['balance' => 1234]);

        $scoped = app(StoreBalanceReconciliation::class)->inspect($first, 10);
        $this->assertTrue($scoped->isConsistent());
        $this->assertSame(1, $scoped->accountsInspected);

        $all = app(StoreBalanceReconciliation::class)->inspect(null, 1);
        $this->assertTrue($all->isConsistent());
        $this->assertSame(1, $all->accountsInspected);

        $withMismatch = app(StoreBalanceReconciliation::class)->inspect(null, 10);
        $this->assertFalse($withMismatch->isConsistent());
        $this->assertSame(2, $withMismatch->accountsInspected);
    }

    public function test_the_json_report_is_sanitized_and_exits_non_zero_on_mismatch(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 902105]);
        app(StoreBalanceLedger::class)->credit($customer, 7000, StoreBalanceEntryKind::TopUpCredit, 'balance_top_up', '25', 'balance_top_up:25:credit');
        CustomerBalanceAccount::query()->where('customer_id', $customer->id)->update(['balance' => 1]);

        $exitCode = Artisan::call('store-balance:check', ['--json' => true]);
        $this->assertSame(1, $exitCode);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertFalse($payload['consistent']);
        $this->assertSame(1, $payload['accounts_inspected']);
        $this->assertSame(1, $payload['mismatch_count']);
        $this->assertSame([
            ['customer_id' => $customer->id, 'projected_balance' => 1, 'ledger_balance' => 7000],
        ], $payload['mismatches']);
        $this->assertStringNotContainsString('balance_top_up', Artisan::output());
    }

    public function test_the_check_rejects_invalid_arguments(): void
    {
        $this->artisan('store-balance:check', ['--customer' => 'not-a-number'])->assertFailed();
        $this->artisan('store-balance:check', ['--customer' => '99999999'])->assertFailed();
        $this->artisan('store-balance:check', ['--limit' => '0'])->assertFailed();
    }
}
