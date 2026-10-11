<?php

namespace Tests\Feature;

use App\Enums\CustomerAuthTokenPurpose;
use App\Models\Customer;
use App\Models\CustomerAuthSetting;
use App\Models\CustomerAuthToken;
use App\Models\CustomerIdentity;
use App\Models\CustomerLoginLink;
use App\Models\Order;
use App\Models\User;
use App\Services\Customer\CustomerAuthAvailability;
use App\Services\Customer\CustomerAuthenticationService;
use App\Storefront\StorefrontOrderAccess;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CustomerAuthOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_rollback_and_restore_only_change_availability_and_preserve_legacy_authentication(): void
    {
        Queue::fake();
        $customer = $this->customer();
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        $key = app(StorefrontOrderAccess::class)->issue($order);
        (new CustomerIdentity)->forceFill(['customer_id' => $customer->id, 'provider' => 'google', 'provider_subject' => 'disposable-subject'])->save();
        $plain = bin2hex(random_bytes(32));
        $link = $this->link($customer, $plain);
        $token = $this->token($customer);
        $this->work('auth_token_id', $token->id);
        $tables = ['customers', 'orders', 'customer_identities', 'customer_auth_tokens', 'customer_login_links', 'customer_auth_mail_works'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->get()->toArray();
        }
        $gate = app(CustomerAuthAvailability::class);
        $this->assertTrue($gate->enabled());
        $this->artisan('customer-auth:availability', ['mode' => 'rollback'])->assertFailed();
        $this->assertTrue($gate->enabled());
        $this->artisan('customer-auth:availability', ['mode' => 'rollback', '--confirm' => true])->assertSuccessful();
        $this->assertFalse($gate->enabled());
        foreach ($tables as $table) {
            $this->assertEquals($before[$table], DB::table($table)->get()->toArray());
        }
        $this->artisan('customer-auth:availability', ['mode' => 'enabled', '--confirm' => true])->assertSuccessful();
        $this->assertTrue($gate->enabled());
        $this->assertDatabaseCount('customer_auth_settings', 1);
        $gate->setEnabled(false);
        $request = Request::create('/akun/link', 'POST');
        $request->setLaravelSession(session()->driver());
        $authenticated = app(CustomerAuthenticationService::class)->consume($plain, $request);
        $this->assertSame($customer->id, $authenticated?->id);
        $this->assertNotNull($link->fresh()->used_at);
        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertTrue(app(StorefrontOrderAccess::class)->verifyKey($order->fresh(), $key));
    }

    public function test_missing_availability_row_fails_closed_and_explicit_restore_repairs_it(): void
    {
        CustomerAuthSetting::query()->delete();
        $gate = app(CustomerAuthAvailability::class);
        $this->assertFalse($gate->enabled());
        $gate->setEnabled(true);
        $this->assertTrue($gate->enabled());
        $this->assertDatabaseCount('customer_auth_settings', 1);
        $this->artisan('customer-auth:availability', ['mode' => 'unknown', '--confirm' => true])->assertFailed();
        $this->assertTrue($gate->enabled());
    }

    public function test_revocation_is_atomic_account_scoped_and_erases_pending_mail_without_deleting_history(): void
    {
        $this->freezeTime();
        $customer = $this->customer();
        $other = $this->customer();
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        $key = app(StorefrontOrderAccess::class)->issue($order);
        $identity = new CustomerIdentity;
        $identity->forceFill(['customer_id' => $customer->id, 'provider' => 'google', 'provider_subject' => 'preserved-subject'])->save();
        $token = $this->token($customer);
        $emailOnly = $this->token($customer, ['customer_id' => null, 'auth_version' => null, 'enrollment_version' => null]);
        $foreignToken = $this->token($other);
        $link = $this->link($customer);
        $foreignLink = $this->link($other);
        $this->work('auth_token_id', $token->id);
        $this->work('auth_token_id', $emailOnly->id);
        $this->work('login_link_id', $link->id);
        $foreignWork = $this->work('auth_token_id', $foreignToken->id);
        $terminalToken = $this->token($customer, ['consumed_at' => now()->subSecond()]);
        $terminalWork = $this->work('auth_token_id', $terminalToken->id, 'sent', null);
        $telegram = $this->telegramClaim($customer);
        $google = $this->googleClaim($customer);
        $expiredGoogle = $this->googleClaim($customer, [
            'expires_at' => now(), 'created_at' => now()->subMinutes(5), 'authenticated_at' => now()->subMinutes(5)->subSecond(),
        ]);
        $foreignGoogle = $this->googleClaim($other);
        $beforeOrder = $order->fresh()->getRawOriginal();
        $beforeIdentity = $identity->fresh()->getRawOriginal();
        $this->actingAs(User::factory()->create(), 'web');
        $this->actingAs($customer, 'customer')->withSession(['customer_auth_version' => 0, 'customer_authenticated_at' => now()->timestamp]);

        $this->artisan('customer-auth:revoke', ['customer' => $customer->id])->assertFailed();
        $this->assertSame(0, $customer->fresh()->auth_version);
        $this->artisan('customer-auth:revoke', ['customer' => $customer->id, '--confirm' => true])
            ->doesntExpectOutputToContain($customer->email)->doesntExpectOutputToContain('secret-ciphertext')
            ->doesntExpectOutputToContain('preserved-subject')->assertSuccessful();

        $this->assertSame(1, $customer->fresh()->auth_version);
        $this->assertSame(1, $customer->fresh()->enrollment_version);
        foreach ([$token, $emailOnly] as $grant) {
            $this->assertNotNull($grant->fresh()->consumed_at);
        }
        $this->assertNotNull($link->fresh()->used_at);
        $this->assertDatabaseHas('customer_telegram_auth_claims', ['id' => $telegram, 'failure' => 'invalid', 'consumed_at' => null]);
        $this->assertDatabaseHas('customer_google_auth_claims', ['id' => $google, 'consumed_at' => now()]);
        $this->assertDatabaseHas('customer_google_auth_claims', ['id' => $expiredGoogle, 'consumed_at' => null]);
        $this->assertDatabaseHas('customer_google_auth_claims', ['id' => $foreignGoogle, 'consumed_at' => null]);
        $this->assertSame(3, DB::table('customer_auth_mail_works')->where('status', 'cancelled')->whereNull('payload')->count());
        $this->assertDatabaseHas('customer_auth_mail_works', ['id' => $terminalWork, 'status' => 'sent', 'payload' => null]);
        $this->assertDatabaseHas('customer_auth_mail_works', ['id' => $foreignWork, 'status' => 'pending', 'payload' => 'secret-ciphertext']);
        $this->assertNull($foreignToken->fresh()->consumed_at);
        $this->assertNull($foreignLink->fresh()->used_at);
        $this->assertSame(0, $other->fresh()->auth_version);
        $this->assertEquals($beforeOrder, $order->fresh()->getRawOriginal());
        $this->assertEquals($beforeIdentity, $identity->fresh()->getRawOriginal());
        $this->assertTrue(app(StorefrontOrderAccess::class)->verifyKey($order->fresh(), $key));
        $this->actingAs($customer->fresh(), 'customer');
        $this->get(route('customer.orders.index'))->assertRedirect(route('customer.account'));
        $this->assertGuest('customer');
        $this->assertAuthenticated('web');
        $this->assertDatabaseCount('customers', 2);
        $this->assertDatabaseCount('customer_auth_mail_works', 5);
    }

    public function test_revocation_failure_rolls_back_the_generation_and_suppresses_database_exception_details(): void
    {
        $customer = $this->customer();
        $token = $this->token($customer);
        $work = $this->work('auth_token_id', $token->id);
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'customer_auth_tokens')) {
                throw new \RuntimeException('private-database-error private@example.test secret-ciphertext');
            }
        });
        $this->artisan('customer-auth:revoke', ['customer' => $customer->id, '--confirm' => true])
            ->doesntExpectOutputToContain('private-database-error')->doesntExpectOutputToContain('private@example.test')
            ->doesntExpectOutputToContain('secret-ciphertext')->assertFailed();
        $this->assertSame(0, $customer->fresh()->auth_version);
        $this->assertSame(0, $customer->fresh()->enrollment_version);
        $this->assertNull($token->fresh()->consumed_at);
        $this->assertDatabaseHas('customer_auth_mail_works', ['id' => $work, 'status' => 'pending', 'payload' => 'secret-ciphertext']);
    }

    public function test_nullable_email_revocation_never_matches_unbound_or_foreign_tokens(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => null, 'email' => null]);
        $other = $this->customer();
        $foreign = $this->token($other, ['email' => null]);
        $owned = $this->token($other, ['customer_id' => $customer->id, 'email' => null]);
        $proof = $this->telegramClaim($customer);
        $this->artisan('customer-auth:revoke', ['customer' => $customer->id, '--confirm' => true])->assertSuccessful();
        $this->assertNotNull($owned->fresh()->consumed_at);
        $this->assertNull($foreign->fresh()->consumed_at);
        $this->assertDatabaseHas('customer_telegram_auth_claims', ['id' => $proof, 'failure' => 'invalid']);
        foreach (['0', '-1', 'invalid', '999999999'] as $id) {
            $this->artisan('customer-auth:revoke', ['customer' => $id, '--confirm' => true])->assertFailed();
        }
    }

    public function test_readiness_caps_counts_does_not_decrypt_or_probe_and_keeps_live_release_gate_open(): void
    {
        Http::preventStrayRequests();
        $customer = $this->customer();
        for ($i = 0; $i < 3; $i++) {
            $this->token($customer);
        }
        $this->work('auth_token_id', $this->token($customer)->id, 'failed');
        config()->set('services.google.client_id', 'private-google-client');
        config()->set('services.google.client_secret', 'private-google-secret');
        config()->set('app.url', 'https://store.example.test');
        $this->assertSame(1, Artisan::call('customer-auth:readiness', ['--limit' => 2]));
        $output = Artisan::output();
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['count' => 2, 'capped' => true], $report['counts']['customer_auth_tokens']);
        $this->assertSame(['count' => 1, 'capped' => false], $report['counts']['failed_mail']);
        $this->assertTrue($report['configuration']['google']);
        $this->assertFalse($report['configuration']['resend_smtp_safe']);
        $this->assertFalse($report['configuration']['redis_session']);
        $this->assertFalse($report['release_ready']);
        $this->assertSame('unverified', $report['live_evidence']['resend_inbox']);
        foreach ([$customer->email, 'secret-ciphertext', 'private-google-client', 'private-google-secret'] as $private) {
            $this->assertStringNotContainsString($private, $output);
        }
        Http::assertNothingSent();
        foreach (['0', '-1', '10001', 'bad'] as $limit) {
            $this->artisan('customer-auth:readiness', ['--limit' => $limit])->assertFailed();
        }
    }

    public function test_readiness_distinguishes_safe_smtp_configuration_from_real_delivery(): void
    {
        config()->set('mail.default', 'smtp');
        config()->set('mail.from.address', 'no-reply@notify.lhiestore.web.id');
        config()->set('mail.mailers.smtp', [
            'transport' => 'smtp', 'scheme' => 'smtp', 'host' => 'smtp.resend.com', 'port' => 587,
            'username' => 'resend', 'password' => 'disposable-secret', 'auto_tls' => true, 'require_tls' => true,
        ]);
        foreach ([true, false] as $tls) {
            config()->set('mail.mailers.smtp.require_tls', $tls);
            $this->assertSame(1, Artisan::call('customer-auth:readiness'));
            $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($tls, $report['configuration']['resend_smtp_safe']);
            $this->assertFalse($report['release_ready']);
        }
    }

    public function test_mail_recovery_rejects_invalid_limits_instead_of_silently_clamping(): void
    {
        Queue::fake();
        $work = $this->work('auth_token_id', $this->token($this->customer())->id);
        foreach (['0', '-1', '1001', 'bad'] as $limit) {
            $this->artisan('customer-auth:recover-mail', ['--limit' => $limit])->assertFailed();
        }
        Queue::assertNothingPushed();
        $this->assertDatabaseHas('customer_auth_mail_works', ['id' => $work, 'status' => 'pending', 'payload' => 'secret-ciphertext']);
    }

    private function customer(): Customer
    {
        return Customer::factory()->create(['telegram_id' => null, 'email' => fake()->unique()->safeEmail(), 'email_verified_at' => now()]);
    }

    private function token(Customer $customer, array $overrides = []): CustomerAuthToken
    {
        $token = (new CustomerAuthToken)->forceFill([
            'customer_id' => $customer->id, 'email' => $customer->email, 'purpose' => CustomerAuthTokenPurpose::PasswordReset,
            'token_hash' => hash('sha256', random_bytes(32)), 'auth_version' => $customer->auth_version,
            'enrollment_version' => $customer->enrollment_version, 'created_at' => now(), 'expires_at' => now()->addMinutes(30),
            ...$overrides,
        ]);
        $token->save();

        return $token;
    }

    private function link(Customer $customer, ?string $plain = null): CustomerLoginLink
    {
        return CustomerLoginLink::query()->create([
            'email' => $customer->email, 'token_hash' => hash('sha256', $plain ?? random_bytes(32)), 'expires_at' => now()->addMinutes(15),
        ]);
    }

    private function work(string $binding, int $id, string $status = 'pending', ?string $payload = 'secret-ciphertext'): int
    {
        return DB::table('customer_auth_mail_works')->insertGetId([
            $binding => $id, 'status' => $status, 'payload' => $payload, 'attempts' => 0,
            'expires_at' => now()->addMinutes(30), 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function googleClaim(Customer $customer, array $overrides = []): int
    {
        return DB::table('customer_google_auth_claims')->insertGetId([
            'operation' => 'link', 'customer_id' => $customer->id, 'auth_version' => $customer->auth_version,
            'authenticated_at' => now()->subSecond(), 'state_hash' => hash('sha256', random_bytes(32)),
            'session_binding_hash' => hash('sha256', random_bytes(32)), 'configuration_fingerprint' => hash('sha256', random_bytes(32)),
            'expires_at' => now()->addMinutes(5), 'created_at' => now(), 'updated_at' => now(), ...$overrides,
        ]);
    }

    private function telegramClaim(Customer $customer): int
    {
        return DB::table('customer_telegram_auth_claims')->insertGetId([
            'operation' => 'login', 'proof_customer_id' => $customer->id, 'proof_auth_version' => $customer->auth_version,
            'actor_id' => '123456', 'prepared_at' => now(), 'confirmation_hash' => hash('sha256', random_bytes(32)),
            'capability_hash' => hash('sha256', random_bytes(32)), 'session_binding_hash' => hash('sha256', random_bytes(32)),
            'expires_at' => now()->addMinutes(5), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
