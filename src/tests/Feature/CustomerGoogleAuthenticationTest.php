<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerGoogleAuthClaim;
use App\Models\CustomerIdentity;
use App\Models\User;
use App\Services\Customer\CustomerGoogleAuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ProviderRequest;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomerGoogleAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const USER_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->withCredentials();
        config([
            'app.url' => 'https://store.example.test',
            'services.google.client_id' => 'fixture-client',
            'services.google.client_secret' => 'fixture-secret',
            'cache.default' => 'array',
            'cache.limiter' => 'array',
        ]);
        Http::preventStrayRequests();
    }

    public function test_explicit_google_registration_creates_one_verified_customer_and_rotates_the_session(): void
    {
        $this->provider('new-subject', ' New@Example.TEST ');
        $browser = $this->browser();
        $state = $this->start($browser, 'register');
        $oldId = $browser->session()->getId();
        $oldCsrf = $browser->session()->token();

        $this->assertSame('authenticated', $this->consumeCallback($browser, $state));

        $customer = Customer::query()->sole();
        $this->assertSame('new@example.test', $customer->email);
        $this->assertNotNull($customer->email_verified_at);
        $this->assertNull($customer->telegram_id);
        $this->assertNull($customer->password);
        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertNotSame($oldId, $browser->session()->getId());
        $this->assertNotSame($oldCsrf, $browser->session()->token());
        $this->assertSame($customer->auth_version, $browser->session()->get('customer_auth_version'));
        $this->assertSame(now()->timestamp, $browser->session()->get('customer_authenticated_at'));
        $this->assertSame($customer->id, $this->identity('new-subject')->customer_id);
        Http::assertSent(fn (ProviderRequest $request) => $request->url() === self::TOKEN_URL
            && $request['grant_type'] === 'authorization_code'
            && $request['code'] === 'fixture-authorization-code'
            && $request['redirect_uri'] === 'https://store.example.test/auth/google/callback');
    }

    public function test_existing_subject_signs_in_without_changing_canonical_email_or_admin_guard(): void
    {
        $customer = $this->emailCustomer('original@example.test');
        $this->addGoogle($customer, 'stable-subject');
        $other = $this->emailCustomer('changed@example.test');
        $admin = User::factory()->create();
        $this->actingAs($admin, 'web');
        $this->provider('stable-subject', $other->email);
        $browser = $this->browser();
        $state = $this->start($browser);
        $oldId = $browser->session()->getId();

        $this->assertSame('authenticated', $this->consumeCallback($browser, $state));
        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertAuthenticatedAs($admin, 'web');
        $this->assertNotSame($oldId, $browser->session()->getId());
        $this->assertSame('original@example.test', $customer->fresh()->email);
        $this->assertSame(2, Customer::query()->count());
        $this->assertNotNull($this->identity('stable-subject')->last_used_at);
    }

    public function test_unknown_subject_cannot_register_through_login_or_guest_link(): void
    {
        $this->provider();
        $browser = $this->browser();
        $this->assertSame('invalid', $this->consumeCallback($browser, $this->start($browser)));
        $this->assertNull($this->service()->start($this->browser(), 'link'));
        $this->assertGuest('customer');
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('customer_identities', 0);
    }

    public function test_email_collision_does_not_authenticate_merge_or_overwrite_the_existing_account(): void
    {
        $customer = $this->emailCustomer('owned@example.test');
        $original = $customer->getAttributes();
        $this->provider('unowned-subject', ' OWNED@EXAMPLE.TEST ');
        $browser = $this->browser();

        $this->assertSame('collision', $this->consumeCallback($browser, $this->start($browser, 'register')));
        $this->assertGuest('customer');
        $this->assertSame($original, $customer->fresh()->getAttributes());
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('customer_identities', 0);
    }

    public function test_fresh_explicit_link_and_unlink_rotate_session_without_extending_proof(): void
    {
        $this->freezeTime();
        $customer = $this->emailCustomer();
        $browser = $this->browser();
        $proof = now()->subMinutes(2)->timestamp;
        $this->authenticateBrowser($customer, $browser, $proof);
        $this->provider('linked-subject', 'different@example.test');
        $state = $this->start($browser, 'link');
        $oldId = $browser->session()->getId();
        $oldCsrf = $browser->session()->token();

        $this->assertSame('linked', $this->consumeCallback($browser, $state));
        $this->assertSame($customer->id, $this->identity('linked-subject')->customer_id);
        $this->assertSame('owner@example.test', $customer->fresh()->email);
        $this->assertNotSame($oldId, $browser->session()->getId());
        $this->assertNotSame($oldCsrf, $browser->session()->token());
        $this->assertSame($proof, $browser->session()->get('customer_authenticated_at'));
        $this->assertSame($customer->auth_version, $browser->session()->get('customer_auth_version'));
        $oldId = $browser->session()->getId();

        $this->assertSame('unlinked', $this->service()->unlink($browser));
        $this->assertNotSame($oldId, $browser->session()->getId());
        $this->assertSame($proof, $browser->session()->get('customer_authenticated_at'));
        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertDatabaseCount('customer_identities', 0);
    }

    public function test_link_rejects_foreign_subject_and_an_occupied_provider_slot_without_reassignment(): void
    {
        $customer = $this->emailCustomer();
        $foreign = $this->emailCustomer('foreign@example.test');
        $foreignIdentity = $this->addGoogle($foreign, 'foreign-subject');
        $browser = $this->browser();
        $this->authenticateBrowser($customer, $browser);
        $this->provider('foreign-subject');
        $this->assertSame('conflict', $this->consumeCallback($browser, $this->start($browser, 'link')));
        $this->assertSame($foreign->id, $foreignIdentity->fresh()->customer_id);
        $owned = $this->addGoogle($customer, 'original-subject');
        $this->provider('replacement-subject');
        $this->assertSame('conflict', $this->consumeCallback($browser, $this->start($browser, 'link')));
        $this->assertSame('original-subject', $owned->fresh()->provider_subject);
        $this->assertDatabaseCount('customer_identities', 2);
        $this->assertDatabaseCount('customers', 2);
    }

    public function test_old_missing_future_or_changed_fresh_proof_cannot_link_or_unlink(): void
    {
        $this->freezeTime();
        $customer = $this->emailCustomer();
        $this->addGoogle($customer, 'existing-subject');
        foreach ([null, now()->subSeconds(300)->timestamp, now()->addMinute()->timestamp] as $proof) {
            $browser = $this->browser();
            $this->authenticateBrowser($customer, $browser);
            $browser->session()->put('customer_authenticated_at', $proof);
            $this->assertNull($this->service()->start($browser, 'link'));
            $this->assertSame('invalid', $this->service()->unlink($browser));
        }
        $customer->identities()->delete();
        $browser = $this->browser();
        $this->authenticateBrowser($customer, $browser);
        $state = $this->start($browser, 'link');
        $browser->session()->put('customer_authenticated_at', now()->subSecond()->timestamp);
        $this->assertSame('invalid', $this->consumeCallback($browser, $state));
        Http::assertNothingSent();
        $this->assertDatabaseCount('customer_identities', 0);
    }

    public function test_callback_rechecks_freshness_current_customer_and_authentication_version(): void
    {
        $customer = $this->emailCustomer();
        $other = $this->emailCustomer('other@example.test');
        foreach (['expired', 'customer', 'version'] as $change) {
            $browser = $this->browser();
            $this->authenticateBrowser($customer->fresh(), $browser);
            $state = $this->start($browser, 'link');
            if ($change === 'expired') {
                $this->travel(300)->seconds();
            } elseif ($change === 'customer') {
                Auth::guard('customer')->setUser($other);
            } else {
                $customer->forceFill(['auth_version' => $customer->auth_version + 1])->save();
            }
            $this->assertSame('invalid', $this->consumeCallback($browser, $state));
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('customer_identities', 0);
    }

    public function test_google_only_and_unverified_email_accounts_cannot_unlink_the_last_method(): void
    {
        foreach ([null, 'unverified@example.test'] as $email) {
            $customer = Customer::factory()->create(['telegram_id' => null, 'email' => $email, 'email_verified_at' => null])->fresh();
            $identity = $this->addGoogle($customer, 'only-'.($email ?? 'no-email'));
            $browser = $this->browser();
            $this->authenticateBrowser($customer, $browser);
            $this->assertSame('last_method', $this->service()->unlink($browser));
            $this->assertNotNull($identity->fresh());
        }
    }

    public function test_only_exact_canonical_telegram_identity_counts_as_an_alternative_method(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 456789, 'email' => null, 'email_verified_at' => null])->fresh();
        $this->addGoogle($customer, 'google-only');
        $browser = $this->browser();
        $this->authenticateBrowser($customer, $browser);
        $this->assertSame('last_method', $this->service()->unlink($browser));
        $telegram = (new CustomerIdentity)->forceFill([
            'customer_id' => $customer->id, 'provider' => 'telegram', 'provider_subject' => '456789',
        ]);
        $telegram->save();
        $customer->update(['telegram_id' => 987654]);
        $this->assertSame('last_method', $this->service()->unlink($browser));
        $customer->update(['telegram_id' => 456789]);
        $this->assertSame('unlinked', $this->service()->unlink($browser));
        $this->assertSame('456789', $telegram->fresh()->provider_subject);
        $this->assertSame(456789, $customer->fresh()->telegram_id);
    }

    public function test_invalid_cross_browser_expired_and_replayed_state_never_repeat_provider_exchange(): void
    {
        $this->provider();
        $browser = $this->browser();
        $state = $this->start($browser, 'register');
        $this->assertSame('invalid', $this->consumeCallback($browser, 'not-a-valid-state'));
        $this->assertSame('invalid', $this->consumeCallback($this->browser(), $state));
        $copiedBrowser = $this->browser();
        $copiedBrowser->session()->put($browser->session()->all());
        $this->assertSame('invalid', $this->consumeCallback($copiedBrowser, $state));
        Http::assertNothingSent();
        $this->assertSame('authenticated', $this->consumeCallback($browser, $state));
        $this->assertSame('invalid', $this->consumeCallback($browser, $state));
        Http::assertSentCount(2);
        Auth::guard('customer')->logout();
        $browser = $this->browser();
        $state = $this->start($browser, 'register');
        $this->travel(300)->seconds();
        $this->assertSame('invalid', $this->consumeCallback($browser, $state));
        Http::assertSentCount(2);
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_state_is_consumed_before_provider_network_io_and_cannot_be_reentered(): void
    {
        $browser = $this->browser();
        $state = $this->start($browser, 'register');
        $nested = null;
        Http::fake(function (ProviderRequest $request) use ($browser, $state, &$nested) {
            if ($request->url() === self::TOKEN_URL) {
                $this->assertNotNull(CustomerGoogleAuthClaim::query()->sole()->consumed_at);
                $nested = $this->consumeCallback($browser, $state);

                return Http::response(['access_token' => 'fixture-access-token', 'token_type' => 'Bearer']);
            }
            $this->assertSame(self::USER_URL, $request->url());

            return Http::response(['sub' => 'reentry-subject', 'email' => 'reentry@example.test', 'email_verified' => true]);
        });
        $this->assertSame('authenticated', $this->consumeCallback($browser, $state));
        $this->assertSame('invalid', $nested);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_denied_or_missing_code_consumes_state_without_contacting_google(): void
    {
        foreach ([['error' => 'access_denied'], []] as $parameters) {
            $browser = $this->browser();
            $state = $this->start($browser, 'register');
            $browser->query->replace(['state' => $state] + $parameters);
            $this->assertSame('invalid', $this->service()->callback($browser));
            $this->assertSame('invalid', $this->consumeCallback($browser, $state));
        }
        Http::assertNothingSent();
        $this->assertGuest('customer');
    }

    public function test_provider_outage_is_safe_and_the_failed_state_cannot_be_retried(): void
    {
        $browser = $this->browser();
        $state = $this->start($browser, 'register');
        Http::fake([self::TOKEN_URL => Http::response(['error' => 'provider-outage'], 503)]);
        $this->assertSame('unavailable', $this->consumeCallback($browser, $state));
        $this->assertSame('invalid', $this->consumeCallback($browser, $state));
        Http::assertSentCount(1);
        $this->assertGuest('customer');
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_revoked_changed_and_insecure_configuration_fail_closed_without_provider_requests(): void
    {
        $browser = $this->browser();
        $state = $this->start($browser, 'register');
        config(['services.google.client_secret' => 'rotated-secret']);
        $this->assertSame('unavailable', $this->consumeCallback($browser, $state));
        $browser = $this->browser();
        $state = $this->start($browser, 'register');
        config(['services.google.client_secret' => '']);
        $this->assertFalse($this->service()->ready());
        $this->assertSame('unavailable', $this->consumeCallback($browser, $state));
        $this->assertNull($this->service()->start($this->browser(), 'register'));
        config(['services.google.client_secret' => 'fixture-secret', 'app.url' => 'http://store.example.test']);
        $this->assertFalse($this->service()->ready());
        $this->assertNull($this->service()->start($this->browser(), 'register'));
        Http::assertNothingSent();
        $this->assertGuest('customer');
    }

    public function test_unverified_missing_email_or_missing_subject_cannot_register_or_authorize_existing_subject(): void
    {
        $customer = $this->emailCustomer();
        $this->addGoogle($customer, 'known-subject');
        foreach ([
            ['sub' => 'known-subject', 'email' => $customer->email, 'email_verified' => false],
            ['sub' => 'known-subject', 'email' => $customer->email, 'email_verified' => 'true'],
            ['sub' => 'known-subject', 'email_verified' => true],
            ['sub' => '', 'email' => 'new@example.test', 'email_verified' => true],
        ] as $identity) {
            $this->providerResponse($identity);
            $browser = $this->browser();
            $this->assertSame('invalid', $this->consumeCallback($browser, $this->start($browser, 'register')));
            $this->assertGuest('customer');
        }
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('customer_identities', 1);
    }

    public function test_ten_starts_per_session_and_ip_and_unavailable_limiter_fail_closed(): void
    {
        $browser = $this->browser();
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->start($browser);
        }
        $this->assertNull($this->service()->start($browser, 'login'));
        $this->travel(900)->seconds();
        $this->start($browser);
        Cache::flush();
        config(['cache.limiter' => 'missing-google-limiter']);
        $this->assertNull($this->service()->start($this->browser(), 'register'));
        $this->assertDatabaseCount('customer_google_auth_claims', 11);
    }

    public function test_routes_support_explicit_registration_login_link_and_unlink(): void
    {
        $this->provider('route-subject', 'route@example.test');
        $response = $this->get(route('customer.google.redirect', ['operation' => 'register']))->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('https://store.example.test/auth/google/callback', $query['redirect_uri']);
        $oldId = session()->getId();
        $this->withCookie(config('session.cookie'), $oldId)
            ->get(route('customer.google.callback', ['state' => $query['state'], 'code' => 'fixture-authorization-code']))
            ->assertRedirect(route('customer.orders.index'))
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $customer = Customer::query()->sole();
        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertNotSame($oldId, session()->getId());
        $this->withCookie(config('session.cookie'), session()->getId())->get(route('customer.orders.index'))->assertOk();
        $this->withCookie(config('session.cookie'), session()->getId())->post(route('customer.google.unlink'))
            ->assertRedirect(route('customer.security'))->assertSessionHas('customer_login_sent');
        $this->assertDatabaseCount('customer_identities', 0);
        $this->provider('route-subject', 'route@example.test');
        $response = $this->withCookie(config('session.cookie'), session()->getId())->post(route('customer.google.link'))->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $oldId = session()->getId();
        $proof = session('customer_authenticated_at');
        $this->withCookie(config('session.cookie'), $oldId)
            ->get(route('customer.google.callback', ['state' => $query['state'], 'code' => 'fixture-authorization-code']))
            ->assertRedirect(route('customer.security'))->assertSessionHas('customer_login_sent');
        $this->assertNotSame($oldId, session()->getId());
        $this->assertSame($proof, session('customer_authenticated_at'));
        $this->assertSame($customer->id, $this->identity('route-subject')->customer_id);
    }

    public function test_route_login_of_unknown_subject_and_unavailable_provider_do_not_create_an_account(): void
    {
        $this->provider();
        $response = $this->get(route('customer.google.redirect'))->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->withCookie(config('session.cookie'), session()->getId())
            ->get(route('customer.google.callback', ['state' => $query['state'], 'code' => 'fixture-authorization-code']))
            ->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_error');
        $this->assertGuest('customer');
        $this->assertDatabaseCount('customers', 0);
        config(['services.google.client_secret' => '']);
        $this->get(route('customer.google.redirect'))->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_error');
    }

    public function test_nullable_email_google_customer_can_access_orders_and_unlink_to_canonical_telegram(): void
    {
        $customer = Customer::factory()->create(['email' => null, 'email_verified_at' => null, 'telegram_id' => 456789])->fresh();
        $google = $this->addGoogle($customer, 'legacy-google-subject');
        $telegram = (new CustomerIdentity)->forceFill([
            'customer_id' => $customer->id, 'provider' => 'telegram', 'provider_subject' => '456789',
        ]);
        $telegram->save();
        $this->provider('legacy-google-subject', 'provider@example.test');
        $response = $this->get(route('customer.google.redirect'))->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->withCookie(config('session.cookie'), session()->getId())
            ->get(route('customer.google.callback', ['state' => $query['state'], 'code' => 'fixture-authorization-code']))
            ->assertRedirect(route('customer.orders.index'));
        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertSame($google->id, session('customer_google_identity_id'));
        $this->assertNull($customer->fresh()->email);
        $this->withCookie(config('session.cookie'), session()->getId())->get(route('customer.orders.index'))->assertOk();
        $proof = session('customer_authenticated_at');
        $oldId = session()->getId();
        $this->withCookie(config('session.cookie'), $oldId)->post(route('customer.google.unlink'))
            ->assertRedirect(route('customer.security'))->assertSessionHas('customer_login_sent');
        $this->assertNotSame($oldId, session()->getId());
        $this->assertSame($proof, session('customer_authenticated_at'));
        $this->assertNull(session('customer_google_identity_id'));
        $this->assertTrue(session('customer_telegram_authenticated'));
        $this->withCookie(config('session.cookie'), session()->getId())->get(route('customer.orders.index'))->assertOk();
        $this->assertNull($google->fresh());
        $this->assertSame(456789, $customer->fresh()->telegram_id);
        $this->withCookie(config('session.cookie'), session()->getId())->post(route('customer.logout'));
        $this->assertGuest('customer');
        $this->assertNull(session('customer_google_identity_id'));
        $this->assertNull(session('customer_telegram_authenticated'));
    }

    public function test_provider_secrets_never_enter_database_audit_logs_queues_sessions_or_callback_response(): void
    {
        Queue::fake();
        Notification::fake();
        $logs = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logs): void {
            $logs[] = [$event->message, $event->context];
        });
        $this->provider('safe-subject', 'safe@example.test');
        $response = $this->get(route('customer.google.redirect', ['operation' => 'register']));
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $response = $this->withCookie(config('session.cookie'), session()->getId())
            ->get(route('customer.google.callback', ['state' => $query['state'], 'code' => 'fixture-authorization-code']))
            ->assertRedirect(route('customer.orders.index'));
        $evidence = [$logs, session()->all(), $response->getContent(), $response->headers->all(), Customer::query()->sole()->toArray(), CustomerIdentity::query()->sole()->toArray()];
        foreach (Schema::getTableListing() as $table) {
            $evidence[] = DB::table($table)->get()->all();
        }
        $encoded = json_encode($evidence, JSON_THROW_ON_ERROR);
        foreach (['fixture-access-token', 'fixture-refresh-token', 'fixture-id-token', 'fixture-authorization-code', 'fixture-secret', $query['state']] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
        Queue::assertNothingPushed();
        Notification::assertNothingSent();
    }

    private function service(): CustomerGoogleAuthenticationService
    {
        return app(CustomerGoogleAuthenticationService::class);
    }

    private function browser(): Request
    {
        $session = new Store('google-feature', new ArraySessionHandler(120));
        $session->start();
        $session->regenerateToken();
        $request = Request::create('/auth/google/callback', server: ['REMOTE_ADDR' => '192.0.2.61']);
        $request->setLaravelSession($session);
        $request->setUserResolver(fn (?string $guard = null) => Auth::guard($guard)->user());

        return $request;
    }

    private function authenticateBrowser(Customer $customer, Request $browser, ?int $proof = null): void
    {
        Auth::guard('customer')->setUser($customer);
        $browser->session()->put([
            'customer_auth_version' => $customer->auth_version,
            'customer_authenticated_at' => $proof ?? now()->timestamp,
        ]);
    }

    private function start(Request $browser, string $operation = 'login'): string
    {
        $url = $this->service()->start($browser, $operation);
        $this->assertNotNull($url);
        $this->assertSame('https', parse_url($url, PHP_URL_SCHEME));
        $this->assertSame('accounts.google.com', parse_url($url, PHP_URL_HOST));
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('fixture-client', $query['client_id']);
        $this->assertSame('https://store.example.test/auth/google/callback', $query['redirect_uri']);
        $this->assertNotEmpty($query['state']);
        $this->assertStringNotContainsString('fixture-secret', $url);

        return $query['state'];
    }

    private function consumeCallback(Request $browser, string $state): string
    {
        $browser->query->replace(['state' => $state, 'code' => 'fixture-authorization-code']);

        return $this->service()->callback($browser);
    }

    private function provider(string $subject = 'fixture-subject', string $email = 'fixture@example.test'): void
    {
        $this->providerResponse(['sub' => $subject, 'email' => $email, 'email_verified' => true]);
    }

    private function providerResponse(array $identity): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => Http::response([
                'access_token' => 'fixture-access-token', 'refresh_token' => 'fixture-refresh-token',
                'id_token' => 'fixture-id-token', 'token_type' => 'Bearer', 'expires_in' => 3600,
            ]),
            self::USER_URL => Http::response($identity),
        ]);
    }

    private function emailCustomer(string $email = 'owner@example.test'): Customer
    {
        return Customer::factory()->create(['telegram_id' => null, 'email' => $email, 'email_verified_at' => now()])->fresh();
    }

    private function addGoogle(Customer $customer, string $subject): CustomerIdentity
    {
        $identity = (new CustomerIdentity)->forceFill([
            'customer_id' => $customer->id, 'provider' => 'google', 'provider_subject' => $subject,
            'provider_email' => $customer->email,
        ]);
        $identity->save();

        return $identity;
    }

    private function identity(string $subject): CustomerIdentity
    {
        return CustomerIdentity::query()->where('provider', 'google')->where('provider_subject', $subject)->sole();
    }
}
