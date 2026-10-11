<?php

namespace Tests\Feature;

use App\Enums\CustomerIdentityProvider;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\CustomerTelegramAuthClaim;
use App\Services\Customer\CustomerTelegramAuthenticationService;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RunsPostgresWorkers;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\RunsPostgresRace;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
class CustomerTelegramAuthenticationPostgresTest extends TestCase
{
    use RunsPostgresRace, RunsPostgresWorkers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requirePostgresConcurrency('Customer Telegram authentication');
        if (! Schema::hasTable('customer_telegram_auth_claims')) {
            $this->assertSame(0, PostgresTestDatabase::migrateFresh());
        }
        config([
            'cache.default' => 'array',
            'cache.limiter' => 'array',
            'services.telegram.token' => 'fixture-token',
            'storefront.links.telegram_bot_url' => 'https://t.me/lhiestore_bot?start=landing',
        ]);
    }

    public function test_simultaneous_consume_authenticates_one_browser_and_materializes_one_legacy_identity(): void
    {
        $customer = Customer::factory()->create(['email' => null, 'email_verified_at' => null]);
        $request = $this->browser();
        $claim = $this->confirmedClaim($request, (string) $customer->telegram_id);
        $sessionId = $request->session()->getId();
        $sessionData = $request->session()->all();

        $results = $this->race(array_fill(0, 3, function () use ($sessionId, $sessionData): string {
            Auth::forgetGuards();
            $browser = $this->browser($sessionId, $sessionData);
            $consumed = app(CustomerTelegramAuthenticationService::class)->consume($browser);
            if ($consumed) {
                $this->assertTrue($browser->session()->get('customer_telegram_authenticated'));
                $this->assertNotSame($sessionId, $browser->session()->getId());
            }

            return $consumed ? 'authenticated' : 'rejected';
        }));

        sort($results);
        $this->assertSame(['authenticated', 'rejected', 'rejected'], $results);
        $this->assertNotNull($claim->fresh()->consumed_at);
        $identity = CustomerIdentity::query()->where('provider', CustomerIdentityProvider::Telegram->value)->where('provider_subject', (string) $customer->telegram_id)->sole();
        $this->assertSame($customer->getKey(), $identity->customer_id);
        $this->assertSame(1, Customer::query()->where('telegram_id', $customer->telegram_id)->count());
    }

    public function test_competing_customers_cannot_link_the_same_unused_actor(): void
    {
        $actor = (string) random_int(1_000_000_000_000, 9_000_000_000_000);
        $customers = [Customer::factory()->create(['telegram_id' => null])->fresh(), Customer::factory()->create(['telegram_id' => null])->fresh()];
        $claims = [];
        $browsers = [];
        foreach ($customers as $customer) {
            $request = $this->browser();
            Auth::guard('customer')->setUser($customer);
            $request->session()->put(['customer_auth_version' => $customer->auth_version, 'customer_authenticated_at' => now()->timestamp]);
            $claims[] = $this->confirmedClaim($request, $actor, true);
            $browsers[] = [$request->session()->getId(), $request->session()->all()];
        }
        $callbacks = [];
        foreach ($customers as $index => $customer) {
            $callbacks[] = function () use ($customer, $browsers, $index): string {
                Auth::forgetGuards();
                Auth::guard('customer')->setUser(Customer::query()->findOrFail($customer->getKey()));

                return app(CustomerTelegramAuthenticationService::class)->consume($this->browser(...$browsers[$index])) ? 'linked' : 'rejected';
            };
        }

        $results = $this->race($callbacks);
        sort($results);
        $this->assertSame(['linked', 'rejected'], $results);
        $owner = Customer::query()->where('telegram_id', $actor)->sole();
        $identity = CustomerIdentity::query()->where('provider', CustomerIdentityProvider::Telegram->value)->where('provider_subject', $actor)->sole();
        $this->assertSame($owner->getKey(), $identity->customer_id);
        $this->assertSame(1, CustomerTelegramAuthClaim::query()->whereIn('id', array_map(fn ($claim) => $claim->getKey(), $claims))->whereNotNull('consumed_at')->count());
        $this->assertSame(1, CustomerTelegramAuthClaim::query()->whereIn('id', array_map(fn ($claim) => $claim->getKey(), $claims))->where('failure', 'conflict')->count());
        $loser = $customers[0]->getKey() === $owner->getKey() ? $customers[1] : $customers[0];
        $this->assertNull($loser->fresh()->telegram_id);
    }

    public function test_competing_actors_cannot_occupy_one_customer_provider_slot(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => null])->fresh();
        $actors = [(string) random_int(1_000_000_000_000, 4_000_000_000_000), (string) random_int(5_000_000_000_000, 9_000_000_000_000)];
        $callbacks = [];
        foreach ($actors as $actor) {
            $request = $this->browser();
            Auth::guard('customer')->setUser($customer);
            $request->session()->put(['customer_auth_version' => $customer->auth_version, 'customer_authenticated_at' => now()->timestamp]);
            $this->confirmedClaim($request, $actor, true);
            $sessionId = $request->session()->getId();
            $sessionData = $request->session()->all();
            $callbacks[] = function () use ($customer, $sessionId, $sessionData): string {
                Auth::forgetGuards();
                Auth::guard('customer')->setUser(Customer::query()->findOrFail($customer->getKey()));

                return app(CustomerTelegramAuthenticationService::class)->consume($this->browser($sessionId, $sessionData)) ? 'linked' : 'rejected';
            };
        }

        $results = $this->race($callbacks);
        sort($results);
        $this->assertSame(['linked', 'rejected'], $results);
        $identity = CustomerIdentity::query()->where('customer_id', $customer->getKey())->where('provider', CustomerIdentityProvider::Telegram->value)->sole();
        $this->assertSame((string) $customer->fresh()->telegram_id, $identity->provider_subject);
        $this->assertContains($identity->provider_subject, $actors);
    }

    private function confirmedClaim(Request $request, string $actor, bool $link = false): CustomerTelegramAuthClaim
    {
        $service = app(CustomerTelegramAuthenticationService::class);
        $start = $service->start($request, $link);
        $this->assertNotNull($start);
        parse_str(parse_url($start['deep_link'], PHP_URL_QUERY), $query);
        $confirmation = $service->prepare($query['start'], $actor, $actor, 'private');
        $this->assertNotNull($confirmation);
        $this->assertTrue($service->confirm($confirmation, $actor, $actor, 'private'));

        return CustomerTelegramAuthClaim::query()->findOrFail($request->session()->get('customer_telegram_claim_id'));
    }

    private function browser(?string $sessionId = null, array $data = []): Request
    {
        $session = new Store('telegram-race', new ArraySessionHandler(120), $sessionId);
        $session->start();
        $session->put($data);
        $request = Request::create('/akun', 'POST', server: ['REMOTE_ADDR' => '192.0.2.19']);
        $request->setLaravelSession($session);

        return $request;
    }
}
