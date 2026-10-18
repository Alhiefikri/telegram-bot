<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exceptions\CustomerApiAvailabilityConflict;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\User;
use App\Services\CustomerApi\CustomerApiAvailability;
use App\Services\CustomerApi\CustomerApiCredentials;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RunsPostgresWorkers;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\RunsPostgresRace;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
final class CustomerApiAvailabilityPostgresConcurrencyTest extends TestCase
{
    use RunsPostgresRace, RunsPostgresWorkers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requirePostgresConcurrency('Customer API availability');
        $this->assertSame(0, PostgresTestDatabase::migrateFresh());
        Http::preventStrayRequests();
        config([
            'cache.default' => 'redis',
            'cache.limiter' => 'redis',
            'cache.prefix' => 'availability-race-'.bin2hex(random_bytes(8)).':',
        ]);
    }

    public function test_competing_owner_updates_produce_one_change_one_conflict_and_one_audit_row(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $results = $this->runConcurrentWorkers(2, function (int $index) use ($owner): string {
            try {
                $change = app(CustomerApiAvailability::class)
                    ->change(true, 0, $owner, '203.0.113.'.($index + 1));

                return $change->changed ? 'changed' : 'noop';
            } catch (CustomerApiAvailabilityConflict) {
                return 'conflict';
            }
        });

        $values = array_map(fn (array $result): string => (string) $result['value'], $results);
        sort($values);

        $this->assertSame(['changed', 'conflict'], $values, json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertDatabaseHas('customer_api_settings', [
            'singleton_key' => 'customer_api',
            'enabled' => true,
            'version' => 1,
        ]);
        $this->assertSame(1, AuditLog::query()->where('action', 'customer_api.availability.enabled')->count());
    }

    public function test_requests_after_the_off_commit_get_maintenance_without_auth_claim_or_money_side_effect(): void
    {
        $customer = Customer::factory()->create();
        $secret = app(CustomerApiCredentials::class)->generate($customer)->takeSecret();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        app(CustomerApiAvailability::class)->change(true, 0, $owner, '203.0.113.20');
        app(CustomerApiAvailability::class)->change(false, 1, $owner, '203.0.113.20');

        $product = Product::factory()->create([
            'price_amount' => 15_000,
            'usdt_price_amount' => 2_000_000,
            'stock' => 1,
            'reserved_stock' => 0,
        ]);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'availability-race-secret']);
        $body = json_encode([
            'product_id' => (string) $product->id,
            'quantity' => 1,
            'preference' => 'usd_only',
            'expected_totals' => ['USD' => '2'],
        ], JSON_THROW_ON_ERROR);

        $results = $this->runConcurrentWorkers(2, function (int $index) use ($secret, $body): string {
            // Do not share inherited PostgreSQL/Redis sockets across forks.
            DB::purge();
            DB::reconnect();
            app('redis')->purge('default');
            app('redis')->purge('cache');
            Cache::purge('redis');
            $request = Request::create('/api/v1/orders', 'POST', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$secret,
                'HTTP_IDEMPOTENCY_KEY' => 'off-race-'.$index,
            ], $body);
            $response = app(Kernel::class)->handle($request);
            $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

            return $response->getStatusCode().':'.($payload['error']['code'] ?? 'none');
        });

        $this->assertSame(
            ['503:api_maintenance', '503:api_maintenance'],
            array_map(fn (array $result): string => (string) $result['value'], $results),
            json_encode($results, JSON_THROW_ON_ERROR),
        );
        $this->assertDatabaseCount('customer_api_order_claims', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('customer_usdt_balance_entries', 0);
        $this->assertSame(0, ProductStockItem::whereNotNull('order_item_id')->count());
        $this->assertNotNull(app(CustomerApiCredentials::class)->authenticate('Bearer '.$secret));
        $state = app(CustomerApiAvailability::class)->current();
        $this->assertFalse($state->enabled);
        $this->assertSame(2, $state->version);
    }
}
