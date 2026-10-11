<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerAuthToken;
use App\Services\Customer\CustomerAuthAvailability;
use App\Services\Customer\CustomerAuthenticationRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class CustomerAuthReleaseSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_rollback_blocks_new_proofs_but_keeps_legacy_and_guest_access(): void
    {
        Queue::fake();
        app(CustomerAuthAvailability::class)->setEnabled(false);
        $this->get(route('customer.account', ['view' => 'register']))->assertOk()
            ->assertSee('action="'.route('customer.login-link.request').'"', false)
            ->assertDontSee('action="'.route('customer.register').'"', false);
        foreach (['customer.register', 'customer.login', 'customer.login-code.request', 'customer.login-code.consume',
            'customer.verification.consume', 'customer.password-reset.request', 'customer.password-reset.consume'] as $route) {
            $this->post(route($route), [])->assertRedirect(route('customer.account'));
        }
        foreach (['customer.telegram.login', 'customer.telegram.status', 'customer.telegram.consume'] as $route) {
            $method = $route === 'customer.telegram.status' ? 'getJson' : 'postJson';
            $this->{$method}(route($route))->assertStatus(503)->assertExactJson(['status' => 'unavailable']);
        }
        $this->assertSame(0, Customer::query()->count());
        $this->assertSame(0, CustomerAuthToken::query()->count());
        $this->get(route('storefront.order-access.create'))->assertOk();
        $this->get(route('storefront.shop.index'))->assertOk();
        $this->post(route('customer.login-link.request'), ['email' => 'legacy-release@example.test'])->assertRedirect(route('customer.account'));
        $this->assertDatabaseCount('customer_login_links', 1);
        app(CustomerAuthAvailability::class)->setEnabled(true);
        $this->get(route('customer.account'))->assertOk()->assertSee('action="'.route('customer.login').'"', false);
    }

    public function test_rollback_does_not_retain_oauth_callback_capabilities_in_history(): void
    {
        app(CustomerAuthAvailability::class)->setEnabled(false);
        $secret = str_repeat('a', 64);
        $this->get(route('customer.google.callback', ['code' => $secret, 'state' => $secret]))
            ->assertRedirect(route('customer.account'));
        $this->assertStringNotContainsString($secret, json_encode(session()->all(), JSON_THROW_ON_ERROR));
    }

    public function test_legacy_throttle_normalizes_email_without_plaintext_cache_keys(): void
    {
        $first = Request::create('/', 'POST', ['email' => ' Privacy@Example.Test ']);
        $second = Request::create('/', 'POST', ['email' => 'privacy@example.test']);
        $policy = RateLimiter::limiter('customer-login-link');
        $limits = $policy($first);
        $this->assertSame($limits[0]->key, $policy($second)[0]->key);
        $this->assertStringNotContainsString('privacy', strtolower($limits[0]->key));
        for ($i = 0; $i < $limits[0]->maxAttempts; $i++) {
            RateLimiter::hit($limits[0]->key, $limits[0]->decaySeconds);
        }
        $this->assertTrue(RateLimiter::tooManyAttempts($policy($second)[0]->key, 3));
        $other = Request::create('/', 'POST', ['email' => 'other@example.test']);
        $this->assertFalse(RateLimiter::tooManyAttempts($policy($other)[0]->key, 3));
    }

    public function test_recovery_key_is_not_flashed_after_claim_validation_failure(): void
    {
        $customer = Customer::factory()->create(['email' => 'claim-release@example.test', 'email_verified_at' => now()]);
        $key = 'private-recovery-capability';
        $this->actingAs($customer, 'customer')->post(route('customer.claim.store'), ['access_key' => $key])
            ->assertSessionHasErrors('invoice_number')->assertSessionMissing('_old_input.access_key');
    }

    public function test_logout_is_rate_limited_and_does_not_revoke_other_customer_data(): void
    {
        $customer = Customer::factory()->create(['email' => 'logout-release@example.test', 'email_verified_at' => now()]);
        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($customer, 'customer')->post(route('customer.logout'))->assertRedirect(route('customer.account'));
        }
        $this->actingAs($customer, 'customer')->post(route('customer.logout'))->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame(0, $customer->fresh()->auth_version);
    }

    public function test_legacy_link_shares_delivery_cooldown_across_ips_and_auth_methods(): void
    {
        Queue::fake();
        $email = 'shared-delivery@example.test';
        $customer = Customer::factory()->create(['email' => $email, 'email_verified_at' => now()]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])
            ->post(route('customer.login-link.request'), ['email' => $email])
            ->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_sent');
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])
            ->post(route('customer.login-link.request'), ['email' => strtoupper($email)])
            ->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_sent');
        $this->post(route('customer.password-reset.request'), ['email' => $email])
            ->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_sent');
        $this->assertDatabaseCount('customer_login_links', 1);
        $this->assertDatabaseCount('customer_auth_mail_works', 1);
        $this->assertDatabaseCount('customer_auth_tokens', 0);
        $this->travel(61)->seconds();
        $this->post(route('customer.password-reset.request'), ['email' => $email])->assertRedirect(route('customer.account'));
        $this->assertDatabaseHas('customer_auth_tokens', ['customer_id' => $customer->id, 'purpose' => 'password_reset']);
        $this->assertDatabaseCount('customer_auth_mail_works', 2);
    }

    public function test_callback_unlink_and_telegram_browser_budgets_expire_without_cross_operation_exhaustion(): void
    {
        $limits = app(CustomerAuthenticationRateLimiter::class);
        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue($limits->allowGoogleCallback('browser', '192.0.2.10'));
            $this->assertTrue($limits->allowGoogleUnlink('browser', '192.0.2.10'));
        }
        $this->assertFalse($limits->allowGoogleCallback('browser', '192.0.2.10'));
        $this->assertFalse($limits->allowGoogleUnlink('browser', '192.0.2.10'));
        for ($i = 0; $i < 120; $i++) {
            $this->assertTrue($limits->allowTelegramBrowser('browser', '192.0.2.10', false));
        }
        $this->assertFalse($limits->allowTelegramBrowser('browser', '192.0.2.10', false));
        for ($i = 0; $i < 30; $i++) {
            $this->assertTrue($limits->allowTelegramBrowser('browser', '192.0.2.10', true));
        }
        $this->assertFalse($limits->allowTelegramBrowser('browser', '192.0.2.10', true));
        $this->travel(900)->seconds();
        $this->assertTrue($limits->allowGoogleCallback('browser', '192.0.2.10'));
        $this->assertTrue($limits->allowGoogleUnlink('browser', '192.0.2.10'));
        $this->assertTrue($limits->allowTelegramBrowser('browser', '192.0.2.10', false));
        $this->assertTrue($limits->allowTelegramBrowser('browser', '192.0.2.10', true));
    }

    public function test_new_auth_limits_fail_closed_when_cache_is_unavailable(): void
    {
        Cache::shouldReceive('store')->andThrow(new \RuntimeException('private cache diagnostic'));
        $limits = app(CustomerAuthenticationRateLimiter::class);
        $this->assertFalse($limits->allowMail('private@example.test', '127.0.0.1'));
        $this->assertFalse($limits->reserveLogin('private@example.test', '127.0.0.1'));
        $this->assertFalse($limits->allowOtpConsume('127.0.0.1'));
        $this->assertFalse($limits->allowGoogleStart('session', '127.0.0.1'));
        $this->assertFalse($limits->allowGoogleCallback('session', '127.0.0.1'));
        $this->assertFalse($limits->allowGoogleUnlink('session', '127.0.0.1'));
        $this->assertFalse($limits->allowTelegramStart('session', '127.0.0.1'));
        $this->assertFalse($limits->allowTelegramBrowser('session', '127.0.0.1', true));
        $this->assertFalse($limits->allowTelegramProof('12345'));
    }
}
