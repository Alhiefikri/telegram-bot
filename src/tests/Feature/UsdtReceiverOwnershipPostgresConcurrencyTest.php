<?php

namespace Tests\Feature;

use App\Enums\BscReceiverVerificationStatus;
use App\Enums\UsdtReceiverChannel;
use App\Enums\UserRole;
use App\Exceptions\BscReceiverChallengeException;
use App\Exceptions\UsdtDepositReceiverAlreadyActive;
use App\Exceptions\UsdtReceiverAddressConflict;
use App\Models\BscPaymentReceiver;
use App\Models\Customer;
use App\Models\CustomerUsdtDepositReceiver;
use App\Models\UsdtReceiverAddressClaim;
use App\Models\User;
use App\Services\Bsc\BscReceiverManager;
use App\Services\UsdtReceivers\UsdtDepositReceiverProvisioners;
use App\Services\UsdtReceivers\UsdtDepositReceiverRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\FakeUsdtDepositReceiverProvisioner;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\RunsPostgresRace;
use Tests\TestCase;

/**
 * Process-level PostgreSQL evidence for the global receiver ownership
 * registry: checkout and Customer-exclusive deposit channels can never share
 * an address, two Customers can never bind one address, concurrent allocation
 * converges on one exclusive receiver, and the database rejects history
 * rewrites that the application layer also guards.
 */
