<?php

namespace Tests\Feature;

use App\Enums\PaymentEventType;
use App\Models\Payment;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Services\KlikQris\KlikQrisProviderAdapter;
use App\Services\KlikQris\KlikQrisTransaction;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\TestCase;

class KlikQrisNativeQrisPostgresConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.klikqris', KlikQrisSandboxScenarios::readyConfig());
        $this->travelTo('2026-07-28 07:00:00');
    }

    /** @group postgres */
    public function test_postgres_process_concurrency_observes_one_provider_create_effect_with_committed_fixtures(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL concurrency check runs only with the pgsql test connection.');
        }

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('PostgreSQL concurrency check requires pcntl.');
        }

        $this->guardIsolatedPostgresTestingDatabase();
        $this->migrateCommittedPostgresFixtureSchema();

        config()->set('cache.default', 'database');
        Cache::purge('database');

        Schema::dropIfExists('klikqris_test_provider_effects');
        Schema::create('klikqris_test_provider_effects', function (Blueprint $table): void {
            $table->id();
            $table->uuid('payment_id')->unique();
            $table->string('merchant_ref');
            $table->timestamps();
        });

        $payment = KlikQrisSandboxScenarios::klikQrisPayment(['amount' => 1000]);
        app()->bind(KlikQrisProviderAdapter::class, fn () => new DatabaseBackedKlikQrisProviderAdapter);

        DB::disconnect();
        $results = $this->runConcurrently([
            fn () => app(KlikQrisNativeQrisService::class)->ensureCreated(Payment::findOrFail($payment->id))->orderId(),
            fn () => app(KlikQrisNativeQrisService::class)->ensureCreated(Payment::findOrFail($payment->id))->orderId(),
        ]);
        DB::reconnect();

        $this->assertSame(2, count(array_filter($results, fn (array $result): bool => $result['ok'] && $result['exit_code'] === 0)), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, DB::table('klikqris_test_provider_effects')->where('payment_id', $payment->id)->count());
        $this->assertSame(1, Payment::findOrFail($payment->id)->paymentEvents()->where('event_type', PaymentEventType::ProviderCreate->value)->count());
    }

    private function guardIsolatedPostgresTestingDatabase(): void
    {
        if (! app()->environment('testing')) {
            $this->fail('Refusing to run PostgreSQL concurrency migration outside APP_ENV=testing.');
        }

        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        $host = (string) config("database.connections.{$connection}.host");

        if (! preg_match('/(^|[_-])(test|testing)([_-]|$)/i', $database)) {
            $this->markTestSkipped("PostgreSQL concurrency check requires an isolated test database name; configured database [{$database}] on host [{$host}] was not accepted.");
        }
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

        $releaseFile = $directory.'/release';
        $children = [];

        foreach ($callbacks as $index => $callback) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Unable to fork concurrency test process.');
            }

            if ($pid === 0) {
                $this->runChildProcess($callback, $releaseFile, $directory.'/result-'.$index.'.json');
            }

            $children[$index] = $pid;
        }

        touch($releaseFile);

        $results = [];
        foreach ($children as $index => $pid) {
            $status = 0;
            pcntl_waitpid($pid, $status);
            $path = $directory.'/result-'.$index.'.json';
            $result = is_file($path) ? json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [
                'ok' => false,
                'class' => RuntimeException::class,
                'message' => 'Child process did not write a result file.',
            ];
            $result['exit_code'] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 255;
            $results[] = $result;
        }

        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);

        return $results;
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

final class DatabaseBackedKlikQrisProviderAdapter implements KlikQrisProviderAdapter
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
        return KlikQrisTransaction::fromStatusPayload(KlikQrisSandboxScenarios::createPayload($payment, [
            'expired_at' => now()->addMinutes(10)->toAtomString(),
        ]));
    }
}
