<?php

namespace Tests\Feature;

use App\Enums\ExternalMoneyReceiptClaimOutcome;
use App\Enums\ExternalMoneyReceiptStatus;
use App\Models\ExternalMoneyReceipt;
use App\Models\ExternalMoneyReceiptAlias;
use App\Models\ExternalMoneyReceiptParentClaim;
use App\Services\MoneyReceipts\ExternalMoneyReceiptClaims;
use App\Services\MoneyReceipts\MoneyReceiptAlias;
use App\Services\MoneyReceipts\MoneyReceiptIdentity;
use App\Services\MoneyReceipts\MoneyReceiptParentClaim;
use App\Services\MoneyReceipts\MoneyReceiptTarget;
use App\Services\MoneyReceipts\VerifiedMoneyReceipt;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\RunsPostgresRace;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
final class ExternalMoneyReceiptPostgresConcurrencyTest extends TestCase
{
    use RunsPostgresRace;

    private const MARKER_ACTION = 'external-money-receipt-race-marker';

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

    public function test_postgres_competing_targets_claiming_the_same_receipt_produce_one_claim(): void
    {
        $this->requirePostgresConcurrency();

        $results = $this->race([
            fn (): string => app(ExternalMoneyReceiptClaims::class)->claim($this->bscReceipt('alpha', 0, MoneyReceiptTarget::orderPayment('order-1')))->value,
            fn (): string => app(ExternalMoneyReceiptClaims::class)->claim($this->bscReceipt('alpha', 0, MoneyReceiptTarget::orderPayment('order-2')))->value,
        ]);

        $sorted = $results;
        sort($sorted);
        $this->assertSame(['claimed', 'conflict'], $sorted);

        $winner = $results[0] === 'claimed' ? 'order-1' : 'order-2';
        $this->assertSame(1, ExternalMoneyReceipt::query()->whereIn('target_id', ['order-1', 'order-2'])->count());
        $this->assertSame($winner, ExternalMoneyReceipt::query()->whereIn('target_id', ['order-1', 'order-2'])->sole()->target_id);
        $this->assertSame($winner, ExternalMoneyReceiptParentClaim::query()->whereIn('target_id', ['order-1', 'order-2'])->sole()->target_id);
    }

    public function test_postgres_competing_replays_claim_one_receipt_and_one_replayed(): void
    {
        $this->requirePostgresConcurrency();

        $receipt = $this->bscReceipt('alpha', 0, MoneyReceiptTarget::orderPayment('order-1'));
        $results = $this->race([
            fn (): string => app(ExternalMoneyReceiptClaims::class)->claim($receipt)->value,
            fn (): string => app(ExternalMoneyReceiptClaims::class)->claim($receipt)->value,
        ]);

        sort($results);
        $this->assertSame(['claimed', 'replayed'], $results);
        $this->assertSame(1, ExternalMoneyReceipt::query()->count());
        $this->assertSame(1, ExternalMoneyReceiptParentClaim::query()->count());
    }

    public function test_postgres_alias_collision_rolls_back_the_loser_and_keeps_both_transactions_usable(): void
    {
        $this->requirePostgresConcurrency();

        $alias = new MoneyReceiptAlias('custody_deposit', MoneyReceiptIdentity::providerReference('custody', 'account-1', 'deposit-1'));
        $results = $this->race([
            fn (): array => $this->claimWithMarker($this->bscReceipt('alpha', 0, MoneyReceiptTarget::orderPayment('order-1'), aliases: [$alias]), 'first'),
            fn (): array => $this->claimWithMarker($this->bscReceipt('beta', 0, MoneyReceiptTarget::orderPayment('order-2'), aliases: [$alias]), 'second'),
        ]);

        $outcomes = array_column($results, 'outcome');
        sort($outcomes);
        $this->assertSame(['claimed', 'conflict'], $outcomes);
        $this->assertSame(1, ExternalMoneyReceipt::query()->count());
        $this->assertSame(1, ExternalMoneyReceiptParentClaim::query()->count());
        $this->assertSame(
            ExternalMoneyReceipt::query()->sole()->id,
            ExternalMoneyReceiptAlias::query()->where('alias_key', $alias->identity->canonicalKey())->sole()->external_money_receipt_id,
        );
        // Both caller transactions committed their own work after the losing
        // claim recovered from the unique violation without an aborted
        // PostgreSQL transaction.
        $this->assertSame(2, DB::table('audit_logs')->where('action', self::MARKER_ACTION)->count());
    }