#[Group('concurrency')]
#[Group('postgres-release')]
final class UsdtReceiverOwnershipPostgresConcurrencyTest extends TestCase
{
    use RunsPostgresRace;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->concurrencyLaneAvailable()) {
            return;
        }

        $this->assertSafeConcurrencyDatabase();
        if (! Schema::hasTable('usdt_receiver_address_claims')) {
            $this->assertSame(0, PostgresTestDatabase::migrateFresh());
        }
    }

    public function test_postgres_checkout_registration_and_deposit_binding_never_share_an_address(): void
    {
        $this->requirePostgresConcurrency();

        $ownerId = (int) User::factory()->create(['role' => UserRole::Owner])->getKey();
        $customerId = (int) Customer::factory()->create()->getKey();
        $address = $this->randomAddress();

        $results = $this->race([
            function () use ($address, $ownerId): string {
                try {
                    app(BscReceiverManager::class)->add(
                        'Checkout race wallet',
                        $address,
                        User::query()->findOrFail($ownerId),
                    );

                    return 'checkout';
                } catch (BscReceiverChallengeException) {
                    return 'conflict';
                }
            },
            function () use ($address, $ownerId, $customerId): string {
                try {
                    app(UsdtDepositReceiverRegistry::class)->register(
                        Customer::query()->findOrFail($customerId),
                        $address,
                        User::query()->findOrFail($ownerId),
                    );

                    return 'deposit';
                } catch (UsdtReceiverAddressConflict) {
                    return 'conflict';
                }
            },
        ]);

        $winner = array_values(array_filter($results, fn (string $result): bool => $result !== 'conflict'));
        $this->assertCount(1, $winner);
        $this->assertContains($winner[0], ['checkout', 'deposit']);

        $checkoutRows = BscPaymentReceiver::query()->where('address_normalized', $address)->count();
        $depositRows = CustomerUsdtDepositReceiver::query()->where('address_normalized', $address)->count();
        $this->assertSame(1, $checkoutRows + $depositRows);

        $claim = UsdtReceiverAddressClaim::query()->forAddress(56, $address)->sole();
        $this->assertSame(
            $winner[0] === 'deposit' ? UsdtReceiverChannel::CustomerDeposit : UsdtReceiverChannel::Checkout,
            $claim->channel,
        );
    }

    public function test_postgres_two_customers_binding_the_same_address_produce_one_binding(): void
    {
        $this->requirePostgresConcurrency();

        $ownerId = (int) User::factory()->create(['role' => UserRole::Owner])->getKey();
        $firstCustomerId = (int) Customer::factory()->create()->getKey();
        $secondCustomerId = (int) Customer::factory()->create()->getKey();
        $address = $this->randomAddress();

        $results = $this->race([
            fn (): string => $this->bindDepositReceiver($firstCustomerId, $ownerId, $address),
            fn (): string => $this->bindDepositReceiver($secondCustomerId, $ownerId, $address),
        ]);

        $sorted = $results;
        sort($sorted);
        $this->assertSame(['conflict', 'registered'], $sorted);

        $receiver = CustomerUsdtDepositReceiver::query()->where('address_normalized', $address)->sole();
        $this->assertContains((int) $receiver->customer_id, [$firstCustomerId, $secondCustomerId]);
        $this->assertSame(
            UsdtReceiverChannel::CustomerDeposit,
            UsdtReceiverAddressClaim::query()->forAddress(56, $address)->sole()->channel,
        );
    }

    public function test_postgres_concurrent_allocation_converges_on_one_exclusive_receiver(): void
    {
        $this->requirePostgresConcurrency();

        $customerId = (int) Customer::factory()->create()->getKey();
        $this->app->instance(
            UsdtDepositReceiverProvisioners::class,
            new UsdtDepositReceiverProvisioners([FakeUsdtDepositReceiverProvisioner::random()]),
        );

        $claimsBefore = UsdtReceiverAddressClaim::query()->count();

        $results = $this->race([
            fn (): int => app(UsdtDepositReceiverRegistry::class)
                ->allocateFor(Customer::query()->findOrFail($customerId))
                ->receiverId,
            fn (): int => app(UsdtDepositReceiverRegistry::class)
                ->allocateFor(Customer::query()->findOrFail($customerId))
                ->receiverId,
        ]);

        $this->assertSame($results[0], $results[1]);

        $receivers = CustomerUsdtDepositReceiver::query()->where('customer_id', $customerId)->get();
        $this->assertCount(1, $receivers);
        $this->assertSame((int) $receivers->sole()->getKey(), $results[0]);
        $this->assertSame($claimsBefore + 1, UsdtReceiverAddressClaim::query()->count());
    }

    public function test_postgres_guards_reject_cross_channel_reuse_and_history_rewrites(): void
    {
        $this->requirePostgresConcurrency();

        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $registry = app(UsdtDepositReceiverRegistry::class);

        $checkoutAddress = $this->randomAddress();
        app(BscReceiverManager::class)->add('Checkout guard wallet', $checkoutAddress, $owner);
        $checkoutClaim = UsdtReceiverAddressClaim::query()->forAddress(56, $checkoutAddress)->sole();

        $depositAddress = $this->randomAddress();
        $customer = Customer::factory()->create();
        $depositReceiver = $registry->manualVerify(
            $registry->register($customer, $depositAddress, $owner)->receiver,
            true,
            $owner,
        );
        $depositClaim = UsdtReceiverAddressClaim::query()->forAddress(56, $depositAddress)->sole();

        $this->assertQueryRejected(fn () => DB::table('bsc_payment_receivers')->insert([
            'address_claim_id' => (int) $depositClaim->getKey(),
            'label' => 'Cross channel checkout',
            'address' => $depositAddress,
            'address_normalized' => $depositAddress,
            'chain_id' => 56,
            'token_contract' => $depositReceiver->token_contract,
            'token_decimals' => 18,
            'is_active' => false,
            'verification_status' => BscReceiverVerificationStatus::PendingVerification->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->assertQueryRejected(fn () => DB::table('customer_usdt_deposit_receivers')->insert([
            'customer_id' => (int) $customer->getKey(),
            'address_claim_id' => (int) $checkoutClaim->getKey(),
            'chain_id' => 56,
            'address' => $checkoutAddress,
            'address_normalized' => $checkoutAddress,
            'token_contract' => $depositReceiver->token_contract,
            'token_decimals' => 18,
            'custody_namespace' => 'operator',
            'source' => 'owner',
            'verification_status' => BscReceiverVerificationStatus::PendingVerification->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->assertQueryRejected(fn () => DB::table('usdt_receiver_address_claims')
            ->where('id', $depositClaim->getKey())
            ->update(['channel' => UsdtReceiverChannel::Checkout->value]));

        $registry->retire($depositReceiver, $owner, 'guard test');

        $this->assertQueryRejected(fn () => DB::table('customer_usdt_deposit_receivers')
            ->where('id', $depositReceiver->getKey())
            ->update(['retired_at' => null, 'retirement_reason' => null]));

        $this->assertQueryRejected(fn () => DB::table('customer_usdt_deposit_receivers')
            ->where('id', $depositReceiver->getKey())
            ->update(['customer_id' => (int) Customer::factory()->create()->getKey()]));

        $this->assertSame(0, BscPaymentReceiver::query()->where('address_normalized', $depositAddress)->count());
        $this->assertSame(
            UsdtReceiverChannel::CustomerDeposit,
            UsdtReceiverAddressClaim::query()->forAddress(56, $depositAddress)->sole()->channel,
        );
    }

    private function bindDepositReceiver(int $customerId, int $ownerId, string $address): string
    {
        try {
            app(UsdtDepositReceiverRegistry::class)->register(
                Customer::query()->findOrFail($customerId),
                $address,
                User::query()->findOrFail($ownerId),
            );

            return 'registered';
        } catch (UsdtReceiverAddressConflict|UsdtDepositReceiverAlreadyActive) {
            return 'conflict';
        }
    }

    private function assertQueryRejected(callable $callback): void
    {
        try {
            $callback();
            $this->fail('The PostgreSQL guard must reject this write.');
        } catch (QueryException $exception) {
            $this->assertContains((string) ($exception->errorInfo[0] ?? ''), ['23000', '23505', 'P0001']);
        }
    }

    private function randomAddress(): string
    {
        return '0x'.substr(hash('sha256', bin2hex(random_bytes(16))), 0, 40);
    }

    private function concurrencyLaneAvailable(): bool
    {
        return DB::getDriverName() === 'pgsql' && (string) env('RUN_CONCURRENCY_TESTS', '') === '1';
    }

    private function requirePostgresConcurrency(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('USDT receiver ownership races require the pgsql test connection.');
        }

        if ((string) env('RUN_CONCURRENCY_TESTS', '') !== '1') {
            $this->markTestSkipped('Set RUN_CONCURRENCY_TESTS=1 to run process-level PostgreSQL races.');
        }

        $this->assertSafeConcurrencyDatabase();
    }

    private function assertSafeConcurrencyDatabase(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->fail('The pcntl extension is required for USDT receiver ownership races.');
        }

        if (! extension_loaded('pdo_pgsql')) {
            $this->fail('The pdo_pgsql extension is required for USDT receiver ownership races.');
        }

        if (app()->environment() !== 'testing') {
            $this->fail('Refusing USDT receiver ownership races outside APP_ENV=testing.');
        }

        if (config('database.default') !== 'pgsql' || config('database.connections.pgsql.driver') !== 'pgsql') {
            $this->fail('USDT receiver ownership races require DB_CONNECTION=pgsql.');
        }

        if ((string) config('database.connections.pgsql.url') !== '' || (string) env('DB_URL', '') !== '') {
            $this->fail('Refusing USDT receiver ownership races while DB_URL is configured.');
        }

        $database = (string) config('database.connections.pgsql.database');
        if (preg_match('/(^|[_-])(prod|production|live|main)([_-]|$)/i', $database) === 1
            || ! preg_match('/(^|[_-])(test|testing)([_-]|$)/i', $database)) {
            $this->fail("USDT receiver ownership races require an isolated test database; got [{$database}].");
        }

        $actualDatabase = (string) DB::selectOne('select current_database() as database')->database;
        if ($actualDatabase !== $database) {
            $this->fail("Connected database [{$actualDatabase}] does not match configured database [{$database}].");
        }
    }
}
