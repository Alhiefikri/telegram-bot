<?php

namespace Tests\Feature;

use App\Enums\SakurupiahEnvironment;
use App\Exceptions\CheckoutPaymentUnavailable;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\Sakurupiah\SakurupiahChannel;
use App\Services\Sakurupiah\SakurupiahChannelAddition;
use App\Services\Sakurupiah\SakurupiahInvoiceLifecycleService;
use App\Services\Sakurupiah\SakurupiahProviderException;
use App\Services\Sakurupiah\SakurupiahProviderReadiness;
use App\Services\Sakurupiah\SakurupiahRequestBackpressure;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\QrisTestPayload;
use Tests\Support\RunsPostgresRace;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
class SakurupiahCheckoutRecoveryPostgresConcurrencyTest extends TestCase
{
    use RunsPostgresRace;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql' || ! extension_loaded('redis') || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires disposable PostgreSQL, Redis extension and pcntl.');
        }
        if (env('RUN_SAKURUPIAH_REDIS_TESTS') !== '1') {
            $this->markTestSkipped('Set RUN_SAKURUPIAH_REDIS_TESTS=1 with disposable Redis.');
        }
        PostgresTestDatabase::migrateFresh();
        config()->set('cache.default', 'redis');
        config()->set('database.redis.cache.host', env('REDIS_HOST', '127.0.0.1'));
        config()->set('database.redis.cache.port', (int) env('REDIS_PORT', 6379));
        config()->set('database.redis.cache.database', 7);
        config()->set('database.redis.cache.password', null);
        config()->set('database.redis.cache.url', null);
        config()->set('database.redis.default', config('database.redis.cache'));
        Cache::purge('redis');
        Cache::flush();
        Queue::fake();
        config()->set('services.sakurupiah', array_replace(config('services.sakurupiah', []), [
            'sandbox_enabled' => true, 'sandbox_base_url' => 'https://sakurupiah.id/api-sanbox/',
            'sandbox_api_id' => 'issue677-fixture', 'sandbox_api_key' => 'fixture-only',
            'sandbox_callback_url' => 'https://store.example.test/api/webhook/sakurupiah',
            'sandbox_return_url' => 'https://store.example.test/shop/orders',
            'sandbox_callback_allowed_hosts' => ['store.example.test'], 'sandbox_return_allowed_hosts' => ['store.example.test'],
            'lookup_retry_delay_ms' => 0, 'lookup_retry_max_delay_ms' => 0,
            'qris_contract' => ['currency' => 'IDR', 'currency_confirmed' => true, 'fee_payer' => 'merchant',
                'merchant_fee' => 1, 'fee_confirmed' => true, 'expiry_hours' => 24, 'expiry_confirmed' => true],
        ]));
        ManualPaymentSetting::create(['payment_mode' => 'sakurupiah', 'sakurupiah_channel' => 'QRISC', 'is_active' => true]);
        DB::statement('CREATE TABLE issue677_provider_effects (reference varchar(255) PRIMARY KEY, operation varchar(32) NOT NULL)');
    }

    public function test_background_yields_to_inflight_checkout_then_resumes_without_overlapping_dispatch(): void
    {
        $results = $this->race([
            function (): string {
                app('redis')->purge('cache');
                Cache::purge('redis');
                $lock = (new SakurupiahRequestBackpressure)->acquireRequest('createInvoice', false, 'telegram_checkout');
                try {
                    DB::table('issue677_provider_effects')->insert(['reference' => 'foreground', 'operation' => 'createInvoice']);
                    usleep(400000);
                } finally {
                    $lock->release();
                }

                return 'checkout_completed';
            },
            function (): string {
                app('redis')->purge('cache');
                Cache::purge('redis');
                $deadline = microtime(true) + 3;
                while (! DB::table('issue677_provider_effects')->where('reference', 'foreground')->exists()) {
                    if (microtime(true) > $deadline) {
                        throw new \RuntimeException('Foreground never acquired dispatch.');
                    }
                    usleep(1000);
                }
                try {
                    (new SakurupiahRequestBackpressure)->acquireRequest('transactionStatus', true, 'reconciliation_command');
                    throw new \RuntimeException('Background overlapped foreground dispatch.');
                } catch (SakurupiahProviderException $exception) {
                    return $exception->reason;
                }
            },
        ]);
        $this->assertSame(['checkout_completed', 'lookup_temporarily_unavailable'], $results);
        $lock = (new SakurupiahRequestBackpressure)->acquireRequest('transactionStatus', true, 'reconciliation_command');
        $lock->release();
        $this->assertSame(1, DB::table('issue677_provider_effects')->count());
    }

    public function test_cold_and_expired_readiness_are_single_flight_across_processes(): void
    {
        foreach ([0, 60] as $round) {
            if ($round !== 0) {
                $this->travel($round)->seconds();
            }
            $results = $this->race(array_fill(0, 3, function (): string {
                app('redis')->purge('cache');
                Cache::purge('redis');
                $report = app(SakurupiahProviderReadiness::class)->observe(SakurupiahEnvironment::Sandbox, function (): array {
                    DB::table('issue677_provider_effects')->insert(['reference' => bin2hex(random_bytes(8)), 'operation' => 'listChannels']);
                    usleep(300000);

                    return [new SakurupiahChannel('QRISC', 'QRISC', 500, 2_000_000, 'DIRECT', 'Aktif',
                        addition: new SakurupiahChannelAddition(defaultExpiredHours: 24))];
                });

                return $report->isReady() ? 'ready' : $report->failureReason();
            }));
            $this->assertContains('ready', $results);
            $this->assertSame([], array_values(array_diff($results, ['ready', 'observation_refresh_busy'])));
            $this->assertTrue(app(SakurupiahProviderReadiness::class)->observe()->isReady());
            $this->assertSame($round === 0 ? 1 : 2, DB::table('issue677_provider_effects')->count());
        }
    }

    public function test_two_customers_and_duplicate_checkout_create_one_invoice_per_intent(): void
    {
        $product = Product::factory()->create(['stock' => 20, 'reserved_stock' => 0, 'price_amount' => 600]);
        $carts = [];
        foreach ([10, 1] as $quantity) {
            $customer = Customer::factory()->create(['phone_number' => '08123456789']);
            $cart = app(CartService::class)->activeCartFor($customer);
            app(CartService::class)->setItemQuantity($cart, $product, $quantity);
            $carts[] = $cart;
        }
        Http::fake(function ($request) {
            $this->assertSame(0, DB::transactionLevel(), 'Provider I/O must remain outside money locks.');
            if (str_ends_with($request->url(), '/list-payment.php')) {
                return Http::response(['status' => '200', 'data' => [['kode' => 'QRISC', 'nama' => 'QRISC',
                    'minimal' => 500, 'maksimal' => 2_000_000, 'tipe' => 'DIRECT', 'status' => 'Aktif',
                    'addition' => ['default_expired' => 24]]]]);
            }
            DB::table('issue677_provider_effects')->insert(['reference' => $request['merchant_ref'], 'operation' => 'createInvoice']);
            usleep(100000);

            return Http::response(['status' => '200', 'data' => [[
                'trx_id' => 'trx-'.$request['merchant_ref'], 'merchant_ref' => $request['merchant_ref'],
                'payment_kode' => $request['method'], 'payment_status' => 'pending',
                'amount' => $request['amount'], 'total' => $request['amount'], 'fee' => 0, 'merchant_fee' => 1,
                'date' => now()->format('Y-m-d'), 'time' => now()->format('H:i:s'),
                'expired' => now()->addHours(24)->format('Y-m-d H:i:s'), 'qr' => QrisTestPayload::forAmount((int) $request['amount']),
            ]]]);
        });
        $workers = [];
        foreach ([0, 0, 1] as $index) {
            $workers[] = function () use ($carts, $index): string {
                app('redis')->purge('cache');
                Cache::purge('redis');
                for ($attempt = 0; $attempt < 5; $attempt++) {
                    try {
                        $order = app(OrderCheckoutService::class)->checkout($carts[$index], CheckoutPaymentIntents::sakurupiah(), checkoutKey: 'issue677-customer-'.$index);
                        break;
                    } catch (CheckoutPaymentUnavailable $exception) {
                        if ($exception->reason !== 'observation_refresh_busy') {
                            throw $exception;
                        }
                        usleep(100000);
                    }
                }
                if (! isset($order)) {
                    throw new \RuntimeException('Bounded checkout recovery exhausted.');
                }
                app(SakurupiahInvoiceLifecycleService::class)->lifecycle($order->payments()->sole());

                return $order->id;
            };
        }
        $results = $this->race($workers);
        $this->assertSame($results[0], $results[1]);
        $this->assertNotSame($results[0], $results[2]);
        $this->assertSame(2, Order::query()->count());
        $this->assertSame(2, Payment::query()->count());
        $this->assertSame(2, DB::table('issue677_provider_effects')->where('operation', 'createInvoice')->count());
        $this->assertSame(11, $product->fresh()->reserved_stock);
        $this->assertSame(2, InventoryMovement::query()->where('type', 'reservation')->count());
        $this->assertSame([600, 6000], Payment::query()->orderBy('amount')->pluck('amount')->all());
    }

    public function test_concurrent_throttle_observations_keep_longest_merchant_deadline(): void
    {
        $this->travelTo('2026-10-28 12:00:00');
        $expected = now()->timestamp + 120;
        $this->race(array_map(fn (int $seconds) => function () use ($seconds): bool {
            app('redis')->purge('cache');
            Cache::purge('redis');
            (new SakurupiahRequestBackpressure)->observe(new \Illuminate\Http\Client\Response(
                new Response(429, ['Retry-After' => (string) $seconds])));

            return true;
        }, [60, 120, 80]));
        $this->assertSame($expected, (new SakurupiahRequestBackpressure)->retryAt());
    }
}
