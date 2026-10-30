<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\PollActiveSakurupiahPayment;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Sakurupiah\SakurupiahProviderAdapter;
use App\Services\Sakurupiah\SakurupiahStatus;
use App\Services\Sakurupiah\SakurupiahTransactionStatus;
use App\Support\PaymentProvider;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\RunsPostgresRace;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
class SakurupiahFastPollPostgresConcurrencyTest extends TestCase
{
    use RunsPostgresRace;

    public function test_concurrent_wakeups_admit_one_delayed_poll_for_one_payment(): void
    {
        $this->prepareIsolatedServices();
        $this->freezeTime();
        $order = Order::factory()->create(['order_status' => OrderStatus::PendingPayment, 'expires_at' => now()->addMinutes(10)]);
        $payment = Payment::factory()->for($order)->create([
            'provider' => PaymentProvider::SAKURUPIAH,
            'method' => 'QRIS',
            'status' => PaymentStatus::Pending,
            'provider_reference' => 'fixture-fast-poll',
            'expired_at' => now()->addMinutes(10),
        ]);

        $results = $this->race(array_fill(0, 3, function () use ($payment): string {
            app('redis')->purge('cache');
            app('redis')->purge('default');
            Cache::purge('redis');
            PollActiveSakurupiahPayment::schedule(Payment::findOrFail($payment->id));

            return 'done';
        }));

        $this->assertSame(['done', 'done', 'done'], $results);
        app('redis')->purge('default');
        $this->assertSame(1, Queue::connection('redis')->delayedSize('payments'));
        $this->travel(19)->seconds();
        $this->assertNull(Queue::connection('redis')->pop('payments'));
        $this->travel(5)->seconds();
        $this->assertNotNull(Queue::connection('redis')->pop('payments'));
    }

    public function test_burst_of_active_poll_jobs_keeps_one_next_wakeup_per_payment(): void
    {
        $this->prepareIsolatedServices();
        $this->freezeTime();
        $count = 30;
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->times($count)->andReturnUsing(function (string $reference) {
            usleep(100_000);

            return new SakurupiahStatus(trxId: $reference, status: SakurupiahTransactionStatus::Pending);
        });
        $provider->shouldReceive('transactionHistory')->never();
        $provider->shouldReceive('createInvoice')->never();
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);
        foreach (range(1, $count) as $index) {
            $order = Order::factory()->create(['order_status' => OrderStatus::PendingPayment, 'expires_at' => now()->addMinutes(10)]);
            $payment = Payment::factory()->for($order)->make([
                'provider' => PaymentProvider::SAKURUPIAH,
                'method' => 'QRIS',
                'status' => PaymentStatus::Pending,
                'provider_reference' => 'fixture-poll-burst-'.$index,
                'expired_at' => now()->addMinutes(10),
            ]);
            $payment->id = sprintf('00000000-0000-4000-8000-%012d', $index);
            $payment->save();
            PollActiveSakurupiahPayment::schedule($payment);
        }
        $this->assertSame($count, Queue::connection('redis')->delayedSize('payments'));
        $bucketCounts = [];
        foreach (range(0, PollActiveSakurupiahPayment::JITTER_SECONDS) as $offset) {
            $timestamp = now()->timestamp + PollActiveSakurupiahPayment::INTERVAL_SECONDS + $offset;
            $bucketCounts[] = Queue::connection('redis')->getConnection()->command('zCount', [
                'queues:payments:delayed', $timestamp, $timestamp,
            ]);
        }
        $this->assertSame($count, array_sum($bucketCounts));
        $this->assertGreaterThan(0, min($bucketCounts));
        $this->assertLessThanOrEqual(10, max($bucketCounts));
        $this->travel(24)->seconds();
        $worker = app('queue.worker');
        $durations = [];
        foreach (range(1, $count) as $_) {
            $start = hrtime(true);
            $worker->runNextJob('redis', 'payments', new WorkerOptions(sleep: 0));
            $durations[] = (hrtime(true) - $start) / 1_000_000;
        }
        sort($durations);
        $this->assertSame($count, Queue::connection('redis')->delayedSize('payments'));
        $this->assertSame(0, Queue::connection('redis')->pendingSize('payments'));
        fwrite(STDERR, sprintf("\nFixture 30 active polls: total=%.1f ms p95=%.1f ms/job (mock provider with 100 ms delay, local PostgreSQL/Redis).\n",
            array_sum($durations), $durations[(int) ceil($count * 0.95) - 1]));
    }

    private function prepareIsolatedServices(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! extension_loaded('redis') || ! function_exists('pcntl_fork')
            || env('RUN_SAKURUPIAH_REDIS_TESTS') !== '1') {
            $this->markTestSkipped('Requires opted-in disposable PostgreSQL and Redis.');
        }

        PostgresTestDatabase::migrateFresh();
        config()->set('cache.default', 'redis');
        config()->set('database.redis.cache.host', env('REDIS_HOST', '127.0.0.1'));
        config()->set('database.redis.cache.port', (int) env('REDIS_PORT', 6379));
        config()->set('database.redis.cache.database', 7);
        config()->set('database.redis.cache.password', null);
        config()->set('database.redis.cache.url', null);
        config()->set('database.redis.default', config('database.redis.cache'));
        config()->set('queue.default', 'redis');
        Cache::purge('redis');
        Cache::flush();
    }
}
