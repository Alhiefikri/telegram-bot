<?php

namespace Tests\Feature;

use App\Enums\CustomerAuthTokenPurpose;
use App\Enums\CustomerIdentityProvider;
use App\Models\Customer;
use App\Models\CustomerAuthToken;
use App\Models\CustomerIdentity;
use App\Models\CustomerLoginLink;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Tests\TestCase;

class CustomerAuthContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_identity_and_token_authority_cannot_be_mass_assigned(): void
    {
        foreach ([new CustomerIdentity, new CustomerAuthToken] as $record) {
            try {
                $record->fill(['customer_id' => 1, 'purpose' => 'password_reset', 'provider' => 'google', 'token_hash' => 'untrusted', 'consumed_at' => now(), 'attempts' => 0]);
                $this->fail('Auth authority accepted mass assignment.');
            } catch (MassAssignmentException) {
                $this->assertSame([], $record->getAttributes());
            }
        }
    }

    public function test_legacy_customers_need_neither_email_nor_password_and_security_fields_are_guarded(): void
    {
        $telegram = Customer::factory()->create(['email' => null]);
        $guest = Customer::factory()->create(['telegram_id' => null, 'email' => null]);
        foreach ([$telegram, $guest] as $customer) {
            $this->assertNull($customer->fresh()->password);
            $customer->fill(['password' => 'attacker password', 'remember_token' => 'attacker', 'auth_version' => 99, 'enrollment_version' => 99]);
            $customer->save();
            $this->assertNull($customer->fresh()->password);
            $this->assertNull($customer->fresh()->remember_token);
            $this->assertSame(0, $customer->fresh()->auth_version);
            $this->assertSame(0, $customer->fresh()->enrollment_version);
        }
    }

    public function test_password_hash_and_eager_loaded_auth_material_are_not_serialized(): void
    {
        $customer = Customer::factory()->create();
        $customer->forceFill(['password' => 'correct horse battery staple', 'remember_token' => 'private'])->save();
        $customer = $customer->fresh();
        $this->assertTrue(Hash::check('correct horse battery staple', $customer->getAuthPassword()));
        $this->assertNotSame('correct horse battery staple', $customer->getRawOriginal('password'));
        $identity = (new CustomerIdentity)->forceFill(['customer_id' => $customer->id, 'provider' => CustomerIdentityProvider::Google, 'provider_subject' => 'private-subject', 'provider_email' => 'private@example.test']);
        $identity->save();
        $token = $this->token($customer);
        $customer->load(['identities', 'authTokens']);
        foreach (['password', 'remember_token', 'auth_version', 'enrollment_version', 'identities', 'auth_tokens'] as $key) {
            $this->assertArrayNotHasKey($key, $customer->toArray());
        }
        foreach (['token_hash', 'email', 'challenge_hash', 'session_binding_hash', 'proposed_password_hash', 'auth_version', 'enrollment_version'] as $key) {
            $this->assertArrayNotHasKey($key, $token->toArray());
        }
        $this->assertArrayNotHasKey('provider_subject', $identity->toArray());
        $this->assertArrayNotHasKey('provider_email', $identity->toArray());
        $link = CustomerLoginLink::create(['email' => 'private@example.test', 'token_hash' => hash('sha256', 'legacy'), 'expires_at' => now()->addMinutes(15), 'requested_ip' => '192.0.2.1']);
        foreach (['email', 'token_hash', 'requested_ip'] as $key) {
            $this->assertArrayNotHasKey($key, $link->toArray());
        }
    }

    public function test_customer_credentials_cannot_authenticate_the_admin_provider(): void
    {
        $customer = Customer::factory()->create(['email' => 'buyer@example.test']);
        $customer->forceFill(['password' => 'correct horse battery staple'])->save();
        $credentials = ['email' => $customer->email, 'password' => 'correct horse battery staple'];
        $customerProvider = Auth::guard('customer')->getProvider();
        $this->assertInstanceOf(Customer::class, $customerProvider->retrieveByCredentials($credentials));
        $this->assertTrue($customerProvider->validateCredentials($customer, $credentials));
        $this->assertNull(Auth::guard('web')->getProvider()->retrieveByCredentials($credentials));
        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $this->assertNull($customerProvider->retrieveByCredentials(['email' => $admin->email]));
        Auth::guard('customer')->login($customer);
        $this->assertTrue(Auth::guard('customer')->check());
        $this->assertFalse(Auth::guard('web')->check());
    }

    public function test_token_purpose_expiry_consumption_and_durable_attempts_bound_eligibility(): void
    {
        $this->freezeTime();
        $token = $this->token(Customer::factory()->create());
        $this->assertTrue($token->isActiveFor(CustomerAuthTokenPurpose::PasswordReset));
        $this->assertFalse($token->isActiveFor(CustomerAuthTokenPurpose::EmailVerification));
        $this->travel(30)->minutes();
        $this->assertFalse($token->isActiveFor(CustomerAuthTokenPurpose::PasswordReset));
        $this->travelBack();
        $token->consumed_at = now();
        $token->save();
        $this->assertFalse($token->fresh()->isActiveFor(CustomerAuthTokenPurpose::PasswordReset));
        $otp = $this->token(Customer::factory()->create(), CustomerAuthTokenPurpose::EmailOtp);
        $otp->attempts = 4;
        $otp->save();
        $this->assertTrue($otp->fresh()->isActiveFor(CustomerAuthTokenPurpose::EmailOtp));
        $otp->attempts = 5;
        $otp->save();
        $this->assertFalse($otp->fresh()->isActiveFor(CustomerAuthTokenPurpose::EmailOtp));
    }

    public function test_identity_binding_cannot_be_reassigned_through_model_updates(): void
    {
        $identity = (new CustomerIdentity)->forceFill(['customer_id' => Customer::factory()->create()->id, 'provider' => CustomerIdentityProvider::Google, 'provider_subject' => 'subject']);
        $identity->save();
        $identity->customer_id = Customer::factory()->create()->id;
        $this->expectException(LogicException::class);
        $identity->save();
    }

    public function test_token_binding_cannot_change_purpose_after_issuance(): void
    {
        $token = $this->token(Customer::factory()->create());
        $token->purpose = CustomerAuthTokenPurpose::EmailVerification;
        $this->expectException(LogicException::class);
        $token->save();
    }

    public function test_verified_email_uniqueness_preserves_nullable_legacy_accounts(): void
    {
        Customer::factory()->count(2)->create(['email' => null]);
        Customer::factory()->create(['email' => 'unique@example.test']);
        $this->expectException(QueryException::class);
        Customer::factory()->create(['email' => 'unique@example.test']);
    }

    private function token(Customer $customer, CustomerAuthTokenPurpose $purpose = CustomerAuthTokenPurpose::PasswordReset): CustomerAuthToken
    {
        $token = (new CustomerAuthToken)->forceFill([
            'customer_id' => $customer->id,
            'email' => 'private@example.test',
            'purpose' => $purpose,
            'token_hash' => hash_hmac('sha256', bin2hex(random_bytes(32)), 'disposable-test-key'),
            'challenge_hash' => hash('sha256', random_bytes(32)),
            'session_binding_hash' => hash('sha256', random_bytes(32)),
            'proposed_password_hash' => Hash::make('pending private password'),
            'auth_version' => 0,
            'enrollment_version' => 0,
            'expires_at' => now()->addSeconds($purpose->ttlSeconds()),
        ]);
        $token->save();

        return $token;
    }
}
