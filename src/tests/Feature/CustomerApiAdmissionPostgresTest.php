<?php

namespace Tests\Feature;

use App\Enums\TelegramNavigationMediaKind;
use App\Enums\TelegramNavigationScreen;
use App\Models\Customer;
use App\Models\TelegramNavigationRoot;
use App\Services\CustomerApi\CustomerApiAdmission;
use App\Services\CustomerApi\CustomerApiCredentials;
use App\Services\CustomerApi\CustomerApiFailure;
use App\Services\CustomerApi\CustomerApiUsage;
use App\Services\Telegram\TelegramCustomerApiActions;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RunsPostgresWorkers;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\RunsPostgresRace;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
class CustomerApiAdmissionPostgresTest extends TestCase
{
    use RunsPostgresRace, RunsPostgresWorkers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requirePostgresConcurrency('Customer API shared Redis admission');
        if (env('RUN_CUSTOMER_API_REDIS_TESTS') !== '1') {
            $this->markTestSkipped('Set RUN_CUSTOMER_API_REDIS_TESTS=1 with disposable shared Redis.');
        }
        if (! Schema::hasTable('customer_api_credentials')) {
            $this->assertSame(0, PostgresTestDatabase::migrateFresh());
        }
        config(['cache.default' => 'redis', 'cache.limiter' => 'redis', 'cache.prefix' => 's04-test-'.bin2hex(random_bytes(8)).':']);
        Route::get('/api/v1/probe/{id?}', fn (Request $request) => response()->json([
            'customer' => $request->attributes->get('customer_api_principal')->customerId,
            'admin_authenticated' => Auth::guard('web')->check(),
            'session_authenticated' => Auth::guard('customer')->check(),
        ]));
        Route::get('/api/v1/orders/{id}/delivery', fn () => response()->json(['state' => 'pending']));
        Route::post('/api/v1/body', fn (Request $request) => response()->json(['received' => strlen($request->getContent())]));
        Route::get('/api/v1/failure', fn () => throw new \RuntimeException('Synthetic secret SQL detail must not be exposed.'));
    }

    public function test_http_requires_bearer_only_and_revocation_never_grants_session_or_admin_authority(): void
    {
        $customer = Customer::factory()->create();
        $service = app(CustomerApiCredentials::class);
        $token = $service->generate($customer)->takeSecret();
        $this->getJson('/api/v1/probe?api_key='.urlencode($token))->assertStatus(401);
        $this->withCookie('api_key', $token)->getJson('/api/v1/probe')->assertStatus(401);
        $this->getJson('/api/v1/probe', ['Authorization' => 'Basic '.$token])->assertStatus(401);
        $this->getJson('/api/v1/probe', ['Authorization' => 'Bearer '.$token])->assertOk()->assertJson([
            'customer' => $customer->id, 'admin_authenticated' => false, 'session_authenticated' => false,
        ])->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/v1/probe', ['Authorization' => 'Bearer '.$token, 'Origin' => 'https://untrusted.example'])->assertStatus(403)->assertHeaderMissing('Access-Control-Allow-Origin');
        $this->getJson('/api/v1/failure', ['Authorization' => 'Bearer '.$token])->assertStatus(503)->assertJsonMissing(['message' => 'Synthetic secret SQL detail must not be exposed.']);
        $service->disable($customer);
        $this->getJson('/api/v1/probe', ['Authorization' => 'Bearer '.$token])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_ip_and_account_budgets_cannot_be_reset_by_forwarded_headers_endpoint_switching_or_rotation(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/v1/probe', ['X-Forwarded-For' => '192.0.2.'.($i + 1)])->assertStatus(401);
        }
        $this->getJson('/api/v1/probe', ['X-Forwarded-For' => '198.51.100.9'])->assertStatus(429)->assertHeader('Retry-After');
        $customer = Customer::factory()->create();
        $service = app(CustomerApiCredentials::class);
        $token = $service->generate($customer)->takeSecret();
        for ($i = 0; $i < 120; $i++) {
            if ($i === 60) {
                $confirmation = $service->prepareRotation($customer, 100);
                $token = $service->rotate($customer, 100, $confirmation)->takeSecret();
            }
            $this->getJson('/api/v1/probe/'.$i, ['Authorization' => 'Bearer '.$token])->assertOk();
        }
        $this->getJson('/api/v1/probe/switched', ['Authorization' => 'Bearer '.$token])->assertStatus(429)
            ->assertHeader('X-RateLimit-Limit', '120')->assertHeader('X-RateLimit-Remaining', '0')->assertHeader('Retry-After');
    }

    public function test_delivery_quota_is_additional_and_body_bounds_apply_without_content_length(): void
    {
        $customer = Customer::factory()->create();
        $token = app(CustomerApiCredentials::class)->generate($customer)->takeSecret();
        for ($i = 0; $i < 10; $i++) {
            $this->getJson('/api/v1/orders/'.$i.'/delivery', ['Authorization' => 'Bearer '.$token])->assertOk();
        }
        $this->getJson('/api/v1/orders/11/delivery', ['Authorization' => 'Bearer '.$token])->assertStatus(429)->assertHeader('X-RateLimit-Limit', '10');
        foreach (['/api/v1/orders/11/%64elivery', '/api/v1/orders%2F11%2Fdelivery'] as $encoded) {
            $this->getJson($encoded, ['Authorization' => 'Bearer '.$token])->assertStatus(429)->assertHeader('X-RateLimit-Limit', '10');
        }
        $this->getJson('/api/v1/probe', ['Authorization' => 'Bearer '.$token])->assertOk();
        $this->call('POST', '/api/v1/body', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/octet-stream'], content: str_repeat('x', 32768))
            ->assertOk()->assertJsonPath('received', 32768);
        $this->call('POST', '/api/v1/body', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/octet-stream'], content: str_repeat('x', 32769))
            ->assertStatus(413)->assertJsonPath('error.code', 'payload_too_large');
    }

    public function test_shared_workers_respect_account_orders_and_two_expiring_owner_fenced_permits(): void
    {
        $customer = Customer::factory()->create();
        $service = app(CustomerApiCredentials::class);
        $principal = $service->authenticate('Bearer '.$service->generate($customer)->takeSecret());
        $results = $this->race(array_fill(0, 3, function () use ($principal): string {
            app('redis')->purge('cache');
            try {
                // Simulate a worker crash: deliberately do not release the successful token.
                return app(CustomerApiAdmission::class)->authenticated($principal, true, false)['token'];
            } catch (CustomerApiFailure $failure) {
                return $failure->machineCode;
            }
        }));
        $tokens = array_values(array_filter($results, fn ($result) => $result !== 'rate_limited'));
        $this->assertCount(2, $tokens);
        $admission = app(CustomerApiAdmission::class);
        $admission->release($principal, 'not-the-owner');
        try {
            $admission->authenticated($principal, true, false);
            $this->fail('Foreign token release must not free another worker permit.');
        } catch (CustomerApiFailure $failure) {
            $this->assertSame(429, $failure->status);
        }
        $admission->release($principal, $tokens[0]);
        $replacement = $admission->authenticated($principal, true, false)['token'];
        $admission->release($principal, $tokens[0]);
        try {
            $admission->authenticated($principal, true, false);
            $this->fail('A stale release must not free a replacement permit.');
        } catch (CustomerApiFailure $failure) {
            $this->assertSame(429, $failure->status);
        }
        // Advance only the lease scores on disposable Redis, not the account attempt window.
        $store = Cache::store('redis')->getStore();
        $key = $store->getPrefix().'customer-api:inflight:'.hash_hmac('sha256', (string) $customer->id, (string) config('app.key'));
        $store->connection()->eval("redis.call('ZADD', KEYS[1], 0, ARGV[1], 0, ARGV[2]); return 1", 1, $key, $tokens[1], $replacement);
        $next = $admission->authenticated($principal, true, false)['token'];
        $admission->release($principal, $next);
        for ($i = 0; $i < 3; $i++) {
            $reservation = $admission->authenticated($principal, true, false);
            $admission->release($principal, $reservation['token']);
        }
        $this->expectException(CustomerApiFailure::class);
        $admission->authenticated($principal, true, false);
    }

    public function test_limiter_outage_stops_http_before_work_and_telemetry_is_coalesced_account_scoped_and_not_replayed(): void
    {
        $this->freezeTime();
        $customer = Customer::factory()->create();
        $credentials = app(CustomerApiCredentials::class);
        $token = $credentials->generate($customer)->takeSecret();
        for ($i = 0; $i < 4; $i++) {
            $this->getJson('/api/v1/probe', ['Authorization' => 'Bearer '.$token])->assertOk();
        }
        $this->assertSame('0', $credentials->summary($customer)['account_request_count']);
        $usage = app(CustomerApiUsage::class);
        $this->assertSame(1, $usage->flush());
        $this->assertSame('4', $credentials->summary($customer)['account_request_count']);
        $this->assertSame(0, $usage->flush());
        $this->assertSame('4', $credentials->summary($customer)['account_request_count']);
        $nonce = $credentials->prepareRotation($customer, 100);
        $new = $credentials->rotate($customer, 100, $nonce)->takeSecret();
        $this->getJson('/api/v1/probe', ['Authorization' => 'Bearer '.$new])->assertOk();
        $usage->flush();
        $this->assertSame('5', $credentials->summary($customer)['account_request_count']);
        config(['cache.stores.s04-unavailable' => ['driver' => 'redis', 'connection' => 's04-unavailable'],
            'database.redis.s04-unavailable' => ['host' => '127.0.0.1', 'port' => 1, 'database' => 0, 'timeout' => 0.1, 'read_timeout' => 0.1, 'max_retries' => 0],
            'cache.limiter' => 's04-unavailable']);
        $this->getJson('/api/v1/probe', ['Authorization' => 'Bearer '.$new])->assertStatus(503)->assertJsonPath('error.code', 'temporarily_unavailable');
        $this->assertSame('5', $credentials->summary($customer)['account_request_count']);
        $lastUsed = CarbonImmutable::parse($credentials->summary($customer)['last_used_at']);
        $this->assertEquals(now()->startOfMinute()->timestamp, $lastUsed->timestamp);
    }

    public function test_private_key_operations_share_create_cooldown_but_disable_remains_available(): void
    {
        $customer = Customer::factory()->create();
        $actor = (string) $customer->telegram_id;
        TelegramNavigationRoot::query()->create(['customer_id' => $customer->id, 'chat_id' => $actor, 'message_id' => 100,
            'media_kind' => TelegramNavigationMediaKind::Text, 'screen' => TelegramNavigationScreen::Home, 'locale' => 'id', 'generation' => 1]);
        $actions = app(TelegramCustomerApiActions::class);
        $token = $actions->generate($actor, $actor, 'private', 100)->takeSecret();
        for ($i = 0; $i < 2; $i++) {
            $nonce = $actions->prepareRotation($actor, $actor, 'private', 100);
            $token = $actions->rotate($actor, $actor, 'private', 100, $nonce)->takeSecret();
        }
        $nonce = $actions->prepareRotation($actor, $actor, 'private', 100);
        try {
            $actions->rotate($actor, $actor, 'private', 100, $nonce);
            $this->fail('Rotation bypassed the shared generation budget.');
        } catch (CustomerApiFailure $failure) {
            $this->assertSame(429, $failure->status);
        }
        $this->assertNotNull(app(CustomerApiCredentials::class)->authenticate('Bearer '.$token));
        $disable = $actions->prepareDisable($actor, $actor, 'private', 100);
        $actions->disable($actor, $actor, 'private', 100, $disable);
        $this->assertNull(app(CustomerApiCredentials::class)->authenticate('Bearer '.$token));
    }

    public function test_three_workers_share_one_read_budget_and_global_exhaustion_rejects_other_accounts(): void
    {
        $customer = Customer::factory()->create();
        $credentials = app(CustomerApiCredentials::class);
        $principal = $credentials->authenticate('Bearer '.$credentials->generate($customer)->takeSecret());
        $results = $this->race(array_fill(0, 3, function () use ($principal): string {
            app('redis')->purge('cache');
            $accepted = 0;
            for ($i = 0; $i < 50; $i++) {
                try {
                    app(CustomerApiAdmission::class)->authenticated($principal, false, false);
                    $accepted++;
                } catch (CustomerApiFailure $failure) {
                    $this->assertSame(429, $failure->status);
                }
            }

            return (string) $accepted;
        }));
        $this->assertSame(120, array_sum(array_map('intval', $results)));
        $other = Customer::factory()->create();
        $otherPrincipal = $credentials->authenticate('Bearer '.$credentials->generate($other)->takeSecret());
        $admission = app(CustomerApiAdmission::class);
        foreach ([['global-reads', 300, false], ['global-orders', 20, true]] as [$purpose, $maximum, $mutation]) {
            $key = 'customer-api:'.$purpose.':'.hash_hmac('sha256', 'all', (string) config('app.key'));
            // Keep an exhausted global window open deterministically without depending on wall-clock test speed.
            $admission->evaluate("return redis.call('SET', KEYS[1], ARGV[1], 'EX', 60)", [$key], [$maximum]);
            try {
                $admission->authenticated($otherPrincipal, $mutation, false);
                $this->fail('Global admission exhaustion must reject a different account.');
            } catch (CustomerApiFailure $failure) {
                $this->assertSame(429, $failure->status);
                $this->assertSame((string) $maximum, $failure->headers['X-RateLimit-Limit']);
            }
        }
    }

    public function test_full_usage_bucket_drops_new_identities_but_keeps_coalescing_existing_ones(): void
    {
        $customer = Customer::factory()->create();
        $credentials = app(CustomerApiCredentials::class);
        $principal = $credentials->authenticate('Bearer '.$credentials->generate($customer)->takeSecret());
        $key = 'customer-api:usage:'.intdiv(now()->timestamp, 60);
        app(CustomerApiAdmission::class)->evaluate(<<<'LUA'
for i=1,10000 do redis.call('HSET', KEYS[1], 'capacity-fixture-' .. i, 1) end
redis.call('EXPIRE', KEYS[1], 600)
return 1
LUA, [$key]);
        app(CustomerApiUsage::class)->record($principal);
        $field = $principal->customerId.':'.$principal->credentialId;
        $value = app(CustomerApiAdmission::class)->evaluate("return redis.call('HGET', KEYS[1], ARGV[1])", [$key], [$field]);
        $this->assertFalse($value);
        app(CustomerApiAdmission::class)->evaluate("redis.call('HDEL', KEYS[1], 'capacity-fixture-1'); return redis.call('HSET', KEYS[1], ARGV[1], 1)", [$key], [$field]);
        app(CustomerApiUsage::class)->record($principal);
        $this->assertSame('2', app(CustomerApiAdmission::class)->evaluate("return redis.call('HGET', KEYS[1], ARGV[1])", [$key], [$field]));
    }
}
