<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerGoogleAuthClaim;
use App\Models\CustomerIdentity;
use App\Services\Customer\CustomerGoogleAuthenticationService;
use Illuminate\Http\Client\Request as ProviderRequest;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RunsPostgresWorkers;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\RunsPostgresRace;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
class CustomerGoogleAuthenticationPostgresTest extends TestCase
{
    use RunsPostgresRace, RunsPostgresWorkers;

    private array $customerIds = [];

    private array $claimIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requirePostgresConcurrency('Customer Google authentication');
        if (! Schema::hasTable('customer_google_auth_claims')) {
            $this->assertSame(0, PostgresTestDatabase::migrateFresh());
        }
        config([
            'app.url' => 'https://store.example.test',
            'services.google.client_id' => 'race-client',
            'services.google.client_secret' => 'race-secret',
            'cache.default' => 'array',
            'cache.limiter' => 'array',
        ]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->claimIds !== []) {
                CustomerGoogleAuthClaim::query()->whereIn('id', $this->claimIds)->delete();
            }
            if ($this->customerIds !== []) {
                CustomerIdentity::query()->whereIn('customer_id', $this->customerIds)->delete();
                Customer::query()->whereIn('id', $this->customerIds)->delete();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_competing_customers_cannot_link_the_same_google_subject(): void
    {
        $customers = [$this->customer(), $this->customer()];
        $subject = 'shared-'.Str::uuid();
        $callbacks = [];
        foreach ($customers as $customer) {
            [$sessionId, $data, $state] = $this->claim($customer);
            $callbacks[] = function () use ($customer, $sessionId, $data, $state, $subject): string {
                Auth::forgetGuards();
                Auth::guard('customer')->setUser(Customer::query()->findOrFail($customer->id));
                $this->provider($subject);
                $browser = $this->browser($sessionId, $data);
                $browser->query->replace(['state' => $state, 'code' => 'race-code']);
                $outcome = app(CustomerGoogleAuthenticationService::class)->callback($browser);
                if ($outcome === 'linked') {
                    $this->assertNotSame($sessionId, $browser->session()->getId());
                    $this->assertSame($data['customer_authenticated_at'], $browser->session()->get('customer_authenticated_at'));
                }

                return $outcome;
            };
        }

        $results = $this->race($callbacks);
        sort($results);
        $this->assertSame(['conflict', 'linked'], $results);
        $identity = CustomerIdentity::query()->where('provider', 'google')->where('provider_subject', $subject)->sole();
        $this->assertContains($identity->customer_id, $this->customerIds);
        $this->assertSame(1, CustomerIdentity::query()->whereIn('customer_id', $this->customerIds)->where('provider', 'google')->count());
        $this->assertSame(2, Customer::query()->whereIn('id', $this->customerIds)->count());
        $this->assertSame(2, CustomerGoogleAuthClaim::query()->whereIn('id', $this->claimIds)->whereNotNull('consumed_at')->count());
    }

    public function test_competing_subjects_cannot_occupy_one_customer_google_slot(): void
    {
        $customer = $this->customer();
        $subjects = ['first-'.Str::uuid(), 'second-'.Str::uuid()];
        $callbacks = [];
        foreach ($subjects as $subject) {
            [$sessionId, $data, $state] = $this->claim($customer);
            $callbacks[] = function () use ($customer, $sessionId, $data, $state, $subject): string {
                Auth::forgetGuards();
                Auth::guard('customer')->setUser(Customer::query()->findOrFail($customer->id));
                $this->provider($subject);
                $browser = $this->browser($sessionId, $data);
                $browser->query->replace(['state' => $state, 'code' => 'race-code']);

                return app(CustomerGoogleAuthenticationService::class)->callback($browser);
            };
        }

        $results = $this->race($callbacks);
        sort($results);
        $this->assertSame(['conflict', 'linked'], $results);
        $identity = CustomerIdentity::query()->where('customer_id', $customer->id)->where('provider', 'google')->sole();
        $this->assertContains($identity->provider_subject, $subjects);
        $this->assertSame($customer->email, $customer->fresh()->email);
        $this->assertSame(2, CustomerGoogleAuthClaim::query()->whereIn('id', $this->claimIds)->whereNotNull('consumed_at')->count());
    }

    public function test_simultaneous_callbacks_consume_one_state_and_only_one_worker_contacts_google(): void
    {
        $customer = $this->customer();
        $subject = 'login-'.Str::uuid();
        $identity = (new CustomerIdentity)->forceFill([
            'customer_id' => $customer->id, 'provider' => 'google', 'provider_subject' => $subject,
        ]);
        $identity->save();
        Auth::forgetGuards();
        $browser = $this->browser();
        [$sessionId, $data, $state] = $this->claim(null, $browser);

        $workers = $this->runConcurrentWorkers(3, function (int $index) use ($sessionId, $data, $state, $subject, $customer, $identity): string {
            Auth::forgetGuards();
            $requests = 0;
            Http::fake(function (ProviderRequest $request) use (&$requests, $subject) {
                $requests++;
                if ($request->url() === 'https://oauth2.googleapis.com/token') {
                    usleep(50_000);

                    return Http::response(['access_token' => 'race-token', 'token_type' => 'Bearer']);
                }
                $this->assertSame('https://www.googleapis.com/oauth2/v3/userinfo', $request->url());

                return Http::response(['sub' => $subject, 'email' => 'changed@example.test', 'email_verified' => true]);
            });
            $browser = $this->browser($sessionId, $data);
            $browser->query->replace(['state' => $state, 'code' => 'race-code']);
            $outcome = app(CustomerGoogleAuthenticationService::class)->callback($browser);
            if ($outcome === 'authenticated') {
                $this->assertSame(2, $requests);
                $this->assertAuthenticatedAs($customer, 'customer');
                $this->assertNotSame($sessionId, $browser->session()->getId());
                $this->assertSame($identity->id, $browser->session()->get('customer_google_identity_id'));
            } else {
                $this->assertSame('invalid', $outcome);
                $this->assertSame(0, $requests);
                $this->assertGuest('customer');
            }

            return $outcome;
        }, 'google-callback-race');

        $this->assertWorkersSucceeded($workers);
        $results = array_column($workers, 'value');
        sort($results);
        $this->assertSame(['authenticated', 'invalid', 'invalid'], $results);
        $this->assertSame(1, CustomerGoogleAuthClaim::query()->whereIn('id', $this->claimIds)->whereNotNull('consumed_at')->count());
        $this->assertSame($customer->id, $identity->fresh()->customer_id);
        $this->assertSame($customer->email, $customer->fresh()->email);
    }

    private function customer(): Customer
    {
        $customer = Customer::factory()->create([
            'telegram_id' => null, 'email' => Str::uuid().'@example.test', 'email_verified_at' => now(),
        ])->fresh();
        $this->customerIds[] = $customer->id;

        return $customer;
    }

    private function claim(?Customer $customer, ?Request $browser = null): array
    {
        $browser ??= $this->browser();
        if ($customer !== null) {
            Auth::guard('customer')->setUser($customer);
            $browser->session()->put([
                'customer_auth_version' => $customer->auth_version,
                'customer_authenticated_at' => now()->subMinute()->timestamp,
            ]);
        }
        $url = app(CustomerGoogleAuthenticationService::class)->start($browser, $customer !== null ? 'link' : 'login');
        $this->assertNotNull($url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $claim = CustomerGoogleAuthClaim::query()->where('state_hash', hash('sha256', $query['state']))->sole();
        $this->claimIds[] = $claim->id;

        return [$browser->session()->getId(), $browser->session()->all(), $query['state']];
    }

    private function browser(?string $id = null, array $data = []): Request
    {
        $session = new Store('google-race', new ArraySessionHandler(120), $id);
        $session->start();
        $session->put($data);
        $request = Request::create('/auth/google/callback', server: ['REMOTE_ADDR' => '192.0.2.62']);
        $request->setLaravelSession($session);
        $request->setUserResolver(fn (?string $guard = null) => Auth::guard($guard)->user());

        return $request;
    }

    private function provider(string $subject): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'race-token', 'token_type' => 'Bearer']),
            'https://www.googleapis.com/oauth2/v3/userinfo' => Http::response([
                'sub' => $subject, 'email' => 'provider@example.test', 'email_verified' => true,
            ]),
        ]);
    }
}
