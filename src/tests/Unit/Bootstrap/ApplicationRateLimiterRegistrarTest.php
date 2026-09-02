<?php

namespace Tests\Unit\Bootstrap;

use App\Bootstrap\ApplicationRateLimiterRegistrar;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class ApplicationRateLimiterRegistrarTest extends TestCase
{
    private RateLimiter $rateLimiter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rateLimiter = new RateLimiter(new CacheRepository(new ArrayStore));
        (new ApplicationRateLimiterRegistrar($this->rateLimiter, new ConfigRepository([
            'services' => [
                'telegram' => ['webhook_secret' => 'telegram-secret', 'webhook_rate_limit_per_minute' => 17],
                'pakasir' => ['webhook_rate_limit_per_minute' => 23, 'status_refresh_rate_limit_per_minute' => 4],
                'sakurupiah' => ['webhook_rate_limit_per_minute' => 29, 'status_refresh_rate_limit_per_minute' => 5],
                'storefront' => ['checkout_rate_limit_per_minute' => 6, 'credential_access_rate_limit_per_minute' => 7],
            ],
        ])))->register();
    }

    public function test_it_registers_every_application_limiter_without_booting_laravel(): void
    {
        foreach ([
            'telegram',
            'pakasir-webhook',
            'pakasir-status-refresh',
            'sakurupiah-webhook',
            'sakurupiah-status-refresh',
            'storefront-checkout',
            'storefront-order-access',
            'customer-login-link',
            'customer-login-consume',
            'customer-order-claim',
        ] as $name) {
            $this->assertNotNull($this->rateLimiter->limiter($name), "{$name} limiter was not registered.");
        }
    }

    public function test_telegram_policy_preserves_trusted_and_untrusted_identity_buckets(): void
    {
        $trusted = $this->limit('telegram', Request::create('/', 'POST', [
            'callback_query' => ['from' => ['id' => 123]],
        ], [], [], ['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'telegram-secret']));
        $untrusted = $this->limit('telegram', Request::create('/', 'POST', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.25',
        ]));

        $this->assertLimit($trusted, 17, 60, 'telegram:webhook:identity:123');
        $this->assertLimit($untrusted, 17, 60, 'telegram:webhook:bad-secret:source:203.0.113.25');
    }

    public function test_pakasir_webhook_policy_preserves_source_bucket(): void
    {
        $limit = $this->limit('pakasir-webhook', Request::create('/', 'POST', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.30',
        ]));

        $this->assertLimit($limit, 23, 60, 'pakasir:webhook:source:203.0.113.30');
    }

    public function test_sakurupiah_webhook_policy_preserves_source_bucket(): void
    {
        $limit = $this->limit('sakurupiah-webhook', Request::create('/', 'POST', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.35',
        ]));

        $this->assertLimit($limit, 29, 60, 'sakurupiah:webhook:source:203.0.113.35');
    }

    public function test_sakurupiah_status_refresh_policy_preserves_order_and_source_buckets(): void
    {
        $request = Request::create('/', 'POST');
        $request->setRouteResolver(fn () => new class
        {
            public function parameter(string $name): object
            {
                return new class
                {
                    public function getKey(): int
                    {
                        return 654;
                    }
                };
            }
        });

        $limits = $this->limits('sakurupiah-status-refresh', $request);

        $this->assertCount(2, $limits);
        $this->assertLimit($limits[0], 5, 60, 'sakurupiah:status-refresh:order:654');
        $this->assertLimit($limits[1], 5, 60, 'sakurupiah:status-refresh:source:127.0.0.1');
    }

    public function test_pakasir_status_refresh_policy_preserves_order_bucket_and_custom_response(): void
    {
        $request = Request::create('/', 'POST');
        $request->setRouteResolver(fn () => new class
        {
            public function parameter(string $name): object
            {
                return new class
                {
                    public function getKey(): int
                    {
                        return 456;
                    }
                };
            }
        });

        $limits = $this->limits('pakasir-status-refresh', $request);

        $this->assertCount(2, $limits);
        $this->assertLimit($limits[0], 4, 60, 'pakasir:status-refresh:order:456');
        $this->assertLimit($limits[1], 4, 60, 'pakasir:status-refresh:source:127.0.0.1');
        $this->assertIsCallable($limits[0]->responseCallback);

    }

    public function test_binance_order_id_policy_has_order_and_ip_buckets(): void
    {
        $request = Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.31']);
        $request->setRouteResolver(fn () => new class
        {
            public function parameter(string $name): object
            {
                return new class
                {
                    public function getKey(): int
                    {
                        return 789;
                    }
                };
            }
        });

        $limits = $this->limits('storefront-binance-order-id', $request);
        $this->assertLimit($limits[0], 5, 60, 'storefront:binance-order-id:order:789');
        $this->assertLimit($limits[1], 15, 60, 'storefront:binance-order-id:source:203.0.113.31');
    }

    public function test_storefront_checkout_policy_preserves_source_and_session_buckets(): void
    {
        $limits = $this->limits('storefront-checkout', Request::create('/', 'POST', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.40',
        ]));

        $this->assertCount(2, $limits);
        $this->assertLimit($limits[0], 6, 60, 'storefront:checkout:source:203.0.113.40');
        $this->assertLimit($limits[1], 6, 60, 'storefront:checkout:session:no-session');
        $this->assertIsCallable($limits[0]->responseCallback);
        $this->assertIsCallable($limits[1]->responseCallback);
    }

    public function test_storefront_order_access_policy_preserves_source_and_normalized_invoice_buckets(): void
    {
        $limits = $this->limits('storefront-order-access', Request::create('/', 'POST', [
            'invoice_number' => ' inv-123 ',
        ], [], [], ['REMOTE_ADDR' => '203.0.113.50']));

        $this->assertLimit($limits[0], 7, 60, 'storefront:order-access:source:203.0.113.50');
        $this->assertLimit($limits[1], 7, 60, 'storefront:order-access:invoice:INV-123');
    }

    public function test_customer_login_link_policy_preserves_email_and_ip_buckets(): void
    {
        $limits = $this->limits('customer-login-link', Request::create('/', 'POST', [
            'email' => ' Customer@Example.Test ',
        ], [], [], ['REMOTE_ADDR' => '203.0.113.60']));

        $this->assertLimit($limits[0], 3, 900, 'customer:login:email:customer@example.test:203.0.113.60');
        $this->assertLimit($limits[1], 10, 3600, 'customer:login:ip:203.0.113.60');
    }

    public function test_customer_login_consume_policy_preserves_ip_bucket(): void
    {
        $limit = $this->limit('customer-login-consume', Request::create('/', 'POST', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.70',
        ]));

        $this->assertLimit($limit, 10, 900, 'customer:login:consume:203.0.113.70');
    }

    public function test_customer_order_claim_policy_preserves_customer_and_ip_bucket(): void
    {
        $request = Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.80']);
        $request->setUserResolver(fn (?string $guard = null) => new class
        {
            public function getAuthIdentifier(): int
            {
                return 789;
            }
        });

        $limit = $this->limit('customer-order-claim', $request);

        $this->assertLimit($limit, 5, 900, 'customer:claim:789:203.0.113.80');
    }

    private function limit(string $name, Request $request): Limit
    {
        $limit = ($this->rateLimiter->limiter($name))($request);
        $this->assertInstanceOf(Limit::class, $limit);

        return $limit;
    }

    private function limits(string $name, Request $request): array
    {
        $limits = ($this->rateLimiter->limiter($name))($request);
        $this->assertIsArray($limits);

        return $limits;
    }

    private function assertLimit(Limit $limit, int $attempts, int $seconds, string $key): void
    {
        $this->assertSame($attempts, $limit->maxAttempts);
        $this->assertSame($seconds, $limit->decaySeconds);
        $this->assertSame($key, $limit->key);
    }
}
