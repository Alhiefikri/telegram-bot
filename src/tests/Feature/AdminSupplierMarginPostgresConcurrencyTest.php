<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ProductPriceChangeEvent;
use App\Models\User;
use App\Services\Admin\AdminSupplierMargins;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\RunsPostgresWorkers;
use Tests\Support\NonTransactionalDatabaseTestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
final class AdminSupplierMarginPostgresConcurrencyTest extends NonTransactionalDatabaseTestCase
{
    use RunsPostgresWorkers;

    public function test_overlapping_batches_in_opposite_order_have_one_winner_and_no_partial_write(): void
    {
        $this->requirePostgresConcurrency('Supplier margin');
        Queue::fake();
        $admins = User::factory()->count(2)->create(['role' => UserRole::Admin]);
        $one = AdminSupplierMarginTest::mappedProduct();
        $two = AdminSupplierMarginTest::mappedProduct();
        $margins = app(AdminSupplierMargins::class);
        $rows = $margins->page(['target' => 2_000, 'rounding' => 500])[1];
        $edits = fn (int $amount): array => $rows->map(fn ($row) => ['id' => $row['id'], 'version' => $row['version'], 'price' => $amount])->all();
        $first = $edits(21_000);
        $second = array_reverse($edits(22_000));
        $margins->review($first, 2_000, 500);
        $margins->review($second, 2_000, 500);

        $results = $this->runConcurrentWorkers(2, function (int $index) use ($margins, $first, $second, $admins): string {
            DB::statement("SET lock_timeout = '5s'");
            try {
                return (string) $margins->save($index === 0 ? $first : $second, 2_000, 500, $admins[$index], null);
            } catch (HttpException $exception) {
                return (string) $exception->getStatusCode();
            }
        }, 'supplier-margin-race');

        $this->assertWorkersSucceeded($results);
        $this->assertEqualsCanonicalizing(['2', '409'], array_column($results, 'value'));
        $winner = $results[0]['value'] === '2' ? 21_000 : 22_000;
        $this->assertSame($winner, $one->fresh()->price_amount);
        $this->assertSame($winner, $two->fresh()->price_amount);
        $this->assertSame(2, ProductPriceChangeEvent::count());
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_save_rechecks_catalog_cost_after_waiting_for_a_concurrent_write(): void
    {
        $this->requirePostgresConcurrency('Supplier margin');
        Queue::fake();
        $actor = User::factory()->create(['role' => UserRole::Owner]);
        $product = AdminSupplierMarginTest::mappedProduct();
        $item = $product->supplierSources()->sole()->catalogItem;
        $margins = app(AdminSupplierMargins::class);
        $row = $margins->page(['target' => 2_000, 'rounding' => 500])[1]->sole();
        $edits = [['id' => $product->id, 'version' => $row['version'], 'price' => 21_000]];
        $margins->review($edits, 2_000, 500);

        $directory = $this->createWorkspace('supplier-margin-cost-race');
        try {
            $results = $this->runConcurrentWorkers(2, function (int $index) use ($directory, $item, $actor, $margins, $edits): string {
                if ($index === 0) {
                    DB::transaction(function () use ($directory, $item): void {
                        $item->forceFill(['unit_price_micros' => 38_000_000_000])->save();
                        touch($directory.'/catalog-locked');
                        $this->waitForMarker($directory.'/save-pid');
                        $pid = (int) file_get_contents($directory.'/save-pid');
                        $deadline = microtime(true) + 3;
                        do {
                            $blocked = DB::selectOne('select pg_backend_pid() = any(pg_blocking_pids(?)) as blocked', [$pid])->blocked;
                            if ($blocked) {
                                return;
                            }
                            usleep(10_000);
                        } while (microtime(true) < $deadline);
                        $this->fail('Margin confirmation never waited on the catalog update.');
                    });

                    return 'updated';
                }
                $this->waitForMarker($directory.'/catalog-locked');
                DB::statement("SET lock_timeout = '5s'");
                file_put_contents($directory.'/save-pid.tmp', (string) DB::selectOne('select pg_backend_pid() as pid')->pid);
                rename($directory.'/save-pid.tmp', $directory.'/save-pid');
                try {
                    return (string) $margins->save($edits, 2_000, 500, $actor, null);
                } catch (HttpException $exception) {
                    return (string) $exception->getStatusCode();
                }
            }, 'supplier-margin-cost-workers');
        } finally {
            $this->cleanupWorkspace($directory);
        }

        $this->assertWorkersSucceeded($results);
        $this->assertSame(['updated', '409'], array_column($results, 'value'));
        $this->assertSame(18_500, $product->fresh()->price_amount);
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