    public function test_postgres_held_alias_recovers_the_loser_through_a_savepoint_without_partial_writes(): void
    {
        $this->requirePostgresConcurrency();

        $signal = sys_get_temp_dir().'/receipt-alias-race-'.bin2hex(random_bytes(8));
        $alias = new MoneyReceiptAlias('custody_deposit', MoneyReceiptIdentity::providerReference('custody', 'account-1', 'deposit-1'));
        $loser = $this->bscReceipt('beta', 0, MoneyReceiptTarget::orderPayment('order-2'), aliases: [$alias]);

        try {
            $results = $this->race([
                function () use ($signal, $alias): string {
                    DB::transaction(function () use ($signal, $alias): void {
                        $hash = '0x'.substr(hash('sha256', 'holder'), 0, 64);
                        DB::table('external_money_receipts')->insert([
                            'canonical_key' => 'v1|bsc_log|chain:56|tx:'.$hash.'|log:0',
                            'provider' => 'bsc',
                            'identity_kind' => 'bsc_log',
                            'chain_id' => 56,
                            'tx_hash' => $hash,
                            'log_index' => 0,
                            'beneficiary' => '0x'.str_repeat('a', 40),
                            'currency' => 'USDT',
                            'amount_units' => '2500000',
                            'status' => 'claimed',
                            'target_type' => 'order_payment',
                            'target_id' => 'order-holder',
                            'fingerprint' => str_repeat('f', 64),
                            'evidence_payload' => json_encode([]),
                            'claimed_at' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        DB::table('external_money_receipt_aliases')->insert([
                            'external_money_receipt_id' => (int) DB::getPdo()->lastInsertId(),
                            'alias_key' => $alias->identity->canonicalKey(),
                            'alias_type' => $alias->type,
                            'created_at' => now(),
                        ]);
                        touch($signal);
                        sleep(2);
                    }, 1);

                    return 'holder';
                },
                function () use ($signal, $loser): array {
                    $deadline = microtime(true) + 10;
                    while (! is_file($signal) && microtime(true) < $deadline) {
                        usleep(1000);
                    }

                    return $this->claimWithMarker($loser, 'loser');
                },
            ]);

            $this->assertSame('holder', $results[0]);
            $this->assertSame('conflict', $results[1]['outcome']);
            $this->assertSame(1, ExternalMoneyReceipt::query()->count());
            $this->assertSame('order-holder', ExternalMoneyReceipt::query()->sole()->target_id);
            $this->assertSame(1, ExternalMoneyReceiptAlias::query()->count());
            $this->assertSame(1, DB::table('audit_logs')->where('action', self::MARKER_ACTION)->count());
        } finally {
            @unlink($signal);
        }
    }

    public function test_postgres_cross_target_logs_in_one_transaction_enter_review_once(): void
    {
        $this->requirePostgresConcurrency();

        $results = $this->race([
            fn (): string => app(ExternalMoneyReceiptClaims::class)->claim($this->bscReceipt('alpha', 0, MoneyReceiptTarget::orderPayment('order-1')))->value,
            fn (): string => app(ExternalMoneyReceiptClaims::class)->claim($this->bscReceipt('alpha', 1, MoneyReceiptTarget::orderPayment('order-2')))->value,
        ]);

        sort($results);
        $this->assertSame(['claimed', 'review'], $results);
        $this->assertSame(2, ExternalMoneyReceipt::query()->count());
        $this->assertSame(1, ExternalMoneyReceiptParentClaim::query()->count());

        $parent = ExternalMoneyReceiptParentClaim::query()->sole();
        $this->assertSame(ExternalMoneyReceiptStatus::Review, $parent->status);
        $this->assertSame(ExternalMoneyReceipt::REVIEW_REASON_BSC_PARENT_CROSS_TARGET, $parent->review_reason);

        $claimed = ExternalMoneyReceipt::query()->where('status', ExternalMoneyReceiptStatus::Claimed->value)->sole();
        $reviewed = ExternalMoneyReceipt::query()->where('status', ExternalMoneyReceiptStatus::Review->value)->sole();
        $this->assertSame($parent->target_id, $claimed->target_id);
        $this->assertNotNull($claimed->claimed_at);
        $this->assertNull($reviewed->claimed_at);
        $this->assertSame(ExternalMoneyReceipt::REVIEW_REASON_BSC_PARENT_CROSS_TARGET, $reviewed->review_reason);
    }

    public function test_postgres_registry_constraints_reject_duplicate_identities_and_invalid_amounts(): void
    {
        $this->requirePostgresConcurrency();

        $receipt = $this->bscReceipt('alpha', 0, MoneyReceiptTarget::orderPayment('order-1'), aliases: [
            new MoneyReceiptAlias('custody_deposit', MoneyReceiptIdentity::providerReference('custody', 'account-1', 'deposit-1')),
        ]);
        $this->assertSame(ExternalMoneyReceiptClaimOutcome::Claimed, app(ExternalMoneyReceiptClaims::class)->claim($receipt));

        $storedReceipt = ExternalMoneyReceipt::query()->sole();
        $receiptAttributes = $storedReceipt->getAttributes();
        unset($receiptAttributes['id']);
        $aliasAttributes = ExternalMoneyReceiptAlias::query()->where('alias_type', 'custody_deposit')->sole()->getAttributes();
        unset($aliasAttributes['id']);
        $parentAttributes = ExternalMoneyReceiptParentClaim::query()->sole()->getAttributes();
        unset($parentAttributes['id']);

        $invalidAmountKey = 'v1|bsc_log|chain:56|tx:0x'.str_repeat('e', 64).'|log:0';

        $this->assertConstraintRejects('external_money_receipts_canonical_key_unique', fn () => DB::table('external_money_receipts')->insert($receiptAttributes));
        $this->assertConstraintRejects('external_money_receipt_aliases_alias_key_unique', fn () => DB::table('external_money_receipt_aliases')->insert($aliasAttributes));
        $this->assertConstraintRejects('external_money_receipt_parent_claims_chain_tx_unique', fn () => DB::table('external_money_receipt_parent_claims')->insert($parentAttributes));
        $this->assertConstraintRejects('external_money_receipts_amount_units_positive', fn () => DB::table('external_money_receipts')->insert([
            ...$receiptAttributes,
            'canonical_key' => $invalidAmountKey,
            'amount_units' => '0',
        ]));
    }

    private function assertConstraintRejects(string $expectedConstraint, callable $statement): void
    {
        try {
            $statement();
            $this->fail("The database constraint [{$expectedConstraint}] was expected to reject this row.");
        } catch (QueryException $exception) {
            $this->assertStringContainsString($expectedConstraint, $exception->getMessage());
        }
    }

    /** @param list<MoneyReceiptAlias> $aliases */
    private function bscReceipt(string $txSeed, int $logIndex, MoneyReceiptTarget $target, array $aliases = []): VerifiedMoneyReceipt
    {
        $hash = '0x'.substr(hash('sha256', $txSeed), 0, 64);

        return new VerifiedMoneyReceipt(
            identity: MoneyReceiptIdentity::bscLog(56, $hash, $logIndex),
            beneficiary: '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            currency: 'USDT',
            amountUnits: '2500000',
            decimals: 6,
            target: $target,
            evidence: ['tx_hash' => $hash, 'log_index' => $logIndex, 'confirmations' => 12],
            aliases: $aliases,
            parentClaim: MoneyReceiptParentClaim::for(56, $hash),
        );
    }

    /** @return array{outcome: string, marker: string} */
    private function claimWithMarker(VerifiedMoneyReceipt $receipt, string $marker): array
    {
        return DB::transaction(function () use ($receipt, $marker): array {
            $outcome = app(ExternalMoneyReceiptClaims::class)->claim($receipt);
            DB::table('audit_logs')->insert([
                'action' => self::MARKER_ACTION,
                'subject_type' => 'test',
                'subject_id' => $marker,
                'created_at' => now(),
            ]);

            return ['outcome' => $outcome->value, 'marker' => $marker];
        }, 1);
    }

    private function cleanupFixtures(): void
    {
        if (! Schema::hasTable('external_money_receipts')) {
            return;
        }

        DB::table('external_money_receipt_parent_claims')->delete();
        DB::table('external_money_receipt_aliases')->delete();
        DB::table('external_money_receipts')->delete();
        DB::table('audit_logs')->where('action', self::MARKER_ACTION)->delete();
    }

    private function concurrencyLaneAvailable(): bool
    {
        return DB::getDriverName() === 'pgsql' && (string) env('RUN_CONCURRENCY_TESTS', '') === '1';
    }

    private function requirePostgresConcurrency(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('External money receipt races require the pgsql test connection.');
        }

        if ((string) env('RUN_CONCURRENCY_TESTS', '') !== '1') {
            $this->markTestSkipped('Set RUN_CONCURRENCY_TESTS=1 to run process-level PostgreSQL races.');
        }

        $this->assertSafeConcurrencyDatabase();
    }

    private function assertSafeConcurrencyDatabase(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->fail('The pcntl extension is required for external money receipt races.');
        }

        if (! extension_loaded('pdo_pgsql')) {
            $this->fail('The pdo_pgsql extension is required for external money receipt races.');
        }

        if (app()->environment() !== 'testing') {
            $this->fail('Refusing external money receipt races outside APP_ENV=testing.');
        }

        if (config('database.default') !== 'pgsql' || config('database.connections.pgsql.driver') !== 'pgsql') {
            $this->fail('External money receipt races require DB_CONNECTION=pgsql.');
        }

        if ((string) config('database.connections.pgsql.url') !== '' || (string) env('DB_URL', '') !== '') {
            $this->fail('Refusing external money receipt races while DB_URL is configured.');
        }

        $database = (string) config('database.connections.pgsql.database');
        if (preg_match('/(^|[_-])(prod|production|live|main)([_-]|$)/i', $database) === 1
            || ! preg_match('/(^|[_-])(test|testing)([_-]|$)/i', $database)) {
            $this->fail("External money receipt races require an isolated test database; got [{$database}].");
        }

        $actualDatabase = (string) DB::selectOne('select current_database() as database')->database;
        if ($actualDatabase !== $database) {
            $this->fail("Connected database [{$actualDatabase}] does not match configured database [{$database}].");
        }
    }
}
