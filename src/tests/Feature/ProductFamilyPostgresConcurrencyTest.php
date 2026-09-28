<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exceptions\ProductFamilyCategoryMismatch;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\User;
use App\Services\Admin\ProductFamilyCategoryCoherence;
use App\Services\Admin\ProductFamilyManagementService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PostgresTestDatabase;
use Tests\TestCase;
use Throwable;

#[Group('concurrency')]
final class ProductFamilyPostgresConcurrencyTest extends TestCase
{
    public function test_same_category_membership_mutations_serialize_on_family_boundary(): void
    {
        $this->requireConcurrencyLane();
        $this->freshConcurrencySchema();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $category = Category::factory()->create(['name' => 'Streaming']);
        $family = ProductFamily::factory()->create(['name' => 'Netflix']);
        $first = Product::factory()->create(['category_id' => $category->id, 'stock' => 2]);
        $second = Product::factory()->create(['category_id' => $category->id, 'stock' => 2]);

        $results = $this->runConcurrently([
            fn (): array => $this->membershipWorker($first->id, $family->id, $admin->id, 1),
            fn (): array => $this->membershipWorker($second->id, $family->id, $admin->id, 2),
        ]);

        $this->assertSame(['assigned', 'assigned'], $this->successfulOutcomes($results), $this->safeResultsJson($results));
        $this->assertSame(2, Product::query()->where('product_family_id', $family->id)->count());
        $this->assertSame($category->id, $first->fresh()->category_id);
        $this->assertSame($category->id, $second->fresh()->category_id);
    }

    public function test_concurrent_family_product_category_changes_cannot_create_mixed_categories(): void
    {
        $this->requireConcurrencyLane();
        $this->freshConcurrencySchema();

        $category = Category::factory()->create(['name' => 'Streaming']);
        $firstOtherCategory = Category::factory()->create(['name' => 'Design']);
        $secondOtherCategory = Category::factory()->create(['name' => 'Office']);
        $family = ProductFamily::factory()->create(['name' => 'Netflix']);
        $first = Product::factory()->create(['category_id' => $category->id, 'product_family_id' => $family->id]);
        $second = Product::factory()->create(['category_id' => $category->id, 'product_family_id' => $family->id]);

        $results = $this->runConcurrently([
            fn (): array => $this->categoryWorker($first->id, $firstOtherCategory->id),
            fn (): array => $this->categoryWorker($second->id, $secondOtherCategory->id),
        ]);

        $this->assertSame(['rejected', 'rejected'], $this->successfulOutcomes($results), $this->safeResultsJson($results));
        $this->assertSame($category->id, $first->fresh()->category_id);
        $this->assertSame($category->id, $second->fresh()->category_id);
        $this->assertSame(1, Product::query()->where('product_family_id', $family->id)->distinct('category_id')->count('category_id'));
    }

    private function membershipWorker(int $productId, int $familyId, int $adminId, int $sortOrder): array
    {
        try {
            DB::disconnect();
            $product = Product::query()->findOrFail($productId);
            $family = ProductFamily::query()->findOrFail($familyId);
            $admin = User::query()->findOrFail($adminId);
            app(ProductFamilyManagementService::class)->updateMembership($product, $family->id, $sortOrder, null, $admin);

            return ['outcome' => 'assigned'];
        } catch (Throwable) {
            return ['outcome' => 'failed'];
        }
    }

    private function categoryWorker(int $productId, int $categoryId): array
    {
        try {
            DB::disconnect();
            DB::transaction(function () use ($productId, $categoryId): void {
                $product = Product::query()->findOrFail($productId);
                $locked = app(ProductFamilyCategoryCoherence::class)->lockForCategoryChange($product, $categoryId);
                $locked->forceFill(['category_id' => $categoryId])->save();
            }, 5);

            return ['outcome' => 'updated'];
        } catch (ProductFamilyCategoryMismatch) {
            return ['outcome' => 'rejected'];
        } catch (Throwable) {
            return ['outcome' => 'failed'];
        }
    }

