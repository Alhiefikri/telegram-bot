<?php

namespace Tests\Feature\Telegram;

use App\Enums\TelegramNavigationMediaKind;
use App\Enums\TelegramNavigationScreen;
use App\Models\Customer;
use App\Models\TelegramNavigationRoot;
use App\Services\Telegram\FakeTelegramBotClient;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramNavigationRootService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
final class TelegramNavigationRootPostgresConcurrencyTest extends TestCase
{
    /** @var list<int> */
    private array $createdCustomerIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql' || (string) env('RUN_CONCURRENCY_TESTS', '') !== '1') {
            return;
        }

        $this->assertSafeConcurrencyDatabase();
        if (! Schema::hasTable('telegram_navigation_roots')) {
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        }
        $this->app->instance(TelegramBotClient::class, new FakeTelegramBotClient);
    }

    protected function tearDown(): void
    {
        if ($this->createdCustomerIds !== [] && Schema::hasTable('telegram_navigation_roots')) {
            TelegramNavigationRoot::query()->whereIn('customer_id', $this->createdCustomerIds)->delete();
            Customer::query()->whereKey($this->createdCustomerIds)->delete();
        }

        parent::tearDown();
    }

    public function test_postgres_workers_allow_only_one_render_owner_for_one_current_root(): void
    {
        $this->requirePostgresConcurrency();

        $customer = Customer::factory()->create(['telegram_id' => 45201, 'locale' => 'en']);
        $this->createdCustomerIds[] = $customer->id;
        $root = TelegramNavigationRoot::query()->create([
            'customer_id' => $customer->id,
            'chat_id' => '45201',
            'message_id' => 801,
            'media_kind' => TelegramNavigationMediaKind::Photo,
            'screen' => TelegramNavigationScreen::Picker,
            'locale' => null,
            'banner_reference' => 'banner-801',
            'generation' => 1,
            'rendered_at' => now(),
        ]);

        $results = $this->runConcurrentRenders($root->id, '45201');
        foreach ($results as $result) {
            $this->assertTrue($result['ok'] ?? false, json_encode($results, JSON_THROW_ON_ERROR));
            $this->assertSame(0, $result['exit_code'] ?? null, json_encode($results, JSON_THROW_ON_ERROR));
        }

        $outcomes = array_map(static fn (array $result): string => (string) ($result['value'] ?? ''), $results);
        sort($outcomes);
        $this->assertSame(['Applied', 'Superseded'], $outcomes, json_encode($results, JSON_THROW_ON_ERROR));

        $stored = $root->fresh();
        $this->assertSame(1, TelegramNavigationRoot::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(2, $stored->generation);
        $this->assertSame(TelegramNavigationScreen::Home, $stored->screen);
        $this->assertNull($stored->render_token);
        $this->assertNull($stored->render_started_at);
    }

    public function test_postgres_start_cannot_be_overwritten_by_an_older_locale_callback(): void
    {
        $this->requirePostgresConcurrency();

        $customer = Customer::factory()->create(['telegram_id' => 45202, 'locale' => 'en']);
        $this->createdCustomerIds[] = $customer->id;
        $root = TelegramNavigationRoot::query()->create([
            'customer_id' => $customer->id,
            'chat_id' => '45202',
            'message_id' => 802,
            'media_kind' => TelegramNavigationMediaKind::Photo,
            'screen' => TelegramNavigationScreen::Home,
            'locale' => 'en',
            'banner_reference' => 'banner-802',
            'generation' => 1,
            'rendered_at' => now(),
        ]);

        $results = $this->runConcurrentRenders($root->id, '45202', $customer->id);
        foreach ($results as $result) {
            $this->assertTrue($result['ok'] ?? false, json_encode($results, JSON_THROW_ON_ERROR));
            $this->assertSame(0, $result['exit_code'] ?? null, json_encode($results, JSON_THROW_ON_ERROR));
        }

        $this->assertSame('Established', $results[1]['value'] ?? null, json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertContains($results[0]['value'] ?? null, ['Applied', 'Superseded'], json_encode($results, JSON_THROW_ON_ERROR));

        $stored = $root->fresh();
        $this->assertNotSame(802, $stored->message_id);
        $this->assertSame(1, TelegramNavigationRoot::query()->where('customer_id', $customer->id)->count());
        $this->assertGreaterThanOrEqual(2, $stored->generation);
        $this->assertSame(TelegramNavigationScreen::Home, $stored->screen);
        $this->assertNull($stored->render_token);
    }

    private function requirePostgresConcurrency(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Telegram navigation PostgreSQL races require the pgsql test connection.');
        }

        if ((string) env('RUN_CONCURRENCY_TESTS', '') !== '1') {
            $this->markTestSkipped('Set RUN_CONCURRENCY_TESTS=1 to run process-level PostgreSQL races.');
        }

        $this->assertSafeConcurrencyDatabase();
    }

    private function assertSafeConcurrencyDatabase(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->fail('The pcntl extension is required for Telegram navigation PostgreSQL races.');
        }

        if (! extension_loaded('pdo_pgsql')) {
            $this->fail('The pdo_pgsql extension is required for Telegram navigation PostgreSQL races.');
        }

        if (app()->environment() !== 'testing') {
            $this->fail('Refusing Telegram navigation PostgreSQL races outside APP_ENV=testing.');
        }

        if (config('database.default') !== 'pgsql' || config('database.connections.pgsql.driver') !== 'pgsql') {
            $this->fail('Telegram navigation PostgreSQL races require DB_CONNECTION=pgsql.');
        }

        if ((string) config('database.connections.pgsql.url') !== '' || (string) env('DB_URL', '') !== '') {
            $this->fail('Refusing Telegram navigation PostgreSQL races while DB_URL is configured.');
        }

        $database = (string) config('database.connections.pgsql.database');
        if (preg_match('/(^|[_-])(prod|production|live|main)([_-]|$)/i', $database) === 1
            || ! preg_match('/(^|[_-])(test|testing)([_-]|$)/i', $database)) {
            $this->fail("Telegram navigation PostgreSQL races require an isolated test database; got [{$database}].");
        }

        $actualDatabase = (string) DB::selectOne('select current_database() as database')->database;
        if ($actualDatabase !== $database) {
            $this->fail("Connected database [{$actualDatabase}] does not match configured database [{$database}].");
        }
    }

    /** @return list<array{ok: bool, value?: string, class?: string, exit_code: int}> */
    private function runConcurrentRenders(int $rootId, string $chatId, ?int $establishCustomerId = null): array
    {
        $directory = sys_get_temp_dir().'/telegram-navigation-concurrency-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700) && ! is_dir($directory)) {
            $this->fail('Unable to create Telegram navigation concurrency workspace.');
        }

        $children = [];
        try {
            DB::disconnect();
            for ($index = 0; $index < 2; $index++) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $this->terminateAndReap($children);
                    $this->fail('Unable to fork Telegram navigation concurrency worker.');
                }
                if ($pid === 0) {
                    $this->runNavigationChild(
                        $rootId,
                        $chatId,
                        $directory.'/ready-'.$index,
                        $directory.'/start',
                        $directory.'/result-'.$index.'.json',
                        $index === 1 ? $establishCustomerId : null,
                    );
                }
                $children[$index] = $pid;
            }

            foreach (range(0, 1) as $index) {
                $this->waitForMarker($directory.'/ready-'.$index);
            }
            touch($directory.'/start');

            $results = [];
            foreach ($children as $index => $pid) {
                $status = 0;
                pcntl_waitpid($pid, $status);
                $resultPath = $directory.'/result-'.$index.'.json';
                $result = is_file($resultPath)
                    ? json_decode((string) file_get_contents($resultPath), true, flags: JSON_THROW_ON_ERROR)
                    : ['ok' => false, 'class' => RuntimeException::class];
                $result['exit_code'] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 255;
                $results[] = $result;
            }

            return $results;
        } finally {
            foreach (glob($directory.'/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($directory);
            DB::reconnect();
        }
    }

    private function runNavigationChild(
        int $rootId,
        string $chatId,
        string $readyFile,
        string $startFile,
        string $resultFile,
        ?int $establishCustomerId,
    ): never {
        try {
            DB::disconnect();
            $root = $establishCustomerId === null
                ? TelegramNavigationRoot::query()->findOrFail($rootId)
                : null;
            $customer = $establishCustomerId === null
                ? null
                : Customer::query()->findOrFail($establishCustomerId);
            touch($readyFile);
            $this->waitForMarker($startFile);

            if ($customer !== null) {
                app(TelegramNavigationRootService::class)->establish(
                    $customer,
                    $chatId,
                    new TelegramNavigationSurface(
                        TelegramNavigationScreen::Home,
                        'en',
                        'New home',
                        ['inline_keyboard' => []],
                    ),
                );
                $value = 'Established';
            } else {
                $outcome = app(TelegramNavigationRootService::class)->render(
                    $root,
                    $chatId,
                    new TelegramNavigationSurface(
                        TelegramNavigationScreen::Home,
                        'en',
                        'Home',
                        ['inline_keyboard' => []],
                    ),
                );
                $value = $outcome->name;
            }

            file_put_contents($resultFile, json_encode(['ok' => true, 'value' => $value], JSON_THROW_ON_ERROR), LOCK_EX);
            exit(0);
        } catch (\Throwable $exception) {
            file_put_contents($resultFile, json_encode(['ok' => false, 'class' => $exception::class], JSON_THROW_ON_ERROR), LOCK_EX);
            exit(1);
        }
    }

    private function waitForMarker(string $path): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($path)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Timed out waiting for Telegram navigation concurrency worker.');
            }
            usleep(10_000);
        }
    }

    /** @param array<int, int> $children */
    private function terminateAndReap(array $children): void
    {
        foreach ($children as $pid) {
            if (function_exists('posix_kill')) {
                @posix_kill($pid, SIGTERM);
            }
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }
    }
}
