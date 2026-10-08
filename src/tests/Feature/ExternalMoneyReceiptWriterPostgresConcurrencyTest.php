<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ExternalMoneyReceipt;
use App\Models\ExternalMoneyReceiptReservation;
use App\Models\StoreBalanceEntry;
use App\Services\MoneyReceipts\ExternalMoneyReceiptReservations;
use App\Services\MoneyReceipts\ExternalMoneyReceiptWriter;
use App\Services\MoneyReceipts\MoneyReceiptIdentity;
use App\Services\MoneyReceipts\MoneyReceiptTarget;
use App\Services\MoneyReceipts\VerifiedMoneyReceipt;
use App\Services\StoreBalance\StoreBalanceLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\RunsPostgresRace;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
final class ExternalMoneyReceiptWriterPostgresConcurrencyTest extends TestCase
{
    use RunsPostgresRace;

    private ?int $fixtureCustomerId = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->concurrencyLaneAvailable()) {
            return;
        }

        $this->assertSafeConcurrencyDatabase();
        if (! Schema::hasTable('external_money_receipts')) {
            $this->assertSame(0, PostgresTestDatabase::migrateFresh());
        }
    }

    protected function tearDown(): void
    {
        if ($this->concurrencyLaneAvailable()) {
            $this->cleanupFixtures();
        }

        parent::tearDown();
    }

    public function test_postgres_checkout_and_top_up_writers_contending_for_one_receipt_fund_exactly_one_target(): void
    {
        $this->requirePostgresConcurrency();

        $customer = Customer::factory()->create(['locale' => 'en']);
        $this->fixtureCustomerId = (int) $customer->getKey();
        $identity = MoneyReceiptIdentity::binancePay('pay_id', '900001', 'TXN-WRITER-RACE');
        $checkout = new VerifiedMoneyReceipt(
            identity: $identity,
            beneficiary: 'pay_id:900001',
            currency: 'USDT',
            amountUnits: '2500000',
            decimals: 6,
            target: MoneyReceiptTarget::orderPayment('checkout-fixture'),
        );
        $topUp = new VerifiedMoneyReceipt(
            identity: $identity,
            beneficiary: 'pay_id:900001',
            currency: 'USDT',
            amountUnits: '2500000',
            decimals: 6,
            target: MoneyReceiptTarget::storeBalanceTopUp('topup-fixture'),
        );

        $results = $this->race([
            fn (): string => $this->claimThroughWriter($checkout, 'payment', 'checkout-fixture'),
            function () use ($topUp, $customer): string {
                $outcome = $this->claimThroughWriter($topUp, 'store_balance_top_up', 'topup-fixture');
                if ($outcome === 'claimed' || $outcome === 'replayed') {
                    app(StoreBalanceLedger::class)->creditTopUp(
                        $customer,
                        5000,
                        'topup-fixture',
                        'writer-race:topup-fixture:credit',
                    );
                }

                return $outcome;
            },
        ]);

        $sorted = $results;
        sort($sorted);
        $this->assertSame(['claimed', 'conflict'], $sorted);
        $this->assertSame(1, ExternalMoneyReceipt::query()->whereIn('target_id', ['checkout-fixture', 'topup-fixture'])->count());
        $this->assertSame(1, ExternalMoneyReceiptReservation::query()->whereIn('legacy_id', ['checkout-fixture', 'topup-fixture'])->count());

        // Exactly one target funds: only the top-up winner may post a ledger
        // credit when it wins the receipt.
        $this->assertSame($results[1] === 'claimed' ? 1 : 0, StoreBalanceEntry::query()->where('source_id', 'topup-fixture')->count());

        $reservation = ExternalMoneyReceiptReservation::query()->whereIn('legacy_id', ['checkout-fixture', 'topup-fixture'])->sole();
        $this->assertSame(ExternalMoneyReceiptReservations::SOURCE_RUNTIME, $reservation->source);
        $this->assertSame(ExternalMoneyReceiptReservation::REASON_REGISTRY_CONFLICT, $reservation->reason);
        $this->assertNull($reservation->resolved_at);
        $this->assertSame(
            $results[0] === 'claimed' ? 'topup-fixture' : 'checkout-fixture',
            $reservation->legacy_id,
        );
    }

    public function test_postgres_concurrent_writer_replays_do_not_reserve_or_duplicate(): void
    {
        $this->requirePostgresConcurrency();

        $receipt = new VerifiedMoneyReceipt(
            identity: MoneyReceiptIdentity::binancePay('pay_id', '900002', 'TXN-WRITER-REPLAY'),
            beneficiary: 'pay_id:900002',
            currency: 'USDT',
            amountUnits: '2500000',
            decimals: 6,
            target: MoneyReceiptTarget::orderPayment('same-target'),
        );

        $results = $this->race([
            fn (): string => $this->claimThroughWriter($receipt, 'payment', 'same-target'),
            fn (): string => $this->claimThroughWriter($receipt, 'payment', 'same-target'),
        ]);

        sort($results);
        $this->assertSame(['claimed', 'replayed'], $results);
        $this->assertSame(1, ExternalMoneyReceipt::query()->count());
        $this->assertSame(0, ExternalMoneyReceiptReservation::query()->count());
    }

    private function claimThroughWriter(VerifiedMoneyReceipt $receipt, string $legacyType, string $legacyId): string
    {
        return app(ExternalMoneyReceiptWriter::class)
            ->claim($receipt, ExternalMoneyReceiptReservations::SOURCE_RUNTIME, $legacyType, $legacyId)
            ->value;
    }

    private function cleanupFixtures(): void
    {
        if (! Schema::hasTable('external_money_receipts')) {
            return;
        }

        DB::table('external_money_receipt_parent_claims')->delete();
        DB::table('external_money_receipt_aliases')->delete();
        DB::table('external_money_receipts')->delete();
        DB::table('external_money_receipt_reservations')->delete();

        if ($this->fixtureCustomerId !== null) {
            DB::table('customers')->where('id', $this->fixtureCustomerId)->delete();
            $this->fixtureCustomerId = null;
        }
    }

    private function concurrencyLaneAvailable(): bool
    {
        return DB::getDriverName() === 'pgsql' && (string) env('RUN_CONCURRENCY_TESTS', '') === '1';
    }

    private function requirePostgresConcurrency(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Money receipt writer races require the pgsql test connection.');
        }

        if ((string) env('RUN_CONCURRENCY_TESTS', '') !== '1') {
            $this->markTestSkipped('Set RUN_CONCURRENCY_TESTS=1 to run process-level PostgreSQL races.');
        }

        $this->assertSafeConcurrencyDatabase();
    }

    private function assertSafeConcurrencyDatabase(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->fail('The pcntl extension is required for money receipt writer races.');
        }

        if (! extension_loaded('pdo_pgsql')) {
            $this->fail('The pdo_pgsql extension is required for money receipt writer races.');
        }

        if (app()->environment() !== 'testing') {
            $this->fail('Refusing money receipt writer races outside APP_ENV=testing.');
        }

        if (config('database.default') !== 'pgsql' || config('database.connections.pgsql.driver') !== 'pgsql') {
            $this->fail('Money receipt writer races require DB_CONNECTION=pgsql.');
        }

        if ((string) config('database.connections.pgsql.url') !== '' || (string) env('DB_URL', '') !== '') {
            $this->fail('Refusing money receipt writer races while DB_URL is configured.');
        }

        $database = (string) config('database.connections.pgsql.database');
        if (preg_match('/(^|[_-])(prod|production|live|main)([_-]|$)/i', $database) === 1
            || ! preg_match('/(^|[_-])(test|testing)([_-]|$)/i', $database)) {
            $this->fail("Money receipt writer races require an isolated test database; got [{$database}].");
        }

        $actualDatabase = (string) DB::selectOne('select current_database() as database')->database;
        if ($actualDatabase !== $database) {
            $this->fail("Connected database [{$actualDatabase}] does not match configured database [{$database}].");
        }
    }
}
