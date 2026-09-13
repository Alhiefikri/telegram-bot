<?php

namespace Tests\Feature;

use App\Enums\PaymentEventType;
use App\Enums\StoreBalanceFundingIntentStatus;
use App\Enums\StoreBalanceTopUpStatus;
use App\Enums\TelegramNavigationMediaKind;
use App\Enums\TelegramNavigationScreen;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\StoreBalanceEntry;
use App\Models\StoreBalanceFundingIntent;
use App\Models\StoreBalanceTopUp;
use App\Models\StoreBalanceTopUpEvent;
use App\Models\TelegramNavigationRoot;
use App\Models\TelegramOutboundEffect;
use App\Services\KlikQris\KlikQrisCallbackProcessor;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Services\KlikQris\KlikQrisProviderAdapter;
use App\Services\KlikQris\KlikQrisTopUpProviderAdapter;
use App\Services\KlikQris\KlikQrisTransaction;
use App\Services\StoreBalance\StoreBalanceTopUpService;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
class KlikQrisNativeQrisPostgresConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        KlikQrisSandboxScenarios::configureReadySandbox();
        $this->travelTo('2026-07-28 07:00:00');
    }

    /** @group postgres */
    public function test_postgres_process_concurrency_observes_one_provider_create_effect_with_committed_fixtures(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL concurrency check runs only with the pgsql test connection.');
        }

        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_signal')) {
            $this->markTestSkipped('PostgreSQL concurrency check requires pcntl fork and signal support.');
        }

        $this->guardIsolatedPostgresTestingDatabase();
        $this->migrateCommittedPostgresFixtureSchema();

        config()->set('cache.default', 'database');
        Cache::purge('database');

        $this->prepareConcurrencyTables();

        $payment = KlikQrisSandboxScenarios::klikQrisPayment(['amount' => 1000]);

        DB::disconnect();
        $results = $this->runConcurrently([
            fn () => app(KlikQrisNativeQrisService::class)->lifecycle(Payment::findOrFail($payment->id))->payable()?->qrisUrl(),
            fn () => app(KlikQrisNativeQrisService::class)->lifecycle(Payment::findOrFail($payment->id))->payable()?->qrisUrl(),
        ]);
        DB::reconnect();

        $this->assertSame(2, count(array_filter($results, fn (array $result): bool => $result['ok'] && $result['exit_code'] === 0)), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, DB::table('klikqris_test_provider_effects')->where('payment_id', $payment->id)->count());
        $this->assertSame(1, Payment::findOrFail($payment->id)->paymentEvents()->where('event_type', PaymentEventType::ProviderCreate->value)->count());
    }

    /** @group postgres */
    public function test_postgres_concurrent_recovery_keeps_one_payment_state_without_second_create(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL concurrency check runs only with the pgsql test connection.');
        }

        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_signal')) {
            $this->markTestSkipped('PostgreSQL concurrency check requires pcntl fork and signal support.');
        }

        $this->guardIsolatedPostgresTestingDatabase();
        $this->migrateCommittedPostgresFixtureSchema();

        config()->set('cache.default', 'database');
        Cache::purge('database');

        $this->prepareConcurrencyTables();

        $payment = KlikQrisSandboxScenarios::klikQrisPayment([
            'amount' => 1000,
            'provider_create_state' => 'outcome_unknown',
        ]);

        DB::disconnect();
        $results = $this->runConcurrently([
            fn () => app(KlikQrisNativeQrisService::class)->lifecycle(Payment::findOrFail($payment->id))->outcome()->value,
            fn () => app(KlikQrisNativeQrisService::class)->lifecycle(Payment::findOrFail($payment->id))->outcome()->value,
        ]);
        DB::reconnect();

        $this->assertSame(2, count(array_filter($results, fn (array $result): bool => $result['ok'] && $result['exit_code'] === 0)), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(0, DB::table('klikqris_test_provider_effects')->where('payment_id', $payment->id)->count());

        $recovered = Payment::findOrFail($payment->id);
        $this->assertSame(1, DB::table('klikqris_test_status_lookups')->where('payment_id', $payment->id)->count());
        $this->assertSame('created', $recovered->provider_create_state);
        $this->assertSame(1016, $recovered->provider_total_payment);
        $this->assertNotNull($recovered->provider_expires_at);
        $this->assertTrue($recovered->isPayableNativeKlikQris());
        $this->assertSame(1, $recovered->paymentEvents()->where('event_type', PaymentEventType::ProviderCreate->value)->count());
    }

    /** @group postgres */
    public function test_postgres_reconciliation_and_customer_recovery_share_one_payment_lock(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL concurrency check runs only with the pgsql test connection.');
        }

        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_signal')) {
            $this->markTestSkipped('PostgreSQL concurrency check requires pcntl fork and signal support.');
        }

        $this->guardIsolatedPostgresTestingDatabase();
        $this->migrateCommittedPostgresFixtureSchema();

        config()->set('cache.default', 'database');
        Cache::purge('database');
        $this->prepareConcurrencyTables();

        $payment = KlikQrisSandboxScenarios::klikQrisPayment([
            'amount' => 1000,
            'provider_create_state' => 'outcome_unknown',
        ]);

        DB::disconnect();
        $results = $this->runConcurrently([
            fn () => app(KlikQrisNativeQrisService::class)->reconcile(
                Payment::findOrFail($payment->id),
                fn (Payment $payment, KlikQrisTransaction $transaction): null => null,
            )?->outcome()->value,
            fn () => app(KlikQrisNativeQrisService::class)->lifecycle(Payment::findOrFail($payment->id))->outcome()->value,
        ]);
        DB::reconnect();

        $this->assertSame(2, count(array_filter($results, fn (array $result): bool => $result['ok'] && $result['exit_code'] === 0)), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, DB::table('klikqris_test_status_lookups')->where('payment_id', $payment->id)->count());
        $this->assertSame('created', Payment::findOrFail($payment->id)->provider_create_state);
    }

    /** @group postgres */
    public function test_postgres_parallel_top_up_confirmation_work_reuses_one_provider_target_and_instruction_effect(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL concurrency check runs only with the pgsql test connection.');
        }

        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_signal')) {
            $this->markTestSkipped('PostgreSQL concurrency check requires pcntl fork and signal support.');
        }

        $this->guardIsolatedPostgresTestingDatabase();
        $this->migrateCommittedPostgresFixtureSchema();
        config()->set('cache.default', 'database');
        Cache::purge('database');
        $this->prepareConcurrencyTables();
        KlikQrisSandboxScenarios::selectKlikQrisProvider();

        $customer = Customer::factory()->create(['telegram_id' => 46290, 'locale' => 'en']);
        $root = TelegramNavigationRoot::query()->create([
            'customer_id' => $customer->id,
            'chat_id' => (string) $customer->telegram_id,
            'message_id' => 7790,
            'media_kind' => TelegramNavigationMediaKind::Photo,
            'screen' => TelegramNavigationScreen::AddBalance,
            'locale' => $customer->locale,
            'generation' => 1,
        ]);
        $intent = StoreBalanceFundingIntent::query()->create([
            'customer_id' => $customer->id,
            'telegram_navigation_root_id' => $root->id,
            'chat_id' => (string) $customer->telegram_id,
            'canonical_message_id' => $root->message_id,
            'policy_version' => 1,
            'amount' => 25_000,
            'nonce' => bin2hex(random_bytes(16)),
            'status' => StoreBalanceFundingIntentStatus::Confirmed,
            'expires_at' => now()->addMinutes(10),
            'claimed_at' => now(),
        ]);

        DB::disconnect();
        $results = $this->runConcurrently([
            function () use ($intent): string {
                $topUp = app(StoreBalanceTopUpService::class)->createOrReuseConfirmedIntent(
                    StoreBalanceFundingIntent::query()->findOrFail($intent->id),
                );
                $result = app(KlikQrisNativeQrisService::class)->lifecycleForTopUp($topUp);
                if (! $result->isPayable()) {
                    throw new RuntimeException('Top-up worker did not produce a payable result.');
                }
                $effect = app(TelegramOutboundEffectService::class)->recordBalanceTopUpInstructions(
                    $topUp->fresh(['customer']),
                    $topUp->customer,
                );

                return $topUp->getKey().':'.($effect?->getKey() ?? '');
            },
            function () use ($intent): string {
                $topUp = app(StoreBalanceTopUpService::class)->createOrReuseConfirmedIntent(
                    StoreBalanceFundingIntent::query()->findOrFail($intent->id),
                );
                $result = app(KlikQrisNativeQrisService::class)->lifecycleForTopUp($topUp);
                if (! $result->isPayable()) {
                    throw new RuntimeException('Top-up worker did not produce a payable result.');
                }
                $effect = app(TelegramOutboundEffectService::class)->recordBalanceTopUpInstructions(
                    $topUp->fresh(['customer']),
                    $topUp->customer,
                );

                return $topUp->getKey().':'.($effect?->getKey() ?? '');
            },
        ]);
        DB::reconnect();

        $this->assertSame(2, count(array_filter($results, fn (array $result): bool => $result['ok'] && $result['exit_code'] === 0)), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame($results[0]['value'] ?? null, $results[1]['value'] ?? null);
        $this->assertSame(1, StoreBalanceTopUp::query()->count());
        $this->assertSame(1, DB::table('klikqris_test_topup_provider_effects')->count());
        $this->assertSame(1, TelegramOutboundEffect::query()->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP)->count());
    }

    /** @group postgres */
    public function test_postgres_two_top_up_reconcilers_credit_once_and_query_provider_once(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL concurrency check runs only with the pgsql test connection.');
        }

        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_signal')) {
            $this->markTestSkipped('PostgreSQL concurrency check requires pcntl fork and signal support.');
        }

        $this->guardIsolatedPostgresTestingDatabase();
        $this->migrateCommittedPostgresFixtureSchema();
        config()->set('cache.default', 'database');
        Cache::purge('database');
        $this->prepareConcurrencyTables();
        $topUp = $this->createPayableTopUpForConcurrency();

        DB::disconnect();
        $results = $this->runConcurrently([
            fn () => app(StoreBalanceTopUpService::class)->reconcile(StoreBalanceTopUp::findOrFail($topUp->id))->outcome()->value,
            fn () => app(StoreBalanceTopUpService::class)->reconcile(StoreBalanceTopUp::findOrFail($topUp->id))->outcome()->value,
        ]);
        DB::reconnect();

        $this->assertSame(2, count(array_filter($results, fn (array $result): bool => $result['ok'] && $result['exit_code'] === 0)), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(StoreBalanceTopUpStatus::Paid, StoreBalanceTopUp::findOrFail($topUp->id)->status);
        $this->assertSame(1, StoreBalanceEntry::query()->where('source_id', $topUp->id)->count());
        $this->assertSame(1, StoreBalanceTopUpEvent::query()->where('event_type', 'reconciliation')->count());
        $this->assertSame(1, DB::table('klikqris_test_topup_status_lookups')->where('top_up_id', $topUp->id)->count());
    }

    /** @group postgres */
    public function test_postgres_top_up_reconciliation_and_callback_race_credit_once(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL concurrency check runs only with the pgsql test connection.');
        }

        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_signal')) {
            $this->markTestSkipped('PostgreSQL concurrency check requires pcntl fork and signal support.');
        }

        $this->guardIsolatedPostgresTestingDatabase();
        $this->migrateCommittedPostgresFixtureSchema();
        config()->set('cache.default', 'database');
        Cache::purge('database');
        $this->prepareConcurrencyTables();
        $topUp = $this->createPayableTopUpForConcurrency();
        $callback = [
            'order_id' => $topUp->merchant_ref,
            'amount' => (int) $topUp->amount,
            'total_amount' => (int) $topUp->provider_total_payment,
            'status' => 'SUCCESS',
            'signature' => $topUp->providerSignature(),
        ];

        DB::disconnect();
        $results = $this->runConcurrently([
            fn () => app(StoreBalanceTopUpService::class)->reconcile(StoreBalanceTopUp::findOrFail($topUp->id))->outcome()->value,
            fn () => app(KlikQrisCallbackProcessor::class)->processCallback($callback)->getKey(),
        ]);
        DB::reconnect();

        $this->assertSame(2, count(array_filter($results, fn (array $result): bool => $result['ok'] && $result['exit_code'] === 0)), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(StoreBalanceTopUpStatus::Paid, StoreBalanceTopUp::findOrFail($topUp->id)->status);
        $this->assertSame(1, StoreBalanceEntry::query()->where('source_id', $topUp->id)->count());
        // The callback and the scheduled reconciler share one top-up lock, so
        // whichever acquires it first settles the single credit and the other
        // observes a terminal top-up. A callback that wins leaves the
        // reconciliation path on its terminal short-circuit, which is why only
        // the single credit and the single provider verification are invariant
        // here, not which of the two paths recorded the settlement.
        $this->assertLessThanOrEqual(1, StoreBalanceTopUpEvent::query()->where('event_type', 'reconciliation')->count());
        $this->assertLessThanOrEqual(1, StoreBalanceTopUpEvent::query()->where('event_type', 'callback')->count());
        $this->assertSame(1, DB::table('klikqris_test_topup_status_lookups')->where('top_up_id', $topUp->id)->count());
    }

    /** @group postgres */
    public function test_postgres_top_up_reconciliation_and_expiry_race_never_credit_expired_top_up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL concurrency check runs only with the pgsql test connection.');
        }

        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_signal')) {
            $this->markTestSkipped('PostgreSQL concurrency check requires pcntl fork and signal support.');
        }

        $this->guardIsolatedPostgresTestingDatabase();
        $this->migrateCommittedPostgresFixtureSchema();
        config()->set('cache.default', 'database');
        Cache::purge('database');
        $this->prepareConcurrencyTables();
        $topUp = $this->createPayableTopUpForConcurrency();
        $expiredAt = now()->subSecond();
        $topUp->forceFill([
            'status' => StoreBalanceTopUpStatus::Payable,
            'provider_expires_at' => $expiredAt,
            'expires_at' => $expiredAt,
        ])->save();

        DB::disconnect();
        $results = $this->runConcurrently([
            fn () => app(StoreBalanceTopUpService::class)->reconcile(StoreBalanceTopUp::findOrFail($topUp->id))->outcome()->value,
            fn () => app(StoreBalanceTopUpService::class)->expireIfDue(StoreBalanceTopUp::findOrFail($topUp->id)),
        ]);
        DB::reconnect();

        $this->assertSame(2, count(array_filter($results, fn (array $result): bool => $result['ok'] && $result['exit_code'] === 0)), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(StoreBalanceTopUpStatus::Expired, StoreBalanceTopUp::findOrFail($topUp->id)->status);
        $this->assertSame(0, StoreBalanceEntry::query()->where('source_id', $topUp->id)->count());
        $this->assertSame(0, StoreBalanceTopUpEvent::query()->whereNotNull('processed_at')->count());
    }

    private function prepareConcurrencyTables(): void
    {
        Schema::dropIfExists('klikqris_test_provider_effects');
        Schema::create('klikqris_test_provider_effects', function (Blueprint $table): void {
            $table->id();
            $table->uuid('payment_id')->unique();
            $table->string('merchant_ref');
            $table->timestamps();
        });

        Schema::dropIfExists('klikqris_test_status_lookups');
        Schema::create('klikqris_test_status_lookups', function (Blueprint $table): void {
            $table->id();
            $table->uuid('payment_id');
            $table->string('merchant_ref');
            $table->timestamps();
        });
        Schema::dropIfExists('klikqris_test_topup_provider_effects');
        Schema::create('klikqris_test_topup_provider_effects', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('top_up_id');
            $table->string('merchant_ref');
            $table->timestamps();
        });
        Schema::dropIfExists('klikqris_test_topup_status_lookups');
        Schema::create('klikqris_test_topup_status_lookups', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('top_up_id');
            $table->string('merchant_ref');
            $table->timestamps();
        });

        app()->bind(KlikQrisProviderAdapter::class, fn () => new DatabaseBackedKlikQrisProviderAdapter);
    }

    private function createPayableTopUpForConcurrency(): StoreBalanceTopUp
    {
        KlikQrisSandboxScenarios::selectKlikQrisProvider();
        $customer = Customer::factory()->create(['telegram_id' => 46291, 'locale' => 'en']);
        $root = TelegramNavigationRoot::query()->create([
            'customer_id' => $customer->id,
            'chat_id' => (string) $customer->telegram_id,
            'message_id' => 7791,
            'media_kind' => TelegramNavigationMediaKind::Photo,
            'screen' => TelegramNavigationScreen::AddBalance,
            'locale' => $customer->locale,
            'generation' => 1,
        ]);
        $intent = StoreBalanceFundingIntent::query()->create([
            'customer_id' => $customer->id,
            'telegram_navigation_root_id' => $root->id,
            'chat_id' => (string) $customer->telegram_id,
            'canonical_message_id' => $root->message_id,
            'policy_version' => 1,
            'amount' => 25_000,
            'nonce' => bin2hex(random_bytes(16)),
            'status' => StoreBalanceFundingIntentStatus::Confirmed,
            'expires_at' => now()->addMinutes(10),
            'claimed_at' => now(),
        ]);

        $topUp = app(StoreBalanceTopUpService::class)->createOrReuseConfirmedIntent($intent);
        $result = app(KlikQrisNativeQrisService::class)->lifecycleForTopUp($topUp);
        if (! $result->isPayable()) {
            throw new RuntimeException('Top-up fixture did not produce a payable result.');
        }

        return $topUp->fresh();
    }

    private function guardIsolatedPostgresTestingDatabase(): void
    {
        if (app()->environment() !== 'testing') {
            $this->fail('Refusing to run PostgreSQL concurrency migration outside APP_ENV=testing.');
        }

        $connection = config('database.default');
        $driver = (string) config("database.connections.{$connection}.driver");
        if ($driver !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL concurrency check runs only with the pgsql test connection.');
        }

        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_signal')) {
            $this->markTestSkipped('PostgreSQL concurrency check requires pcntl fork and signal support.');
        }

        $configuredUrl = (string) config("database.connections.{$connection}.url");
        $environmentUrl = (string) env('DB_URL', '');
        if ($configuredUrl !== '' || $environmentUrl !== '') {
            $this->fail('Refusing destructive PostgreSQL concurrency setup while DB_URL is configured. Use phpunit.postgres.xml with an isolated explicit DB_DATABASE instead.');
        }

        $database = (string) config("database.connections.{$connection}.database");
        $host = (string) config("database.connections.{$connection}.host");

        $rejectedDatabases = ['postgres', 'template0', 'template1', 'production', 'prod', 'telegram_bot', 'telegram-bot', 'telegram_bot_prod', 'telegram-bot-prod'];
        if (in_array(strtolower($database), $rejectedDatabases, true) || preg_match('/(^|[_-])(prod|production|live|main)([_-]|$)/i', $database)) {
            $this->fail("Refusing destructive PostgreSQL concurrency setup against prod-like database [{$database}] on host [{$host}].");
        }

        if (! $this->isStrictTestingDatabaseName($database)) {
            $this->fail("PostgreSQL concurrency check requires an isolated test database name; configured database [{$database}] on host [{$host}] was not accepted.");
        }

        $actualDatabase = (string) DB::selectOne('select current_database() as database')->database;
        if ($actualDatabase !== $database) {
            $this->fail("Refusing destructive PostgreSQL concurrency setup because connected database [{$actualDatabase}] does not match configured database [{$database}].");
        }

        if (! $this->isStrictTestingDatabaseName($actualDatabase)) {
            $this->fail("Refusing destructive PostgreSQL concurrency setup because connected database [{$actualDatabase}] is not an isolated testing database.");
        }
    }

    private function isStrictTestingDatabaseName(string $database): bool
    {
        return preg_match('/(^|[_-])(test|testing)([_-]|$)/i', $database) === 1;
    }

    private function migrateCommittedPostgresFixtureSchema(): void
    {
        Artisan::call('migrate:fresh', [
            '--force' => true,
            '--drop-views' => true,
            '--drop-types' => true,
        ]);
    }

    /**
     * @param  list<callable(): mixed>  $callbacks
     * @return list<array{ok: bool, value?: mixed, class?: string, message?: string, exit_code: int}>
     */
    private function runConcurrently(array $callbacks): array
    {
        $directory = storage_path('framework/testing/klikqris-native-concurrency-'.bin2hex(random_bytes(8)));
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            $this->fail('Unable to create concurrency test workspace.');
        }

        $children = [];

        try {
            $releaseFile = $directory.'/release';

            foreach ($callbacks as $index => $callback) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $this->terminateAndReapChildren($children);
                    $this->fail('Unable to fork concurrency test process.');
                }

                if ($pid === 0) {
                    $this->runChildProcess($callback, $releaseFile, $directory.'/result-'.$index.'.json');
                }

                $children[$index] = $pid;
            }

            touch($releaseFile);

            $statuses = $this->waitForChildren($children, 20.0);
            $unfinished = array_diff_key($children, $statuses);
            if ($unfinished !== []) {
                $this->terminateAndReapChildren($unfinished);

                $this->fail('PostgreSQL concurrency workers timed out and were terminated: '.implode(', ', $unfinished));
            }

            $results = [];
            foreach ($children as $index => $pid) {
                $path = $directory.'/result-'.$index.'.json';
                $result = is_file($path) ? json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [
                    'ok' => false,
                    'class' => RuntimeException::class,
                    'message' => 'Child process did not write a result file.',
                ];
                $status = $statuses[$index];
                $result['exit_code'] = is_int($status) && pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 255;
                $results[] = $result;
            }

            return $results;
        } finally {
            $this->cleanupConcurrencyDirectory($directory);
        }
    }

    /**
     * @param  array<int, int>  $children
     * @return array<int, int|null>
     */
    private function waitForChildren(array $children, float $timeoutSeconds): array
    {
        $statuses = [];
        $deadline = microtime(true) + $timeoutSeconds;

        while (count($statuses) < count($children) && microtime(true) < $deadline) {
            $statuses += $this->reapAvailableChildren(array_diff_key($children, $statuses));

            if (count($statuses) < count($children)) {
                usleep(10_000);
            }
        }

        return $statuses;
    }

    /**
     * @param  array<int, int>  $children
     * @return array<int, int|null>
     */
    private function reapAvailableChildren(array $children): array
    {
        $statuses = [];
        foreach ($children as $index => $pid) {
            $status = 0;
            $waited = pcntl_waitpid($pid, $status, WNOHANG);
            if ($waited === $pid || $waited === -1) {
                $statuses[$index] = $waited === $pid ? $status : null;
            }
        }

        return $statuses;
    }

    /**
     * @param  array<int, int>  $children
     */
    private function terminateAndReapChildren(array $children): void
    {
        if ($children === []) {
            return;
        }

        $this->signalChildren($children, SIGTERM);
        $statuses = $this->waitForChildren($children, 2.0);
        $unfinished = array_diff_key($children, $statuses);

        if ($unfinished === []) {
            return;
        }

        $this->signalChildren($unfinished, SIGKILL);
        $this->waitForChildren($unfinished, 2.0);
    }

    /**
     * @param  array<int, int>  $children
     */
    private function signalChildren(array $children, int $signal): void
    {
        foreach ($children as $pid) {
            if (function_exists('posix_kill')) {
                @posix_kill($pid, $signal);

                continue;
            }

            if (PHP_OS_FAMILY !== 'Windows') {
                exec('kill -'.escapeshellarg((string) (int) $signal).' '.escapeshellarg((string) (int) $pid));
            }
        }
    }

    private function cleanupConcurrencyDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (glob($directory.'/*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        rmdir($directory);
    }

    private function runChildProcess(callable $callback, string $releaseFile, string $resultFile): never
    {
        DB::disconnect();
        Cache::purge('database');

        $deadline = microtime(true) + 10;
        while (! is_file($releaseFile)) {
            if (microtime(true) > $deadline) {
                file_put_contents($resultFile, json_encode(['ok' => false, 'class' => RuntimeException::class, 'message' => 'Timed out waiting for concurrency barrier.'], JSON_THROW_ON_ERROR));
                exit(1);
            }
            usleep(10_000);
        }

        try {
            $value = $callback();
            file_put_contents($resultFile, json_encode(['ok' => true, 'value' => $value], JSON_THROW_ON_ERROR));
            exit(0);
        } catch (\Throwable $exception) {
            file_put_contents($resultFile, json_encode(['ok' => false, 'class' => $exception::class, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR));
            exit(1);
        }
    }
}

final class DatabaseBackedKlikQrisProviderAdapter implements KlikQrisProviderAdapter, KlikQrisTopUpProviderAdapter
{
    public function createTransaction(Payment $payment): KlikQrisTransaction
    {
        DB::table('klikqris_test_provider_effects')->insert([
            'payment_id' => $payment->id,
            'merchant_ref' => $payment->merchant_ref,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        usleep(150_000);

        return KlikQrisTransaction::fromCreatePayload(KlikQrisSandboxScenarios::createPayload($payment, [
            'expired_at' => now()->addMinutes(10)->toAtomString(),
        ]));
    }

    public function transactionStatus(Payment $payment): KlikQrisTransaction
    {
        DB::table('klikqris_test_status_lookups')->insert([
            'payment_id' => $payment->id,
            'merchant_ref' => $payment->merchant_ref,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        usleep(150_000);

        return KlikQrisTransaction::fromStatusPayload(KlikQrisSandboxScenarios::createPayload($payment, [
            'expired_at' => now()->addMinutes(10)->toAtomString(),
        ]));
    }

    public function createTopUpTransaction(StoreBalanceTopUp $topUp): KlikQrisTransaction
    {
        DB::table('klikqris_test_topup_provider_effects')->insert([
            'top_up_id' => $topUp->getKey(),
            'merchant_ref' => $topUp->merchant_ref,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        usleep(150_000);

        return KlikQrisTransaction::fromCreatePayload([
            'data' => [
                'order_id' => $topUp->merchant_ref,
                'amount' => (int) $topUp->amount,
                'total_amount' => (int) $topUp->amount + 16,
                'fee' => 16,
                'status' => 'PENDING',
                'qris_url' => 'https://klikqris.com/storage/sandbox/sandbox_sample.png',
                'signature' => 'top-up-concurrency-signature',
                'expired_at' => now()->addMinutes(10)->toAtomString(),
            ],
        ]);
    }

    public function topUpTransactionStatus(StoreBalanceTopUp $topUp): KlikQrisTransaction
    {
        DB::table('klikqris_test_topup_status_lookups')->insert([
            'top_up_id' => $topUp->getKey(),
            'merchant_ref' => $topUp->merchant_ref,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        usleep(150_000);

        return KlikQrisTransaction::fromStatusPayload([
            'data' => [
                'order_id' => $topUp->merchant_ref,
                'amount' => (int) $topUp->amount,
                'total_amount' => (int) $topUp->amount + 16,
                'status' => 'SUCCESS',
                'signature' => $topUp->providerSignature(),
                'expired_at' => $topUp->provider_expires_at?->toAtomString(),
            ],
        ]);
    }
}
