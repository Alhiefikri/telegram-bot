<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Services\Customer\CustomerBalanceOverviewReader;
use App\Services\StoreBalance\StoreBalanceLedger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class CustomerBalanceWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_balance_page_requires_the_customer_session_and_exposes_no_write_route(): void
    {
        $this->get(route('customer.balance.show'))->assertRedirect(route('customer.account'));
        $this->post(route('customer.balance.show'))->assertMethodNotAllowed();
    }

    public function test_balance_overview_reads_both_currency_snapshots_in_one_query_and_shows_frozen_state(): void
    {
        $customer = Customer::factory()->create([
            'email' => 'balance-owner@example.test',
            'email_verified_at' => now(),
            'telegram_id' => 9007199254740993,
        ]);
        app(StoreBalanceLedger::class)->creditTopUp($customer, 12_345, 'overview-idr', 'overview-idr:credit');
        $this->createUsdtHistory($customer, 2, 'incident:fixture');

        $selects = [];
        DB::listen(function (QueryExecuted $query) use (&$selects): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                $selects[] = $query->sql;
            }
        });

        $overview = app(CustomerBalanceOverviewReader::class)->forCustomer($customer);

        $this->assertCount(1, $selects, 'The IDR and USDT projections and ledger cursors must share one statement.');
        $this->assertTrue($overview->storeBalance->isAvailable());
        $this->assertSame(12_345, $overview->storeBalance->balance());
        $this->assertTrue($overview->usdtBalance->isAvailable());
        $this->assertSame(2_000_000, $overview->usdtBalance->balanceMicro());
        $this->assertTrue($overview->usdtBalance->isFrozen());

        $this->actingAs($customer, 'customer')->get(route('customer.balance.show'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSee('Rp 12.345')
            ->assertSee('2 USDT')
            ->assertSee('dibekukan sementara')
            ->assertSee('hanya-baca')
            ->assertDontSee((string) $customer->telegram_id);
    }

    public function test_customer_history_uses_keyset_pages_and_rejects_another_customers_cursor(): void
    {
        $customer = Customer::factory()->create(['email' => 'idr-owner@example.test', 'email_verified_at' => now()]);
        $other = Customer::factory()->create(['email' => 'idr-other@example.test', 'email_verified_at' => now()]);
        for ($index = 1; $index <= 26; $index++) {
            app(StoreBalanceLedger::class)->creditTopUp(
                $customer,
                100,
                'web-history-'.$index,
                'web-history:'.$index,
            );
        }

        $firstPage = $this->actingAs($customer, 'customer')
            ->get(route('customer.balance.show', ['currency' => 'IDR']))
            ->assertOk();
        $this->assertSame(25, substr_count($firstPage->getContent(), 'Dikreditkan'));
        $cursor = $this->cursorFromOlderLink($firstPage->getContent());

        $secondPage = $this->actingAs($customer, 'customer')
            ->get(route('customer.balance.show', ['currency' => 'IDR', 'direction' => 'older', 'cursor' => $cursor]))
            ->assertOk();
        $this->assertSame(1, substr_count($secondPage->getContent(), 'Dikreditkan'));

        $this->actingAs($other, 'customer')
            ->get(route('customer.balance.show', ['currency' => 'IDR', 'direction' => 'older', 'cursor' => $cursor]))
            ->assertNotFound();
    }

    public function test_usdt_history_is_customer_scoped_and_uses_signed_keyset_cursors(): void
    {
        $customer = Customer::factory()->create(['email' => 'usdt-owner@example.test', 'email_verified_at' => now()]);
        $other = Customer::factory()->create(['email' => 'usdt-other@example.test', 'email_verified_at' => now()]);
        $this->createUsdtHistory($customer, 26);
        $otherEntryId = $this->createUsdtHistory($other, 1);

        $firstPage = $this->actingAs($customer, 'customer')
            ->get(route('customer.balance.show', ['currency' => 'USDT']))
            ->assertOk()
            ->assertDontSee('#'.$otherEntryId);
        $this->assertSame(25, substr_count($firstPage->getContent(), 'Saldo masuk'));
        $cursor = $this->cursorFromOlderLink($firstPage->getContent());

        $secondPage = $this->actingAs($customer, 'customer')
            ->get(route('customer.balance.show', ['currency' => 'USDT', 'direction' => 'older', 'cursor' => $cursor]))
            ->assertOk();
        $this->assertSame(1, substr_count($secondPage->getContent(), 'Saldo masuk'));

        $this->actingAs($other, 'customer')
            ->get(route('customer.balance.show', ['currency' => 'USDT', 'direction' => 'older', 'cursor' => $cursor]))
            ->assertNotFound();
        $this->actingAs($customer, 'customer')
            ->get(route('customer.balance.show', ['currency' => 'IDR', 'direction' => 'older', 'cursor' => $cursor]))
            ->assertNotFound();
    }

    public function test_guest_recovery_key_or_telegram_id_does_not_authenticate_the_customer_balance(): void
    {
        $customer = Customer::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->getKey()]);
        $recoveryKey = 'LHIE-PRIVATE-RECOVERY-KEY';
        $order->forceFill(['credential_access_key_hash' => Hash::make($recoveryKey)])->save();

        $this->get(route('customer.balance.show', [
            'invoice_number' => $order->invoice_number,
            'access_key' => $recoveryKey,
            'telegram_id' => (string) $customer->telegram_id,
        ]))->assertRedirect(route('customer.account'));
    }

    private function createUsdtHistory(Customer $customer, int $entryCount, ?string $frozenReason = null): int
    {
        $now = now();
        $accountId = DB::table('customer_usdt_balance_accounts')->insertGetId([
            'customer_id' => $customer->getKey(),
            'currency' => 'USDT',
            'balance_micro' => $entryCount * 1_000_000,
            'version' => $entryCount,
            'frozen_reason' => $frozenReason,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $lastEntryId = 0;

        for ($sequence = 1; $sequence <= $entryCount; $sequence++) {
            $lastEntryId = DB::table('customer_usdt_balance_entries')->insertGetId([
                'customer_usdt_balance_account_id' => $accountId,
                'account_sequence' => $sequence,
                'kind' => 'funding_credit',
                'amount_micro' => 1_000_000,
                'balance_after_micro' => $sequence * 1_000_000,
                'source_type' => 'fixture',
                'source_id' => 'customer-'.$customer->getKey().'-'.$sequence,
                'idempotency_key' => 'web-test:'.$customer->getKey().':'.$sequence,
                'request_fingerprint' => hash('sha256', 'web-test:'.$customer->getKey().':'.$sequence),
                'created_at' => CarbonImmutable::instance($now)->subSeconds($entryCount - $sequence),
            ]);
        }

        if ($lastEntryId > 0) {
            DB::table('customer_usdt_balance_accounts')->where('id', $accountId)->update(['last_entry_id' => $lastEntryId]);
        }

        return $lastEntryId;
    }

    private function cursorFromOlderLink(string $html): string
    {
        $this->assertSame(1, preg_match('/href="([^"]*direction=older[^"]*)"/', $html, $matches));
        parse_str((string) parse_url(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5), PHP_URL_QUERY), $query);
        $this->assertIsString($query['cursor'] ?? null);

        return $query['cursor'];
    }
}
