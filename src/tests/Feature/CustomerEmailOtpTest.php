<?php

namespace Tests\Feature;

use App\Enums\CustomerAuthTokenPurpose;
use App\Models\Customer;
use App\Models\CustomerAuthMailWork;
use App\Models\CustomerAuthToken;
use App\Models\User;
use App\Services\Customer\CustomerEmailOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CustomerEmailOtpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Notification::fake();
    }

    public function test_request_is_generic_for_known_unknown_and_unverified_email_without_creating_accounts(): void
    {
        $customer = $this->customer();
        Customer::factory()->create(['email' => 'pending@example.test', 'email_verified_at' => null]);
        $messages = [];
        foreach ([$customer->email, 'unknown@example.test', 'pending@example.test'] as $email) {
            $this->post(route('customer.login-code.request'), ['email' => $email])
                ->assertRedirect(route('customer.account'))->assertHeader('Cache-Control', 'no-store, private');
            $messages[] = session('customer_login_sent');
            $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', session('customer_otp_challenge'));
        }
        $this->assertSame($messages[0], $messages[1]);
        $this->assertSame($messages[0], $messages[2]);
        $this->assertSame(2, Customer::query()->count());
        $this->assertSame(1, CustomerAuthToken::query()->count());
        $this->assertSame(1, CustomerAuthMailWork::query()->count());
        $this->assertGuest('customer');
    }

    public function test_valid_code_authenticates_once_rotates_session_and_preserves_admin_guard(): void
    {
        $customer = $this->customer();
        $admin = User::factory()->create();
        $this->actingAs($admin, 'web');
        [$challenge, $code, $token] = $this->issue($customer);
        $oldSession = session()->getId();
        $oldCsrf = session()->token();
        $this->withCookie(config('session.cookie'), $oldSession)->consume($customer->email, $challenge, $code)
            ->assertRedirect(route('customer.orders.index'));
        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertAuthenticatedAs($admin, 'web');
        $this->assertNotSame($oldSession, session()->getId());
        $this->assertNotSame($oldCsrf, session()->token());
        $this->assertSame('', session()->getHandler()->read($oldSession));
        $this->assertSame($customer->auth_version, session('customer_auth_version'));
        $this->assertNotNull($token->fresh()->consumed_at);
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->post(route('customer.logout'));
        $this->consume($customer->email, $challenge, $code)->assertSessionHas('customer_login_error');
        $this->assertGuest('customer');
    }

    public function test_code_and_recipient_are_encrypted_in_delivery_work_and_digest_is_keyed_and_challenge_bound(): void
    {
        $customer = $this->customer();
        [$challenge, $code, $token] = $this->issue($customer);
        $work = CustomerAuthMailWork::query()->sole();
        $this->assertMatchesRegularExpression('/\A[0-9]{6}\z/', $code);
        $this->assertSame(600.0, $token->created_at->diffInSeconds($token->expires_at));
        $this->assertSame(CustomerEmailOtpService::codeDigest($customer->email, hash('sha256', $challenge), $code), $token->token_hash);
        $this->assertNotSame(hash('sha256', $code), $token->token_hash);
        $this->assertNotSame(CustomerEmailOtpService::codeDigest($customer->email, str_repeat('a', 64), $code), $token->token_hash);
        $this->assertStringNotContainsString($code, $work->getRawOriginal('payload'));
        $this->assertStringNotContainsString($customer->email, $work->getRawOriginal('payload'));
        $this->assertStringNotContainsString($challenge, json_encode($token->getAttributes(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($token->token_hash, $token->toJson());
        $this->assertStringNotContainsString($code, $work->toJson());
        $this->assertSame(CustomerAuthTokenPurpose::EmailOtp, $token->purpose);
    }

    public function test_five_incorrect_attempts_survive_cache_loss_and_exhaust_the_challenge(): void
    {
        $customer = $this->customer();
        [$challenge, $code, $token] = $this->issue($customer);
        $wrong = $code === '000000' ? '000001' : '000000';
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->consume($customer->email, $challenge, $wrong)->assertSessionHas('customer_login_error');
            $this->assertSame($attempt, $token->fresh()->attempts);
            Cache::flush();
        }
        $this->consume($customer->email, $challenge, $code)->assertSessionHas('customer_login_error');
        $this->assertSame(5, $token->fresh()->attempts);
        $this->assertGuest('customer');
    }

    public function test_expiry_is_inclusive_and_password_reset_purpose_cannot_consume_otp(): void
    {
        $customer = $this->customer();
        [$challenge, $code, $token] = $this->issue($customer);
        $this->post(route('customer.password-reset.consume'), [
            'token' => $challenge, 'password' => 'Unwanted replacement password', 'password_confirmation' => 'Unwanted replacement password',
        ])->assertSessionHas('customer_login_error');
        $this->assertNull($customer->fresh()->password);
        $this->assertNull($token->fresh()->consumed_at);
        $this->travelTo($token->expires_at);
        $this->consume($customer->email, $challenge, $code)->assertSessionHas('customer_login_error');
        $this->assertGuest('customer');
    }

    public function test_other_email_and_other_browser_cannot_use_a_code(): void
    {
        $customer = $this->customer();
        [$challenge, $code, $token] = $this->issue($customer);
        $this->consume('foreign@example.test', $challenge, $code)->assertSessionHas('customer_login_error');
        $binding = session('customer_otp_binding');
        session()->put('customer_otp_binding', bin2hex(random_bytes(32)));
        $this->consume($customer->email, $challenge, $code)->assertSessionHas('customer_login_error');
        $this->assertSame(0, $token->fresh()->attempts);
        $this->assertGuest('customer');
        session()->put('customer_otp_binding', $binding);
        $this->consume($customer->email, $challenge, $code)->assertRedirect(route('customer.orders.index'));
    }

    public function test_resend_cooldown_survives_cache_loss_and_replacement_revokes_old_code(): void
    {
        $customer = $this->customer();
        [$challenge, $code, $token] = $this->issue($customer);
        Cache::flush();
        $this->post(route('customer.login-code.request'), ['email' => $customer->email])->assertSessionHas('customer_login_sent');
        $this->assertSame($challenge, session('customer_otp_challenge'));
        $this->assertSame(1, CustomerAuthToken::query()->count());
        $this->travel(60)->seconds();
        Cache::flush();
        [$replacement, $newCode] = $this->issue($customer);
        $this->assertNotSame($challenge, $replacement);
        $this->assertNotNull($token->fresh()->consumed_at);
        $this->consume($customer->email, $challenge, $code)->assertSessionHas('customer_login_error');
        $this->consume($customer->email, $replacement, $newCode)->assertRedirect(route('customer.orders.index'));
    }

    public function test_email_delivery_limits_are_shared_with_reset_across_ips(): void
    {
        $customer = $this->customer();
        $this->issue($customer);
        $this->travel(60)->seconds();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])->post(route('customer.password-reset.request'), ['email' => $customer->email]);
        $this->travel(60)->seconds();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.3'])->issue($customer);
        $this->assertSame(3, CustomerAuthToken::query()->count());
        $this->travel(60)->seconds();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.4'])->post(route('customer.login-code.request'), ['email' => strtoupper($customer->email)])
            ->assertSessionHas('customer_login_sent');
        $this->assertSame(3, CustomerAuthToken::query()->count());
    }

    public function test_ip_request_and_consume_limits_and_missing_storage_fail_closed(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post(route('customer.login-code.request'), ['email' => 'unknown'.$i.'@example.test']);
        }
        $customer = $this->customer();
        $this->post(route('customer.login-code.request'), ['email' => $customer->email]);
        $this->assertSame(0, CustomerAuthToken::query()->count());
        Cache::flush();
        [$challenge, $code] = $this->issue($customer);
        for ($i = 0; $i < 30; $i++) {
            $this->consume($customer->email, bin2hex(random_bytes(32)), '000000');
        }
        $this->consume($customer->email, $challenge, $code)->assertSessionHas('customer_login_error');
        $this->assertGuest('customer');
        $this->travel(15)->minutes();
        [$challenge, $code] = $this->issue($customer);
        config(['cache.limiter' => 'missing-auth-cache']);
        $this->consume($customer->email, $challenge, $code)->assertSessionHas('customer_login_error');
        $this->travel(60)->seconds();
        $this->post(route('customer.login-code.request'), ['email' => $customer->email]);
        $this->assertSame(2, CustomerAuthToken::query()->count());
        $this->assertGuest('customer');
    }

    public function test_version_change_fences_existing_code_and_validation_does_not_flash_secrets(): void
    {
        $customer = $this->customer();
        [$challenge, $code] = $this->issue($customer);
        $customer->forceFill(['auth_version' => 1])->save();
        $this->consume($customer->email, $challenge, $code)->assertSessionHas('customer_login_error');
        $this->post(route('customer.login-code.consume'), ['email' => 'invalid', 'challenge' => $challenge, 'code' => $code])->assertSessionHasErrors('email');
        $this->assertNull(session()->getOldInput('code'));
        $this->assertNull(session()->getOldInput('challenge'));
        $this->assertGuest('customer');
    }

    public function test_leading_zero_codes_are_not_coerced_to_numbers(): void
    {
        $customer = $this->customer();
        [$challenge, , $token] = $this->issue($customer);
        // A fixed-code fixture uses a new immutable challenge rather than rewriting an issued digest.
        $token->forceFill(['consumed_at' => now()])->save();
        $attributes = $token->getAttributes();
        unset($attributes['id']);
        $attributes['consumed_at'] = null;
        $attributes['challenge_hash'] = hash('sha256', $challenge = bin2hex(random_bytes(32)));
        $attributes['token_hash'] = CustomerEmailOtpService::codeDigest($customer->email, $attributes['challenge_hash'], '000007');
        $fixture = new CustomerAuthToken;
        $fixture->forceFill($attributes)->save();
        $this->consume($customer->email, $challenge, '7')->assertSessionHas('customer_login_error');
        $this->consume($customer->email, $challenge, '000007')->assertRedirect(route('customer.orders.index'));
    }

    private function customer(): Customer
    {
        return Customer::factory()->create(['email' => 'otp@example.test', 'email_verified_at' => now()])->fresh();
    }

    private function issue(Customer $customer): array
    {
        $this->post(route('customer.login-code.request'), ['email' => strtoupper($customer->email)])->assertSessionHas('customer_login_sent');
        $token = CustomerAuthToken::query()->where('customer_id', $customer->id)->where('purpose', CustomerAuthTokenPurpose::EmailOtp->value)->latest('id')->firstOrFail();
        $code = CustomerAuthMailWork::query()->where('auth_token_id', $token->id)->sole()->payload['token'];

        return [session('customer_otp_challenge'), $code, $token];
    }

    private function consume(string $email, string $challenge, string $code): TestResponse
    {
        return $this->post(route('customer.login-code.consume'), compact('email', 'challenge', 'code'));
    }
}
