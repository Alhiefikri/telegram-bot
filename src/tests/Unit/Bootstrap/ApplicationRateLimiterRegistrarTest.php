<?php

namespace Tests\Unit\Bootstrap;

use App\Bootstrap\ApplicationRateLimiterRegistrar;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Tests\TestCase;

class ApplicationRateLimiterRegistrarTest extends TestCase
{
    public function test_it_registers_every_application_limiter_with_existing_telegram_behavior(): void
    {
        config([
            'services.telegram.webhook_secret' => 'telegram-secret',
            'services.telegram.webhook_rate_limit_per_minute' => 17,
        ]);

        (new ApplicationRateLimiterRegistrar)->register();

        $rateLimiter = app(RateLimiter::class);

        foreach ([
            'telegram',
            'pakasir-webhook',
            'pakasir-status-refresh',
            'storefront-checkout',
            'storefront-order-access',
            'customer-login-link',
            'customer-login-consume',
            'customer-order-claim',
        ] as $name) {
            $this->assertNotNull($rateLimiter->limiter($name), "{$name} limiter was not registered.");
        }

        $trustedLimit = ($rateLimiter->limiter('telegram'))(Request::create('/', 'POST', [
            'message' => ['from' => ['id' => 123]],
        ], [], [], [
            'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'telegram-secret',
        ]));
        $untrustedLimit = ($rateLimiter->limiter('telegram'))(Request::create('/', 'POST', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.25',
        ]));

        $this->assertInstanceOf(Limit::class, $trustedLimit);
        $this->assertSame(17, $trustedLimit->maxAttempts);
        $this->assertSame('telegram:webhook:identity:123', $trustedLimit->key);
        $this->assertSame('telegram:webhook:bad-secret:source:203.0.113.25', $untrustedLimit->key);
    }
}
