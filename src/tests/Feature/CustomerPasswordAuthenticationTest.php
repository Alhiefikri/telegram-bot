<?php

namespace Tests\Feature;

use App\Enums\CustomerAuthTokenPurpose;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\CustomerAuthMailWork;
use App\Models\CustomerAuthToken;
use App\Models\CustomerLoginLink;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\User;
use App\Notifications\CustomerAuthMailNotification;
use App\Services\Customer\CustomerAuthMailDeliveryService;
use App\Services\Customer\CustomerPasswordHasher;
use App\Storefront\StorefrontOrderAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CustomerPasswordAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();
    }

    public function test_registration_is_pending_until_post_verification_authenticates_the_customer(): void
    {
        $password = '  Mixed Case password1!  ';
        $token = $this->register(' New@Example.TEST ', $password, 'New Customer');
        $customer = Customer::query()->where('email', 'new@example.test')->sole();
        $challenge = CustomerAuthToken::query()->where('token_hash', hash('sha256', $token))->sole();

        $this->assertGuest('customer');
        $this->assertNull($customer->email_verified_at);
        $this->assertNull($customer->password);
        $this->assertTrue(app(CustomerPasswordHasher::class)->check($password, $challenge->proposed_password_hash, CustomerPasswordHasher::SCHEME));
        $this->assertSame(CustomerAuthTokenPurpose::EmailVerification, $challenge->purpose);
        $this->assertSame($customer->id, $challenge->customer_id);
        $this->assertSame($customer->enrollment_version, $challenge->enrollment_version);
        $this->assertEquals(1800, $challenge->created_at->diffInSeconds($challenge->expires_at));

        $this->get(route('customer.verification.show', ['token' => $token]))
            ->assertOk()->assertDontSee($token, false);
        $this->assertNull($challenge->fresh()->consumed_at);
        $this->assertNull($customer->fresh()->password);
        $this->assertGuest('customer');

        $sessionId = session()->getId();
        $csrfToken = session()->token();
        $this->withCookie(config('session.cookie'), $sessionId)->post(route('customer.verification.consume'), ['token' => $token])
            ->assertRedirect(route('customer.orders.index'));

        $customer = $customer->fresh();
        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertGuest('web');
        $this->assertNotNull($customer->email_verified_at);
        $this->assertTrue($this->passwordMatches($password, $customer));
        $this->assertNotNull($challenge->fresh()->consumed_at);
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertNotSame($csrfToken, session()->token());
        $this->assertSame($customer->auth_version, session('customer_auth_version'));
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->get(route('customer.orders.index'))->assertOk();

        $this->post(route('customer.verification.consume'), ['token' => $token])
            ->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_error');
    }

    public function test_duplicate_pending_registration_cannot_replace_the_proposed_password_or_name(): void
    {
        $token = $this->register('pending@example.test', 'Original pending password1!', 'Original Name');
        $customer = Customer::query()->where('email', 'pending@example.test')->sole();
        $original = $customer->getAttributes();
        $challenge = CustomerAuthToken::query()->sole()->getAttributes();
        $this->travel(61)->seconds();

        $this->post(route('customer.register'), [
            'email' => ' PENDING@EXAMPLE.TEST ',
            'password' => 'Attacker replacement password1!',
            'password_confirmation' => 'Attacker replacement password1!',
            'name' => 'Replacement Name',
        ])->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_sent');

        $this->assertSame($original, $customer->fresh()->getAttributes());
        $this->assertSame($challenge, CustomerAuthToken::query()->sole()->getAttributes());
        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(1, CustomerAuthMailWork::query()->count());
        $this->post(route('customer.verification.consume'), ['token' => $token])
            ->assertRedirect(route('customer.orders.index'));
        $this->assertTrue($this->passwordMatches('Original pending password1!', $customer->fresh()));
        $this->assertFalse($this->passwordMatches('Attacker replacement password1!', $customer->fresh()));
        $this->assertSame('Original Name', $customer->fresh()->first_name);
    }

    public function test_duplicate_existing_email_does_not_overwrite_or_merge_customers(): void
    {
        $existing = $this->verifiedCustomer('existing@example.test');
        $legacy = Customer::factory()->create([
            'email' => 'legacy@example.test', 'email_verified_at' => now(), 'first_name' => 'Legacy Name',
        ]);
        $unrelated = Customer::factory()->create(['email' => null, 'first_name' => 'Submitted Name']);
        $order = Order::factory()->create(['customer_id' => $unrelated->id]);

        foreach ([$existing, $legacy] as $customer) {
            $attributes = $customer->fresh()->getAttributes();
            $this->post(route('customer.register'), [
                'email' => strtoupper($customer->email),
                'password' => 'Attempted account takeover1!',
                'password_confirmation' => 'Attempted account takeover1!',
                'name' => 'Submitted Name',
            ])->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_sent');
            $this->assertSame($attributes, $customer->fresh()->getAttributes());
        }

        $this->assertSame(3, Customer::query()->count());
        $this->assertSame($unrelated->id, $order->fresh()->customer_id);
        $this->assertNull($legacy->fresh()->password);
        $this->assertSame(0, CustomerAuthToken::query()->count());
        $this->assertSame(0, CustomerAuthMailWork::query()->count());
        $this->assertGuest('customer');
    }

    public function test_registration_validates_password_length_confirmation_and_email_without_flashing_passwords(): void
    {
        foreach ([
            ['password' => 'Abcd1!X', 'confirmation' => 'Abcd1!X'],
            ['password' => str_repeat('b', 126).'A1!', 'confirmation' => str_repeat('b', 126).'A1!'],
            ['password' => 'Valid1!X', 'confirmation' => 'Different1!X'],
            ['password' => 'abcdef1!', 'confirmation' => 'abcdef1!'],
            ['password' => 'Abcdefg!', 'confirmation' => 'Abcdefg!'],
            ['password' => 'Abcdefg1', 'confirmation' => 'Abcdefg1'],
        ] as $input) {
            $this->from(route('customer.account'))->post(route('customer.register'), [
                'email' => 'invalid@example.test',
                'password' => $input['password'],
                'password_confirmation' => $input['confirmation'],
            ])->assertRedirect(route('customer.account'))->assertSessionHasErrors('password');
            $this->assertArrayNotHasKey('password', session()->getOldInput());
            $this->assertArrayNotHasKey('password_confirmation', session()->getOldInput());
        }
        $this->post(route('customer.register'), [
            'email' => 'not-an-email', 'password' => 'Valid1!X',
            'password_confirmation' => 'Valid1!X',
        ])->assertSessionHasErrors('email');

        $this->assertSame(0, Customer::query()->count());
        $this->assertSame(0, CustomerAuthToken::query()->count());
        $this->assertSame(0, CustomerAuthMailWork::query()->count());
    }

    public function test_registration_accepts_eight_and_128_character_passwords_with_required_character_classes(): void
    {
        $this->register('minimum@example.test', 'ABCDE1!X');
        $this->register('maximum@example.test', str_repeat('b', 125).'A1!');

        $this->assertSame(2, Customer::query()->count());
        $this->assertSame(2, CustomerAuthToken::query()->count());
    }

    public function test_long_and_unicode_passwords_authenticate_without_ignoring_characters_after_seventy_two_bytes(): void
    {
        foreach ([
            'long@example.test' => str_repeat('a', 125).'1!X',
            'unicode@example.test' => str_repeat('界', 125).'1!X',
        ] as $email => $password) {
            $verification = $this->register($email, $password);
            $this->post(route('customer.verification.consume'), ['token' => $verification])
                ->assertRedirect(route('customer.orders.index'));
            $this->post(route('customer.logout'))->assertRedirect(route('customer.account'));

            $this->post(route('customer.login'), ['email' => $email, 'password' => substr($password, 0, -1).'Y'])
                ->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_error');
            $this->assertGuest('customer');
            $this->post(route('customer.login'), compact('email', 'password'))
                ->assertRedirect(route('customer.orders.index'));
            $this->assertSame($email, Auth::guard('customer')->user()->email);
            $this->post(route('customer.logout'))->assertRedirect(route('customer.account'));
        }
    }

    public function test_unverified_wrong_password_passwordless_and_unknown_logins_share_the_same_failure(): void
    {
        $verified = $this->verifiedCustomer();
        $pending = Customer::factory()->create(['email' => 'pending@example.test', 'email_verified_at' => null]);
        $pending->forceFill(['password' => 'Correct pending password'])->save();
        $passwordless = Customer::factory()->create(['email' => 'passwordless@example.test', 'email_verified_at' => now()]);
        $error = null;

        foreach ([
            [$verified->email, 'Incorrect password value'],
            [$pending->email, 'Correct pending password'],
            [$passwordless->email, 'Any password value'],
            ['unknown@example.test', 'Any password value'],
        ] as [$email, $password]) {
            $response = $this->post(route('customer.login'), compact('email', 'password'));
            $response->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_error');
            $error ??= session('customer_login_error');
            $this->assertSame($error, session('customer_login_error'));
            $this->assertArrayNotHasKey('password', session()->getOldInput());
            $response->assertDontSee($password, false);
            $this->assertGuest('customer');
            $this->assertGuest('web');
        }
        $this->assertSame(3, Customer::query()->count());
    }

    public function test_password_login_regenerates_customer_session_without_changing_admin_identity(): void
    {
        $customer = $this->verifiedCustomer();
        $admin = User::factory()->create();
        $this->actingAs($admin, 'web')->get(route('customer.account'))->assertOk();
        $sessionId = session()->getId();
        $csrfToken = session()->token();

        $this->withCookie(config('session.cookie'), $sessionId)->post(route('customer.login'), ['email' => ' OWNER@EXAMPLE.TEST ', 'password' => 'Original customer password'])
            ->assertRedirect(route('customer.orders.index'));

        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertAuthenticatedAs($admin, 'web');
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertNotSame($csrfToken, session()->token());
        $this->assertSame($customer->auth_version, session('customer_auth_version'));
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->get(route('customer.orders.index'))->assertOk();
    }

    public function test_password_spaces_and_case_are_significant_at_registration_login_and_reset(): void
    {
        $password = '  My spaced password1!  ';
        $token = $this->register('spaces@example.test', $password);
        $this->post(route('customer.verification.consume'), ['token' => $token])
            ->assertRedirect(route('customer.orders.index'));
        $this->post(route('customer.logout'))->assertRedirect(route('customer.account'));

        foreach ([trim($password), strtolower($password)] as $wrong) {
            $this->post(route('customer.login'), ['email' => 'spaces@example.test', 'password' => $wrong])
                ->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_error');
            $this->assertGuest('customer');
        }
        $this->post(route('customer.login'), ['email' => 'spaces@example.test', 'password' => $password])
            ->assertRedirect(route('customer.orders.index'));

        $reset = $this->requestReset('spaces@example.test');
        $replacement = '  Reset with spaces1!  ';
        $this->consumeReset($reset, $replacement)->assertRedirect(route('customer.account'));
        $customer = Customer::query()->where('email', 'spaces@example.test')->sole();
        $this->assertTrue($this->passwordMatches($replacement, $customer));
        $this->assertFalse($this->passwordMatches(trim($replacement), $customer));
        $this->assertGuest('customer');
        $this->post(route('customer.login'), ['email' => $customer->email, 'password' => $replacement])
            ->assertRedirect(route('customer.orders.index'));
    }

    public function test_verification_expires_at_the_thirty_minute_boundary(): void
    {
        $token = $this->register('expired@example.test');
        $challenge = CustomerAuthToken::query()->sole();
        $this->travelTo($challenge->expires_at);

        $this->post(route('customer.verification.consume'), ['token' => $token])
            ->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_error');

        $customer = Customer::query()->sole();
        $this->assertNull($customer->email_verified_at);
        $this->assertNull($customer->password);
        $this->assertGuest('customer');
    }

    public function test_unknown_reset_request_is_generic_and_does_not_create_customer_or_mail(): void
    {
        $customer = $this->verifiedCustomer();
        $this->post(route('customer.password-reset.request'), ['email' => 'unknown@example.test'])
            ->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_sent');
        $unknownGuidance = session('customer_login_sent');
        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(0, CustomerAuthToken::query()->count());
        $this->assertSame(0, CustomerAuthMailWork::query()->count());

        $this->requestReset($customer->email);
        $this->assertSame($unknownGuidance, session('customer_login_sent'));
        $this->assertSame(1, Customer::query()->count());
    }

    public function test_reset_get_is_inert_and_post_changes_password_but_requires_a_new_login(): void
    {
        $customer = $this->verifiedCustomer();
        $this->post(route('customer.login'), ['email' => $customer->email, 'password' => 'Original customer password'])
            ->assertRedirect(route('customer.orders.index'));
        $token = $this->requestReset($customer->email);
        $challenge = CustomerAuthToken::query()->where('token_hash', hash('sha256', $token))->sole();
        $passwordHash = $customer->fresh()->password;

        $this->get(route('customer.password-reset.show', ['token' => $token]))
            ->assertOk()->assertDontSee($token, false);
        $this->assertNull($challenge->fresh()->consumed_at);
        $this->assertSame($passwordHash, $customer->fresh()->password);
        $this->assertAuthenticatedAs($customer, 'customer');
        $sessionId = session()->getId();
        $csrfToken = session()->token();

        $this->withCookie(config('session.cookie'), $sessionId);
        $this->consumeReset($token)->assertRedirect(route('customer.account'));

        $this->assertGuest('customer');
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertNotSame($csrfToken, session()->token());
        $this->assertNotNull($challenge->fresh()->consumed_at);
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->assertTrue($this->passwordMatches('Replacement customer password1!', $customer->fresh()));
        $this->get(route('customer.orders.index'))->assertRedirect(route('customer.account'));
        $this->post(route('customer.login'), ['email' => $customer->email, 'password' => 'Original customer password'])
            ->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_error');
        $this->post(route('customer.login'), ['email' => $customer->email, 'password' => 'Replacement customer password1!'])
            ->assertRedirect(route('customer.orders.index'));
    }

    public function test_reset_token_cannot_be_replayed_or_consume_a_verification_token(): void
    {
        $customer = $this->verifiedCustomer();
        $verification = $this->register('pending@example.test');
        $reset = $this->requestReset($customer->email);

        $this->consumeReset($verification)->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_error');
        $this->post(route('customer.verification.consume'), ['token' => $reset])
            ->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_error');
        $this->assertTrue($this->passwordMatches('Original customer password', $customer->fresh()));
        $this->assertGuest('customer');

        $this->consumeReset($reset)->assertRedirect(route('customer.account'));
        $hash = $customer->fresh()->password;
        $this->consumeReset($reset, 'Replay replacement password1!')
            ->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_error');
        $this->assertSame($hash, $customer->fresh()->password);
        $this->assertGuest('customer');

        $this->post(route('customer.verification.consume'), ['token' => $verification])
            ->assertRedirect(route('customer.orders.index'));
        $this->assertSame('pending@example.test', Auth::guard('customer')->user()->email);
    }

    public function test_reset_token_expires_at_the_thirty_minute_boundary(): void
    {
        $customer = $this->verifiedCustomer();
        $hash = $customer->password;
        $token = $this->requestReset($customer->email);
        $this->travelTo(CustomerAuthToken::query()->sole()->expires_at);

        $this->consumeReset($token)->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_error');

        $this->assertSame($hash, $customer->fresh()->password);
        $this->assertSame(0, $customer->fresh()->auth_version);
        $this->assertGuest('customer');
    }

    public function test_reset_validation_does_not_consume_token_or_flash_sensitive_old_input(): void
    {
        $customer = $this->verifiedCustomer();
        $token = $this->requestReset($customer->email);

        foreach ([
            ['Abcd1!X', 'Abcd1!X'],
            [str_repeat('b', 126).'A1!', str_repeat('b', 126).'A1!'],
            ['Valid1!X', 'Mismatched1!X'],
            ['abcdef1!', 'abcdef1!'],
            ['Abcdefg!', 'Abcdefg!'],
            ['Abcdefg1', 'Abcdefg1'],
        ] as [$password, $confirmation]) {
            $this->post(route('customer.password-reset.consume'), [
                'token' => $token, 'password' => $password, 'password_confirmation' => $confirmation,
            ])->assertSessionHasErrors('password');
            foreach (['password', 'password_confirmation', 'token'] as $key) {
                $this->assertArrayNotHasKey($key, session()->getOldInput());
            }
            $this->assertNull(CustomerAuthToken::query()->sole()->consumed_at);
        }
        $this->consumeReset($token, 'ABCDE1!X')->assertRedirect(route('customer.account'));
        $this->assertTrue($this->passwordMatches('ABCDE1!X', $customer->fresh()));
    }

    public function test_malformed_and_unknown_tokens_fail_without_creating_or_authenticating_customers(): void
    {
        foreach (['not-a-token', str_repeat('f', 64)] as $token) {
            $this->post(route('customer.verification.consume'), ['token' => $token])
                ->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_error');
            $this->consumeReset($token)->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_error');
            $this->assertGuest('customer');
        }
        $this->assertSame(0, Customer::query()->count());
    }

    public function test_reset_revokes_other_customer_sessions_and_all_outstanding_links_but_not_admin(): void
    {
        $customer = $this->verifiedCustomer();
        $admin = User::factory()->create();
        $this->actingAs($admin, 'web');
        $this->post(route('customer.login'), ['email' => $customer->email, 'password' => 'Original customer password'])
            ->assertRedirect(route('customer.orders.index'));
        $otherSession = session()->all();
        $oldVersion = $customer->auth_version;
        $firstReset = $this->requestReset($customer->email);
        $secondReset = $this->requestReset($customer->email);
        $legacyToken = str_repeat('a', 64);
        CustomerLoginLink::create([
            'email' => $customer->email, 'token_hash' => hash('sha256', $legacyToken), 'expires_at' => now()->addMinutes(15),
        ]);

        $this->consumeReset($secondReset)->assertRedirect(route('customer.account'));

        $this->assertSame($oldVersion + 1, $customer->fresh()->auth_version);
        $this->assertGuest('customer');
        $this->assertAuthenticatedAs($admin, 'web');
        $this->consumeReset($firstReset, 'Revoked reset password1!')
            ->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_error');
        $this->post(route('customer.login-link.consume'), ['token' => $legacyToken])
            ->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_error');
        $this->assertGuest('customer');

        $this->withSession($otherSession);
        Auth::forgetGuards();
        $this->get(route('customer.orders.index'))->assertRedirect(route('customer.account'));
        $this->assertGuest('customer');
    }

    public function test_stale_registration_verification_cannot_install_credentials_after_legacy_recovery_and_reset(): void
    {
        $verification = $this->register('recovered@example.test', 'Stale enrollment password1!');
        $customer = Customer::query()->where('email', 'recovered@example.test')->sole();
        $enrollmentVersion = $customer->enrollment_version;
        $this->loginWithLegacyLink($customer);
        $reset = $this->requestReset($customer->email);
        $this->consumeReset($reset, 'Recovered customer password1!')->assertRedirect(route('customer.account'));
        $hash = $customer->fresh()->password;
        $this->assertGreaterThan($enrollmentVersion, $customer->fresh()->enrollment_version);

        $this->post(route('customer.verification.consume'), ['token' => $verification])
            ->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_error');

        $this->assertSame($hash, $customer->fresh()->password);
        $this->assertTrue($this->passwordMatches('Recovered customer password1!', $customer->fresh()));
        $this->assertFalse($this->passwordMatches('Stale enrollment password1!', $customer->fresh()));
        $this->assertGuest('customer');
        $this->post(route('customer.login'), ['email' => $customer->email, 'password' => 'Recovered customer password1!'])
            ->assertRedirect(route('customer.orders.index'));
    }

    public function test_legacy_verified_customer_can_install_first_password_only_after_fresh_authentication(): void
    {
        $customer = Customer::factory()->create(['email' => 'legacy@example.test', 'email_verified_at' => now()]);
        $this->loginWithLegacyLink($customer);
        $version = $customer->fresh()->auth_version;
        $sessionId = session()->getId();

        $this->post(route('customer.password.enroll'), [
            'password' => 'First legacy customer password1!',
            'password_confirmation' => 'First legacy customer password1!',
        ])->assertRedirect(route('customer.security'))->assertSessionHas('customer_login_sent');

        $customer = $customer->fresh();
        $this->assertTrue($this->passwordMatches('First legacy customer password1!', $customer));
        $this->assertSame($version + 1, $customer->auth_version);
        $this->assertSame($customer->auth_version, session('customer_auth_version'));
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertAuthenticatedAs($customer, 'customer');
        $hash = $customer->password;

        $this->post(route('customer.password.enroll'), [
            'password' => 'Unwanted replacement password1!',
            'password_confirmation' => 'Unwanted replacement password1!',
        ])->assertRedirect(route('customer.security'))->assertSessionHas('customer_login_error');
        $this->assertSame($hash, $customer->fresh()->password);
    }

    public function test_old_or_fixture_only_customer_session_cannot_install_a_first_password(): void
    {
        $customer = Customer::factory()->create(['email' => 'legacy@example.test', 'email_verified_at' => now()]);
        $this->loginWithLegacyLink($customer);
        $this->travel(301)->seconds();
        $input = ['password' => 'First legacy customer password1!', 'password_confirmation' => 'First legacy customer password1!'];

        $this->post(route('customer.password.enroll'), $input)
            ->assertRedirect(route('customer.security'))->assertSessionHas('customer_login_error');
        $this->assertNull($customer->fresh()->password);

        session()->forget('customer_authenticated_at');
        $this->actingAs($customer->fresh(), 'customer')->post(route('customer.password.enroll'), $input)
            ->assertRedirect(route('customer.security'))->assertSessionHas('customer_login_error');
        $this->assertNull($customer->fresh()->password);
        $this->post(route('customer.logout'));
        $this->post(route('customer.password.enroll'), $input)->assertRedirect(route('customer.account'));
        $this->assertNull($customer->fresh()->password);
    }

    public function test_only_verified_version_zero_legacy_sessions_may_omit_the_auth_version(): void
    {
        $customer = $this->verifiedCustomer();
        session()->forget('customer_auth_version');
        $this->actingAs($customer, 'customer')->get(route('customer.orders.index'))->assertOk();

        $customer->forceFill(['auth_version' => 1])->save();
        session()->forget('customer_auth_version');
        $this->actingAs($customer->fresh(), 'customer')->get(route('customer.orders.index'))
            ->assertRedirect(route('customer.account'));
        $this->assertGuest('customer');

        $unverified = Customer::factory()->create(['email' => 'unverified@example.test', 'email_verified_at' => null]);
        session()->forget('customer_auth_version');
        $this->actingAs($unverified, 'customer')->get(route('customer.orders.index'))
            ->assertRedirect(route('customer.account'));
        $this->assertGuest('customer');
    }

    public function test_customer_logout_regenerates_the_session_and_preserves_admin_login(): void
    {
        $customer = $this->verifiedCustomer();
        $admin = User::factory()->create();
        $this->actingAs($admin, 'web');
        $this->post(route('customer.login'), ['email' => $customer->email, 'password' => 'Original customer password'])
            ->assertRedirect(route('customer.orders.index'));
        $sessionId = session()->getId();
        $csrfToken = session()->token();

        $this->withCookie(config('session.cookie'), $sessionId)->post(route('customer.logout'))->assertRedirect(route('customer.account'));

        $this->assertGuest('customer');
        $this->assertAuthenticatedAs($admin, 'web');
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertSame('', session()->getHandler()->read($sessionId), 'The old session ID must no longer grant account access.');
        $this->assertNotSame($csrfToken, session()->token());
        $this->assertNull(session('customer_auth_version'));
    }

    public function test_reset_preserves_order_recovery_key_and_telegram_ownership(): void
    {
        $customer = $this->verifiedCustomer();
        $other = Customer::factory()->create(['email' => null]);
        $order = Order::factory()->create([
            'customer_id' => $customer->id, 'order_status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Paid,
        ]);
        Payment::factory()->create(['order_id' => $order->id, 'status' => PaymentStatus::Paid, 'paid_at' => now()]);
        $product = Product::factory()->create();
        $item = OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id]);
        ProductStockItem::create([
            'product_id' => $product->id, 'order_item_id' => $item->id, 'secret' => 'paid@example.test|PaidCredential123',
        ]);
        $foreignOrder = Order::factory()->create(['customer_id' => $other->id]);
        $key = app(StorefrontOrderAccess::class)->issue($order);
        $orderAttributes = $order->fresh()->getAttributes();
        $telegramId = $customer->telegram_id;
        $token = $this->requestReset($customer->email);

        $this->consumeReset($token)->assertRedirect(route('customer.account'));

        $this->assertSame($orderAttributes, $order->fresh()->getAttributes());
        $this->assertSame($telegramId, $customer->fresh()->telegram_id);
        $this->assertSame($other->id, $foreignOrder->fresh()->customer_id);
        $this->assertSame(2, Customer::query()->count());
        $this->post(route('storefront.order-access.store'), ['invoice_number' => $order->invoice_number, 'access_key' => $key])
            ->assertRedirect(route('storefront.order-access.show', $order));
        $this->get(route('storefront.order-access.show', $order))
            ->assertOk()->assertSee('PaidCredential123', false);
        $this->assertGuest('customer');
        $this->post(route('customer.login'), ['email' => $customer->email, 'password' => 'Replacement customer password1!'])
            ->assertRedirect(route('customer.orders.index'));
        $this->get(route('customer.orders.index'))->assertOk()->assertSee($order->invoice_number)->assertDontSee($foreignOrder->invoice_number);
    }

    public function test_auth_mail_has_fragment_only_links_and_no_plaintext_secrets_at_rest_or_in_responses(): void
    {
        $password = 'Secret pending password value1!';
        $token = $this->register('private@example.test', $password);
        $work = CustomerAuthMailWork::query()->sole();
        $challenge = CustomerAuthToken::query()->sole();
        $customer = Customer::query()->sole();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->assertSame(hash('sha256', $token), $challenge->token_hash);
        foreach ([$work->getAttributes(), $challenge->getAttributes(), $customer->getAttributes()] as $record) {
            $stored = json_encode($record, JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString($token, $stored);
            $this->assertStringNotContainsString($password, $stored);
        }
        $this->assertStringNotContainsString('private@example.test', json_encode($work->getAttributes(), JSON_THROW_ON_ERROR));
        $customer->load('authTokens');
        foreach ([$customer->toJson(), $challenge->toJson(), $work->toJson()] as $serialized) {
            $this->assertStringNotContainsString($token, $serialized);
            $this->assertStringNotContainsString($password, $serialized);
            $this->assertStringNotContainsString($challenge->proposed_password_hash, $serialized);
            $this->assertStringNotContainsString($challenge->token_hash, $serialized);
        }
        foreach (['customer.account', 'customer.verification.show', 'customer.password-reset.show'] as $route) {
            $response = $this->get(route($route))->assertOk();
            $response->assertDontSee($token, false)->assertDontSee($password, false)
                ->assertDontSee($challenge->proposed_password_hash, false);
        }

        app(CustomerAuthMailDeliveryService::class)->deliver($work->id);
        Notification::assertSentOnDemand(CustomerAuthMailNotification::class, function (CustomerAuthMailNotification $notification) use ($token): bool {
            $url = (string) $notification->toMail((object) [])->actionUrl;

            return $url === route('customer.verification.show').'#token='.$token
                && parse_url($url, PHP_URL_QUERY) === null;
        });

        $customer = $this->verifiedCustomer('reset-mail@example.test');
        $reset = $this->requestReset($customer->email);
        $resetWork = CustomerAuthMailWork::query()->whereHas('authToken', fn ($query) => $query->where('purpose', CustomerAuthTokenPurpose::PasswordReset->value))->sole();
        app(CustomerAuthMailDeliveryService::class)->deliver($resetWork->id);
        Notification::assertSentOnDemand(CustomerAuthMailNotification::class, function (CustomerAuthMailNotification $notification) use ($reset): bool {
            return (string) $notification->toMail((object) [])->actionUrl === route('customer.password-reset.show').'#token='.$reset;
        });
        $this->assertStringNotContainsString($reset, json_encode($resetWork->getAttributes(), JSON_THROW_ON_ERROR));
    }

    public function test_password_failure_limit_blocks_correct_login_until_window_expires(): void
    {
        $customer = $this->verifiedCustomer();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('customer.login'), ['email' => $customer->email, 'password' => 'Wrong customer password'])
                ->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_error');
        }
        $this->post(route('customer.login'), ['email' => strtoupper($customer->email), 'password' => 'Original customer password'])
            ->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_error');
        $this->assertGuest('customer');

        $this->travel(15)->minutes();
        $this->post(route('customer.login'), ['email' => $customer->email, 'password' => 'Original customer password'])
            ->assertRedirect(route('customer.orders.index'));
        $this->assertAuthenticatedAs($customer, 'customer');
    }

    public function test_registration_and_reset_share_email_cooldown_and_delivery_window(): void
    {
        $customer = $this->verifiedCustomer();
        $first = $this->requestReset($customer->email);
        $firstChallenge = CustomerAuthToken::query()->sole();
        $this->post(route('customer.password-reset.request'), ['email' => strtoupper($customer->email)])
            ->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_sent');
        $this->assertSame($firstChallenge->id, CustomerAuthToken::query()->sole()->id);
        $this->assertNull($firstChallenge->fresh()->consumed_at);

        $this->travel(61)->seconds();
        $this->post(route('customer.register'), [
            'email' => $customer->email, 'password' => 'Unwanted customer password1!',
            'password_confirmation' => 'Unwanted customer password1!',
        ])->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_sent');
        $this->travel(61)->seconds();
        $this->post(route('customer.password-reset.request'), ['email' => $customer->email])
            ->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_sent');
        $replacement = $this->issuedToken($customer->email, CustomerAuthTokenPurpose::PasswordReset);
        $this->assertNotSame($first, $replacement);
        $this->assertNotNull($firstChallenge->fresh()->consumed_at);

        $this->travel(61)->seconds();
        $this->post(route('customer.password-reset.request'), ['email' => $customer->email])
            ->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_sent');
        $this->assertSame($replacement, $this->issuedToken($customer->email, CustomerAuthTokenPurpose::PasswordReset));
        $this->consumeReset($replacement)->assertRedirect(route('customer.account'));
        $this->assertTrue($this->passwordMatches('Replacement customer password1!', $customer->fresh()));
    }

    public function test_missing_rate_storage_fails_closed_without_creating_authentication_work(): void
    {
        $customer = $this->verifiedCustomer();
        config(['cache.limiter' => 'missing-auth-cache-store']);
        $this->post(route('customer.login'), ['email' => $customer->email, 'password' => 'Original customer password'])
            ->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_error');
        $this->post(route('customer.password-reset.request'), ['email' => $customer->email])
            ->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_sent');
        $this->post(route('customer.register'), [
            'email' => 'new-unavailable@example.test', 'password' => 'New customer password1!',
            'password_confirmation' => 'New customer password1!',
        ])->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_sent');
        $this->assertGuest('customer');
        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(0, CustomerAuthToken::query()->count());
        $this->assertSame(0, CustomerAuthMailWork::query()->count());
    }

    public function test_lost_queue_wakeup_recovers_the_same_verification_grant_and_clears_delivery_secret(): void
    {
        $token = $this->register('lost-wakeup@example.test');
        $work = CustomerAuthMailWork::query()->sole();
        Notification::assertNothingSent();

        Queue::swap(Queue::getFacadeRoot()->queue);
        config(['queue.default' => 'sync']);
        $this->artisan('customer-auth:recover-mail')->assertSuccessful();

        $this->assertSame('sent', $work->fresh()->status);
        $this->assertNull($work->fresh()->payload);
        Notification::assertSentOnDemand(CustomerAuthMailNotification::class, function (CustomerAuthMailNotification $notification) use ($token): bool {
            return $notification->toMail((object) [])->actionUrl === route('customer.verification.show').'#token='.$token;
        });
        $this->post(route('customer.verification.consume'), ['token' => $token])
            ->assertRedirect(route('customer.orders.index'));
        $this->assertAuthenticated('customer');
        $this->assertSame(1, CustomerAuthToken::query()->count());
    }

    public function test_expired_delivery_work_is_not_sent_after_queue_loss(): void
    {
        $token = $this->register('expired-mail@example.test');
        $work = CustomerAuthMailWork::query()->sole();
        $this->travelTo($work->expires_at);
        Queue::swap(Queue::getFacadeRoot()->queue);
        config(['queue.default' => 'sync']);
        $this->artisan('customer-auth:recover-mail')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertSame('cancelled', $work->fresh()->status);
        $this->assertNull($work->fresh()->payload);
        $this->post(route('customer.verification.consume'), ['token' => $token])
            ->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_error');
        $this->assertGuest('customer');
    }

    private function passwordMatches(string $password, Customer $customer): bool
    {
        return app(CustomerPasswordHasher::class)->check($password, $customer->password, $customer->password_scheme);
    }

    private function verifiedCustomer(string $email = 'owner@example.test'): Customer
    {
        $customer = Customer::factory()->create(['email' => $email, 'email_verified_at' => now()]);
        $customer->forceFill(['password' => 'Original customer password'])->save();

        return $customer->fresh();
    }

    private function register(string $email, string $password = 'Pending customer password1!', ?string $name = null): string
    {
        $this->post(route('customer.register'), [
            'email' => $email, 'password' => $password, 'password_confirmation' => $password, 'name' => $name,
        ])->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_sent');

        return $this->issuedToken(strtolower(trim($email)), CustomerAuthTokenPurpose::EmailVerification);
    }

    private function requestReset(string $email): string
    {
        $this->travel(61)->seconds();
        $this->post(route('customer.password-reset.request'), ['email' => $email])
            ->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_sent');

        return $this->issuedToken(strtolower(trim($email)), CustomerAuthTokenPurpose::PasswordReset);
    }

    private function issuedToken(string $email, CustomerAuthTokenPurpose $purpose): string
    {
        $challenge = CustomerAuthToken::query()->where('email', $email)->where('purpose', $purpose->value)->orderByDesc('id')->firstOrFail();
        $work = CustomerAuthMailWork::query()->where('auth_token_id', $challenge->id)->sole();

        return $work->payload['token'];
    }

    private function loginWithLegacyLink(Customer $customer): void
    {
        $token = bin2hex(random_bytes(32));
        CustomerLoginLink::create([
            'email' => $customer->email, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(15),
        ]);
        $this->post(route('customer.login-link.consume'), ['token' => $token])
            ->assertRedirect(route('customer.orders.index'));
        $this->assertAuthenticatedAs($customer, 'customer');
    }

    private function consumeReset(string $token, string $password = 'Replacement customer password1!'): TestResponse
    {
        return $this->post(route('customer.password-reset.consume'), [
            'token' => $token, 'password' => $password, 'password_confirmation' => $password,
        ]);
    }
}