    private function requireConcurrencyLane(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Product Family PostgreSQL races run only with the pgsql test connection.');
        }
        if ((string) env('RUN_CONCURRENCY_TESTS', '') !== '1') {
            $this->markTestSkipped('Set RUN_CONCURRENCY_TESTS=1 to run Product Family process races.');
        }
        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('Product Family concurrency requires pcntl process support.');
        }
        if (! extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('The pdo_pgsql extension is required for Product Family concurrency.');
        }

        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        if ((string) config("database.connections.{$connection}.url", '') !== '' || (string) env('DB_URL', '') !== '') {
            $this->fail('Refusing Product Family concurrency while DB_URL is configured.');
        }
        if (! preg_match('/(^|[_-])(test|testing)([_-]|$)/i', $database)) {
            $this->fail("Refusing Product Family concurrency against non-test database [{$database}].");
        }
        $actual = (string) DB::selectOne('select current_database() as database')->database;
        $this->assertSame($database, $actual);
    }

    private function freshConcurrencySchema(): void
    {
        if (app()->environment() !== 'testing') {
            $this->fail('Refusing Product Family concurrency migrations outside APP_ENV=testing.');
        }

        $this->assertSame(0, PostgresTestDatabase::migrateFresh());
    }

    /** @param list<callable():array<string,mixed>> $callbacks */
    private function runConcurrently(array $callbacks): array
    {
        $directory = storage_path('framework/testing/product-family-concurrency-'.bin2hex(random_bytes(8)));
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            $this->fail('Unable to create Product Family concurrency workspace.');
        }

        $releaseFile = $directory.'/release';
        $children = [];
        DB::disconnect();

        try {
            foreach ($callbacks as $index => $callback) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $this->terminateAndReap($children);
                    $this->fail('Unable to fork Product Family concurrency worker.');
                }
                if ($pid === 0) {
                    $this->runChild($callback, $releaseFile, $directory.'/result-'.$index.'.json');
                }
                $children[$index] = $pid;
            }

            touch($releaseFile);
            $statuses = $this->waitForChildren($children, 20.0);
            $this->assertCount(count($children), $statuses, 'Product Family concurrency workers timed out.');
            $results = [];
            foreach ($children as $index => $pid) {
                $path = $directory.'/result-'.$index.'.json';
                $results[] = is_file($path)
                    ? json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)
                    : ['ok' => false, 'outcome' => 'missing'];
            }

            return $results;
        } finally {
            DB::reconnect();
            foreach (glob($directory.'/*') ?: [] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
        }
    }

    /** @param callable():array<string,mixed> $callback */
    private function runChild(callable $callback, string $releaseFile, string $resultFile): never
    {
        DB::disconnect();
        $deadline = microtime(true) + 10;
        while (! is_file($releaseFile)) {
            if (microtime(true) > $deadline) {
                file_put_contents($resultFile, json_encode(['ok' => false, 'outcome' => 'barrier_timeout'], JSON_THROW_ON_ERROR));
                exit(1);
            }
            usleep(10_000);
        }

        try {
            file_put_contents($resultFile, json_encode(['ok' => true] + $callback(), JSON_THROW_ON_ERROR));
            exit(0);
        } catch (Throwable) {
            file_put_contents($resultFile, json_encode(['ok' => false, 'outcome' => 'worker_failed'], JSON_THROW_ON_ERROR));
            exit(1);
        }
    }

    /** @param array<int,int> $children @return array<int,int> */
    private function waitForChildren(array $children, float $timeout): array
    {
        $statuses = [];
        $deadline = microtime(true) + $timeout;
        while (count($statuses) < count($children) && microtime(true) < $deadline) {
            foreach (array_diff_key($children, $statuses) as $index => $pid) {
                $status = 0;
                $waited = pcntl_waitpid($pid, $status, WNOHANG);
                if ($waited === $pid || $waited === -1) {
                    $statuses[$index] = $status;
                }
            }
            if (count($statuses) < count($children)) {
                usleep(10_000);
            }
        }

        return $statuses;
    }

    /** @param array<int,int> $children */
    private function terminateAndReap(array $children): void
    {
        foreach ($children as $pid) {
            if (function_exists('posix_kill')) {
                @posix_kill($pid, SIGTERM);
            }
        }
        foreach ($children as $pid) {
            $status = 0;
            @pcntl_waitpid($pid, $status);
        }
    }

    /** @param list<array<string,mixed>> $results @return list<string> */
    private function successfulOutcomes(array $results): array
    {
        $outcomes = array_values(array_map(
            static fn (array $result): string => (string) ($result['outcome'] ?? 'missing'),
            array_filter($results, static fn (array $result): bool => ($result['ok'] ?? false) === true),
        ));
        sort($outcomes);

        return $outcomes;
    }

    /** @param list<array<string,mixed>> $results */
    private function safeResultsJson(array $results): string
    {
        return json_encode(array_map(static fn (array $result): array => [
            'ok' => $result['ok'] ?? false,
            'outcome' => $result['outcome'] ?? null,
        ], $results), JSON_THROW_ON_ERROR);
    }
}
