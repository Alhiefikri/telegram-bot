<?php

namespace Tests\Feature\Telegram;

use App\Enums\TelegramOutboundEffectState;
use App\Models\TelegramOutboundEffect;
use App\Services\Telegram\TelegramOutboundEffectLedgerProtocol;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

#[Group('concurrency')]
final class TelegramOutboundEffectPostgresConcurrencyTest extends TestCase
{
    /** @var list<string> */
    private array $createdEffectIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql' || (string) env('RUN_CONCURRENCY_TESTS', '') !== '1') {
            return;
        }

        $this->assertSafeConcurrencyDatabase();
        if (! Schema::hasTable('telegram_outbound_effects')) {
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        }
    }

    protected function tearDown(): void
    {
        if ($this->createdEffectIds !== [] && Schema::hasTable('telegram_outbound_effects')) {
            TelegramOutboundEffect::query()->whereKey($this->createdEffectIds)->delete();
        }

        parent::tearDown();
    }

    public function test_two_postgres_workers_cannot_claim_one_effect_simultaneously(): void
    {
        $this->requirePostgresConcurrency();

        $effect = $this->createTestEffect();
        $results = $this->runConcurrently([
            fn (string $_directory): bool => app(TelegramOutboundEffectLedgerProtocol::class)
                ->claim($effect->id) !== null,
            fn (string $_directory): bool => app(TelegramOutboundEffectLedgerProtocol::class)
                ->claim($effect->id) !== null,
        ]);

        $claims = $this->successfulValues($results);
        sort($claims);

        $this->assertSame([false, true], $claims, json_encode($results, JSON_THROW_ON_ERROR));

        $stored = $effect->fresh();
        $this->assertSame(TelegramOutboundEffectState::Sending, $stored->state);
        $this->assertSame(1, $stored->attempts);
        $this->assertNotNull($stored->claim_token);
        $this->assertNotNull($stored->lease_until);
    }

    public function test_expired_lease_recovery_cannot_be_overwritten_by_the_old_worker(): void
    {
        $this->requirePostgresConcurrency();

        $effect = $this->createTestEffect();
        $claimed = app(TelegramOutboundEffectLedgerProtocol::class)->claim($effect->id);
        $this->assertNotNull($claimed);
        $oldClaimToken = (string) $claimed->claim_token;

        $effect->fresh()->forceFill(['lease_until' => now()->subSecond()])->save();

        $results = $this->runConcurrently([
            function (string $directory): int {
                $recovered = app(TelegramOutboundEffectLedgerProtocol::class)
                    ->recoverExpiredLeases(now());
                $this->signalMarker($directory.'/lease-recovered');

                return $recovered;
            },
            function (string $directory) use ($effect, $oldClaimToken): bool {
                $this->waitForMarker($directory.'/lease-recovered');

                return app(TelegramOutboundEffectLedgerProtocol::class)
                    ->finalizeSent($effect->id, $oldClaimToken, '100001') !== null;
            },
        ]);

        $this->assertSame(1, $results[0]['value'] ?? null, json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(false, $results[1]['value'] ?? null, json_encode($results, JSON_THROW_ON_ERROR));

        $stored = $effect->fresh();
        $this->assertSame(TelegramOutboundEffectState::Unknown, $stored->state);
        $this->assertSame('lease_expired', $stored->last_error_code);
        $this->assertNull($stored->claim_token);
        $this->assertNull($stored->telegram_message_id);
    }

    public function test_an_old_claim_token_cannot_finalize_after_a_later_owner_claims_the_effect(): void
    {
        $this->requirePostgresConcurrency();

        $effect = $this->createTestEffect();
        $claimed = app(TelegramOutboundEffectLedgerProtocol::class)->claim($effect->id);
        $this->assertNotNull($claimed);
        $oldClaimToken = (string) $claimed->claim_token;

        $results = $this->runConcurrently([
            function (string $directory) use ($effect, $oldClaimToken): bool {
                $released = app(TelegramOutboundEffectLedgerProtocol::class)->finalizeFailed(
                    $effect->id,
                    $oldClaimToken,
                    new RuntimeException('pre-send failure'),
                    0,
                );
                if ($released === null) {
                    throw new RuntimeException('The original owner could not release the effect.');
                }

                $this->signalMarker($directory.'/effect-released');
                $this->waitForMarker($directory.'/new-owner-claimed');

                return app(TelegramOutboundEffectLedgerProtocol::class)
                    ->finalizeSent($effect->id, $oldClaimToken, '100002') !== null;
            },
            function (string $directory) use ($effect): bool {
                $this->waitForMarker($directory.'/effect-released');

                $newClaim = app(TelegramOutboundEffectLedgerProtocol::class)
                    ->claim($effect->id);
                if ($newClaim === null) {
                    throw new RuntimeException('The later owner could not claim the effect.');
                }

                $this->signalMarker($directory.'/new-owner-claimed');

                return true;
            },
        ]);

        $this->assertSame(false, $results[0]['value'] ?? null, json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(true, $results[1]['value'] ?? null, json_encode($results, JSON_THROW_ON_ERROR));

        $stored = $effect->fresh();
        $this->assertSame(TelegramOutboundEffectState::Sending, $stored->state);
        $this->assertSame(2, $stored->attempts);
        $this->assertNotNull($stored->claim_token);
        $this->assertNotSame($oldClaimToken, $stored->claim_token);
        $this->assertNull($stored->telegram_message_id);
    }

    private function requirePostgresConcurrency(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Telegram outbound PostgreSQL races require the pgsql test connection.');
        }

        if ((string) env('RUN_CONCURRENCY_TESTS', '') !== '1') {
            $this->markTestSkipped('Set RUN_CONCURRENCY_TESTS=1 to run process-level PostgreSQL races.');
        }

        $this->assertSafeConcurrencyDatabase();
    }

    private function createTestEffect(): TelegramOutboundEffect
    {
        $effect = TelegramOutboundEffect::factory()->create();
        $this->createdEffectIds[] = $effect->id;

        return $effect;
    }

    private function assertSafeConcurrencyDatabase(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->fail('The pcntl extension is required for Telegram outbound PostgreSQL races.');
        }

        if (! extension_loaded('pdo_pgsql')) {
            $this->fail('The pdo_pgsql extension is required for Telegram outbound PostgreSQL races.');
        }

        if (app()->environment() !== 'testing') {
            $this->fail('Refusing Telegram outbound PostgreSQL races outside APP_ENV=testing.');
        }

        $connection = config('database.default');
        if ($connection !== 'pgsql' || config('database.connections.pgsql.driver') !== 'pgsql') {
            $this->fail('Telegram outbound PostgreSQL races require DB_CONNECTION=pgsql.');
        }

        if ((string) config('database.connections.pgsql.url') !== '' || (string) env('DB_URL', '') !== '') {
            $this->fail('Refusing Telegram outbound PostgreSQL races while DB_URL is configured.');
        }

        $database = (string) config('database.connections.pgsql.database');
        $rejectedDatabases = [
            'postgres',
            'template0',
            'template1',
            'production',
            'prod',
            'telegram_bot',
            'telegram-bot',
            'telegram_bot_prod',
            'telegram-bot-prod',
        ];
        if (in_array(strtolower($database), $rejectedDatabases, true)
            || preg_match('/(^|[_-])(prod|production|live|main)([_-]|$)/i', $database) === 1) {
            $this->fail("Refusing Telegram outbound PostgreSQL races against database [{$database}].");
        }

        if (preg_match('/(^|[_-])(test|testing)([_-]|$)/i', $database) !== 1) {
            $this->fail("Telegram outbound PostgreSQL races require an isolated test database; got [{$database}].");
        }

        $actualDatabase = (string) DB::selectOne('select current_database() as database')->database;
        if ($actualDatabase !== $database) {
            $this->fail("Connected database [{$actualDatabase}] does not match configured database [{$database}].");
        }
    }

    /** @param list<array{ok: bool, value?: mixed, class?: string, exit_code: int}> $results */
    private function successfulValues(array $results): array
    {
        foreach ($results as $result) {
            $this->assertTrue($result['ok'] ?? false, json_encode($results, JSON_THROW_ON_ERROR));
            $this->assertSame(0, $result['exit_code'] ?? null, json_encode($results, JSON_THROW_ON_ERROR));
        }

        return array_values(array_map(
            static fn (array $result): mixed => $result['value'] ?? null,
            $results,
        ));
    }

    /** @param list<callable(string): mixed> $callbacks */
    private function runConcurrently(array $callbacks): array
    {
        $directory = sys_get_temp_dir().'/telegram-outbound-concurrency-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700) && ! is_dir($directory)) {
            $this->fail('Unable to create Telegram outbound concurrency workspace.');
        }

        $releaseFile = $directory.'/release';
        $children = [];

        try {
            DB::disconnect();

            foreach ($callbacks as $index => $callback) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $this->terminateAndReapChildren($children);
                    $this->fail('Unable to fork Telegram outbound concurrency worker.');
                }

                if ($pid === 0) {
                    $this->runChildProcess($callback, $releaseFile, $directory.'/result-'.$index.'.json');
                }

                $children[$index] = $pid;
            }

            if (@touch($releaseFile) === false) {
                $this->terminateAndReapChildren($children);
                $this->fail('Unable to release Telegram outbound concurrency workers.');
            }

            $statuses = $this->waitForChildren($children, 20.0);
            $unfinished = array_diff_key($children, $statuses);
            if ($unfinished !== []) {
                $this->terminateAndReapChildren($unfinished);
                $this->fail('Telegram outbound PostgreSQL workers timed out: '.implode(', ', $unfinished));
            }

            $results = [];
            foreach ($children as $index => $pid) {
                $path = $directory.'/result-'.$index.'.json';
                $result = is_file($path)
                    ? json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)
                    : ['ok' => false, 'class' => RuntimeException::class];
                $status = $statuses[$index];
                $result['exit_code'] = is_int($status) && pcntl_wifexited($status)
                    ? pcntl_wexitstatus($status)
                    : 255;
                $results[] = $result;
            }

            return $results;
        } finally {
            $this->cleanupConcurrencyDirectory($directory);
            DB::reconnect();
        }
    }

    /** @param array<int, int> $children */
    private function waitForChildren(array $children, float $timeoutSeconds): array
    {
        $statuses = [];
        $deadline = microtime(true) + $timeoutSeconds;

        while (count($statuses) < count($children) && microtime(true) < $deadline) {
            foreach (array_diff_key($children, $statuses) as $index => $pid) {
                $status = 0;
                $waited = pcntl_waitpid($pid, $status, WNOHANG);
                if ($waited === $pid || $waited === -1) {
                    $statuses[$index] = $waited === $pid ? $status : null;
                }
            }

            if (count($statuses) < count($children)) {
                usleep(10_000);
            }
        }

        return $statuses;
    }

    /** @param array<int, int> $children */
    private function terminateAndReapChildren(array $children): void
    {
        foreach ($children as $pid) {
            if (function_exists('posix_kill')) {
                @posix_kill($pid, SIGTERM);
            }
        }

        $this->waitForChildren($children, 2.0);
    }

    private function cleanupConcurrencyDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (glob($directory.'/*') ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }

    private function runChildProcess(callable $callback, string $releaseFile, string $resultFile): never
    {
        DB::disconnect();

        $deadline = microtime(true) + 10;
        while (! is_file($releaseFile)) {
            if (microtime(true) > $deadline) {
                $this->writeChildResult($resultFile, ['ok' => false, 'class' => RuntimeException::class]);
                exit(1);
            }

            usleep(10_000);
        }

        try {
            $value = $callback(dirname($resultFile));
            $this->writeChildResult($resultFile, ['ok' => true, 'value' => $value]);
            exit(0);
        } catch (\Throwable $exception) {
            $this->writeChildResult($resultFile, ['ok' => false, 'class' => $exception::class]);
            exit(1);
        }
    }

    /** @param array<string, mixed> $result */
    private function writeChildResult(string $resultFile, array $result): void
    {
        file_put_contents($resultFile, json_encode($result, JSON_THROW_ON_ERROR), LOCK_EX);
    }

    private function signalMarker(string $path): void
    {
        if (@file_put_contents($path, 'ready', LOCK_EX) === false) {
            throw new RuntimeException('Unable to signal concurrency worker.');
        }
    }

    private function waitForMarker(string $path): void
    {
        $deadline = microtime(true) + 10;
        while (! is_file($path)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Timed out waiting for concurrency worker.');
            }

            usleep(10_000);
        }
    }
}
