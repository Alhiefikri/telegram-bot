<?php

namespace Tests\Feature;

use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\KlikQris\KlikQrisCallbackProcessor;
use App\Services\KlikQris\KlikQrisProviderAdapter;
use App\Services\KlikQris\KlikQrisTransaction;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\Support\PostgresTestDatabase;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
class KlikQrisWebhookPostgresConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        KlikQrisSandboxScenarios::configureReadySandbox();
        $this->travelTo('2026-07-28 07:05:00');
    }

    /** @group postgres */
    public function test_concurrent_success_callbacks_claim_one_event_and_fulfill_once(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL concurrency check runs only with the pgsql test connection.');
        }

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('PostgreSQL concurrency check requires pcntl fork support.');
        }

        $this->guardIsolatedPostgresTestingDatabase();
        PostgresTestDatabase::migrateFresh();

        $payment = KlikQrisSandboxScenarios::klikQrisPayment([
            'amount' => 10000,
            'provider_total_payment' => 10016,
            'provider_fee' => 16,
            'provider_expires_at' => now()->addMinutes(10),
            'expired_at' => now()->addMinutes(10),
        ]);
        $payment->update(['provider_payload' => KlikQrisSandboxScenarios::createPayload($payment)]);
        $payment->order->update(['expires_at' => now()->addMinutes(10)]);

        app()->bind(KlikQrisProviderAdapter::class, fn () => new SlowSuccessfulStatusAdapter);
        $payload = [
            'order_id' => $payment->merchant_ref,
            'amount' => (string) $payment->amount,
            'total_amount' => (string) $payment->provider_total_payment,
            'status' => 'PAID',
            'signature' => 'ignored-signature',
        ];

        DB::disconnect();
        $results = $this->runConcurrently([
            fn () => app(KlikQrisCallbackProcessor::class)->processCallback($payload)->id,
            fn () => app(KlikQrisCallbackProcessor::class)->processCallback($payload)->id,
        ]);
        DB::reconnect();

        $this->assertSame(2, count(array_filter($results, fn (array $result): bool => $result['ok'] && $result['exit_code'] === 0)), json_encode($results, JSON_THROW_ON_ERROR));
        $event = Payment::findOrFail($payment->id)->paymentEvents()->where('event_type', PaymentEventType::Callback->value)->sole();
        $this->assertNotNull($event->processed_at);
        $this->assertSame(2, $event->received_count);
        $this->assertSame(PaymentStatus::Paid, Payment::findOrFail($payment->id)->status);
    }

    private function guardIsolatedPostgresTestingDatabase(): void
    {
        if (app()->environment() !== 'testing') {
            $this->fail('Refusing to run PostgreSQL concurrency migration outside APP_ENV=testing.');
        }

        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        $configuredUrl = (string) config("database.connections.{$connection}.url");
        if ($configuredUrl !== '' || (string) env('DB_URL', '') !== '') {
            $this->fail('Refusing destructive PostgreSQL concurrency setup while DB_URL is configured.');
        }

        if (preg_match('/(^|[_-])(test|testing)([_-]|$)/i', $database) !== 1) {
            $this->fail("PostgreSQL concurrency check requires an isolated test database name; got [{$database}].");
        }

        $actualDatabase = (string) DB::selectOne('select current_database() as database')->database;
        if ($actualDatabase !== $database || preg_match('/(^|[_-])(test|testing)([_-]|$)/i', $actualDatabase) !== 1) {
            $this->fail("Refusing destructive PostgreSQL setup for connected database [{$actualDatabase}].");
        }
    }

    /**
     * @param  list<callable(): mixed>  $callbacks
     * @return list<array{ok: bool, value?: mixed, class?: string, message?: string, exit_code: int}>
     */
    private function runConcurrently(array $callbacks): array
    {
        $directory = storage_path('framework/testing/klikqris-webhook-concurrency-'.bin2hex(random_bytes(8)));
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            $this->fail('Unable to create concurrency test workspace.');
        }

        $children = [];
        $releaseFile = $directory.'/release';

        try {
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
            $statuses = [];
            $deadline = microtime(true) + 20;
            while (count($statuses) < count($children) && microtime(true) < $deadline) {
                foreach (array_diff_key($children, $statuses) as $index => $pid) {
                    $status = 0;
                    $waited = pcntl_waitpid($pid, $status, WNOHANG);
                    if ($waited === $pid || $waited === -1) {
                        $statuses[$index] = $status;
                    }
                }
                usleep(10_000);
            }

            foreach (array_diff_key($children, $statuses) as $pid) {
                posix_kill($pid, SIGKILL);
            }

            $results = [];
            foreach ($children as $index => $pid) {
                $path = $directory.'/result-'.$index.'.json';
                $result = is_file($path) ? json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [
                    'ok' => false,
                    'class' => RuntimeException::class,
                    'message' => 'Child process did not write a result file.',
                ];
                $status = $statuses[$index] ?? 255;
                $result['exit_code'] = is_int($status) && pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 255;
                $results[] = $result;
            }

            return $results;
        } finally {
            foreach (glob($directory.'/*') ?: [] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    private function runChildProcess(callable $callback, string $releaseFile, string $resultFile): never
    {
        DB::disconnect();
        $deadline = microtime(true) + 10;
        while (! is_file($releaseFile)) {
            if (microtime(true) > $deadline) {
                file_put_contents($resultFile, json_encode(['ok' => false, 'class' => RuntimeException::class, 'message' => 'Timed out waiting for release.'], JSON_THROW_ON_ERROR));
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

final class SlowSuccessfulStatusAdapter implements KlikQrisProviderAdapter
{
    public function createTransaction(Payment $payment): KlikQrisTransaction
    {
        return KlikQrisTransaction::fromCreatePayload(KlikQrisSandboxScenarios::createPayload($payment));
    }

    public function transactionStatus(Payment $payment): KlikQrisTransaction
    {
        usleep(150_000);

        return KlikQrisTransaction::fromStatusPayload(KlikQrisSandboxScenarios::statusPayload($payment));
    }
}
