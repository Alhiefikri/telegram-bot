<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\UsdtBscRailInvoice;
use App\Models\User;
use App\Services\Bsc\BscVerificationRpcClientInterface;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtReceivers\UsdtDepositReceiverRegistry;
use App\Services\UsdtTopUps\BscUsdtTransferIngestion;
use App\Services\UsdtTopUps\UsdtTopUpRail;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\BscUsdtRailRpcFixture;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\RunsPostgresRace;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
final class BscUsdtRailPostgresConcurrencyTest extends TestCase
{
    use RunsPostgresRace;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql' || (string) env('RUN_CONCURRENCY_TESTS', '') !== '1') {
            $this->markTestSkipped('BSC USDT rail races require disposable PostgreSQL and RUN_CONCURRENCY_TESTS=1.');
        }
        $this->assertTrue(function_exists('pcntl_fork'));
        $this->assertSame(0, PostgresTestDatabase::migrateFresh());
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'is_active' => true, 'bsc_min_confirmations' => 3]);
        Queue::fake();
    }

    public function test_identical_concurrent_creates_recover_one_invoice_and_one_enrollment(): void
    {
        $customer = $this->customer();
        $deadline = CarbonImmutable::now()->addMinutes(15)->startOfSecond();
        $callbacks = [];
        for ($i = 0; $i < 2; $i++) {
            $callbacks[] = function () use ($customer, $deadline): string {
                $this->bindRpc();

                return app(UsdtTopUpRail::class)->createOrRecover($customer, 'sandbox:race:identical', UsdtMicroAmount::fromDecimalString('1'), $deadline)->id;
            };
        }
        $results = $this->race($callbacks);
        $this->assertSame($results[0], $results[1]);
        $this->assertSame(1, UsdtBscRailInvoice::query()->count());
        $this->assertSame(1, DB::table('usdt_bsc_scan_checkpoints')->count());
        $this->assertNoMoney();
    }

    public function test_different_concurrent_references_cannot_overlap_one_receiver_window(): void
    {
        $customer = $this->customer();
        $deadline = CarbonImmutable::now()->addMinutes(15)->startOfSecond();
        $callbacks = [];
        foreach (['a', 'b'] as $suffix) {
            $callbacks[] = function () use ($customer, $deadline, $suffix): string {
                $this->bindRpc();
                try {
                    app(UsdtTopUpRail::class)->createOrRecover($customer, 'sandbox:race:'.$suffix, UsdtMicroAmount::fromDecimalString('1'), $deadline);

                    return 'created';
                } catch (DomainException) {
                    return 'conflict';
                }
            };
        }
        $results = $this->race($callbacks);
        sort($results);
        $this->assertSame(['conflict', 'created'], $results);
        $this->assertSame(1, UsdtBscRailInvoice::query()->count());
        $this->assertNoMoney();
    }

    public function test_global_reference_contention_between_customers_recovers_after_unique_failure(): void
    {
        $first = $this->customer();
        $second = $this->customer('0x'.str_repeat('1', 40));
        $deadline = CarbonImmutable::now()->addMinutes(15)->startOfSecond();
        $callbacks = [];
        foreach ([$first, $second] as $customer) {
            $callbacks[] = function () use ($customer, $deadline): string {
                $this->bindRpc();
                try {
                    app(UsdtTopUpRail::class)->createOrRecover($customer, 'sandbox:race:global', UsdtMicroAmount::fromDecimalString('1'), $deadline);

                    return 'created';
                } catch (AuthorizationException) {
                    // A new statement succeeds after the losing transaction was
                    // rolled back; do not continue an aborted PostgreSQL tx.
                    DB::selectOne('SELECT 1 AS healthy');

                    return 'foreign';
                }
            };
        }
        $results = $this->race($callbacks);
        sort($results);
        $this->assertSame(['created', 'foreign'], $results);
        $this->assertSame(1, UsdtBscRailInvoice::query()->count());
        $this->assertSame(1, DB::table('usdt_bsc_scan_checkpoints')->count());
        $this->assertNoMoney();
    }

    public function test_concurrent_scan_commits_one_full_page_and_deduplicates_physical_log(): void
    {
        $customer = $this->customer();
        $receiverId = app(UsdtDepositReceiverRegistry::class)->snapshotFor($customer)->receiverId;
        $callback = function () use ($receiverId): string {
            $this->bindRpc();

            return app(BscUsdtTransferIngestion::class)->scan($receiverId, 100)['status'];
        };
        $results = $this->race([$callback, $callback]);
        $this->assertContains('complete', $results);
        $checkpoint = DB::table('usdt_bsc_scan_checkpoints')->where('receiver_id', $receiverId)->sole();
        $this->assertSame(1, (int) $checkpoint->version);
        $this->assertSame(105, (int) $checkpoint->last_block);
        $this->assertSame(BscUsdtRailRpcFixture::blockHash(105), $checkpoint->last_block_hash);
        $this->assertSame(1, DB::table('usdt_bsc_transfer_observations')->count());
        $this->assertNoMoney();
    }

    public function test_postgres_guards_reject_invoice_rewrites_deletion_and_foreign_receiver_binding(): void
    {
        $customer = $this->customer();
        $this->bindRpc();
        $invoice = app(UsdtTopUpRail::class)->createOrRecover($customer, 'sandbox:guard', UsdtMicroAmount::fromDecimalString('1'), CarbonImmutable::now()->addMinutes(15));
        $writes = [
            fn () => DB::table('usdt_bsc_rail_invoices')->where('id', $invoice->id)->update(['amount_micro' => 2000000]),
            fn () => DB::table('usdt_bsc_rail_invoices')->where('id', $invoice->id)->delete(),
        ];
        $attributes = $invoice->getAttributes();
        $attributes['id'] = (string) Str::uuid();
        $attributes['reference'] = 'sandbox:foreign';
        $attributes['customer_id'] = Customer::factory()->create()->getKey();
        $writes[] = fn () => DB::table('usdt_bsc_rail_invoices')->insert($attributes);
        foreach ($writes as $write) {
            try {
                DB::transaction($write);
                $this->fail('PostgreSQL must reject this evidence write.');
            } catch (QueryException $exception) {
                $this->assertSame('P0001', (string) ($exception->errorInfo[0] ?? ''));
            }
        }
        $this->assertSame(1000000, $invoice->fresh()->amount_micro);
        $this->assertNoMoney();
    }

    private function bindRpc(): void
    {
        $rpc = new BscUsdtRailRpcFixture;
        $rpc->logs = [$rpc->log()];
        $this->app->instance(BscVerificationRpcClientInterface::class, $rpc);
    }

    private function customer(string $address = BscUsdtRailRpcFixture::ADDRESS): Customer
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $customer = Customer::factory()->create();
        $registry = app(UsdtDepositReceiverRegistry::class);
        $registry->manualVerify($registry->register($customer, $address, $owner)->receiver, true, $owner);

        return $customer;
    }

    private function assertNoMoney(): void
    {
        foreach (['external_money_receipts', 'customer_usdt_balance_entries', 'store_balance_entries'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
    }
}
