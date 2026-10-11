<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerAuthMailWork;
use App\Models\CustomerIdentity;
use App\Models\User;
use App\Services\Customer\CustomerTelegramAuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CustomerTelegramAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        config(['services.telegram.token' => 'test-token', 'storefront.links.telegram_bot_url' => 'https://t.me/TestStoreBot']);
    }

    public function test_unused_private_actor_links_only_after_explicit_bot_and_browser_consumption(): void
    {
        $customer = $this->emailCustomer();
        $this->freshLogin($customer);
        $capability = $this->start(true);
        $service = app(CustomerTelegramAuthenticationService::class);
        $confirmation = $service->prepare($capability, '456789', '456789', 'private');
        $this->assertNotNull($confirmation);
        $this->assertNull($customer->fresh()->telegram_id);
        $this->claimStatus()->assertJson(['status' => 'pending']);
        $this->consume()->assertStatus(422);
        $this->assertTrue($service->confirm($confirmation, '456789', '456789', 'private'));
        $this->assertFalse($service->confirm($confirmation, '456789', '456789', 'private'));
        $this->claimStatus()->assertJson(['status' => 'confirmed']);
        $this->assertNull($customer->fresh()->telegram_id);
        $this->consume()->assertOk()->assertJson(['status' => 'complete']);
        $this->assertSame('456789', (string) $customer->fresh()->telegram_id);
        $identity = CustomerIdentity::query()->where('provider', 'telegram')->sole();
        $this->assertSame($customer->id, $identity->customer_id);
        $this->assertSame('456789', $identity->provider_subject);
        $this->consume()->assertStatus(422);
    }

    public function test_existing_telegram_customer_login_preserves_canonical_account_and_admin_guard(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 456789, 'email' => null, 'email_verified_at' => null]);
        $admin = User::factory()->create();
        $this->actingAs($admin, 'web');
        $capability = $this->start(false);
        $this->prove($capability, '456789');
        $oldSession = session()->getId();
        $this->consume()->assertOk();
        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertAuthenticatedAs($admin, 'web');
        $this->assertNotSame($oldSession, session()->getId());
        $this->withCookie(config('session.cookie'), session()->getId())->get(route('customer.orders.index'))->assertOk();
        $this->assertSame(1, Customer::query()->count());
        $this->withCookie(config('session.cookie'), session()->getId())->post(route('customer.logout'));
        $this->assertGuest('customer');
        $this->assertNull(session('customer_telegram_authenticated'));
    }

    public function test_foreign_canonical_owner_and_existing_target_identity_fail_without_reassignment(): void
    {
        $customer = $this->emailCustomer();
        $foreign = Customer::factory()->create(['telegram_id' => 456789]);
        $this->freshLogin($customer);
        $capability = $this->start(true);
        $this->prove($capability, '456789', false);
        $this->consume()->assertStatus(422);
        $this->assertNull($customer->fresh()->telegram_id);
        $this->assertSame('456789', (string) $foreign->fresh()->telegram_id);
        $this->assertSame(2, Customer::query()->count());
        $customer->update(['telegram_id' => 777777]);
        $capability = $this->start(true);
        $this->prove($capability, '888888', false);
        $this->consume()->assertStatus(422);
        $this->assertSame('777777', (string) $customer->fresh()->telegram_id);
    }

    public function test_browser_session_customer_and_auth_version_changes_reject_confirmed_claims(): void
    {
        $customer = $this->emailCustomer();
        $this->freshLogin($customer);
        $capability = $this->start(true);
        $this->prove($capability, '456789');
        session()->migrate(true);
        $this->consume()->assertStatus(422);
        $this->assertNull($customer->fresh()->telegram_id);
        $this->freshLogin($customer);
        $capability = $this->start(true);
        $this->prove($capability, '456789');
        $foreign = Customer::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($foreign, 'customer');
        $this->consume()->assertStatus(422);
        $this->assertNull($customer->fresh()->telegram_id);
        $this->freshLogin($customer);
        $capability = $this->start(true);
        $this->prove($capability, '456789');
        $customer->forceFill(['auth_version' => 1])->save();
        $this->consume()->assertStatus(422);
        $this->assertNull($customer->fresh()->telegram_id);
    }

    public function test_expiry_and_stale_link_authentication_fail_closed(): void
    {
        $customer = $this->emailCustomer();
        $this->freshLogin($customer);
        session()->put('customer_authenticated_at', now()->subMinutes(6)->timestamp);
        session()->save();
        $this->withCookie(config('session.cookie'), session()->getId())->postJson(route('customer.telegram.link'))->assertStatus(422);
        $this->freshLogin($customer);
        $capability = $this->start(true);
        $this->prove($capability, '456789');
        $this->travel(300)->seconds();
        $this->claimStatus()->assertJson(['status' => 'expired']);
        $this->consume()->assertStatus(422);
        $this->assertNull($customer->fresh()->telegram_id);
    }

    public function test_status_discloses_no_identity_and_submitted_profile_facts_cannot_authenticate(): void
    {
        $this->getJson(route('customer.telegram.status'), ['telegram_id' => '456789'])->assertExactJson(['status' => 'invalid'])
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->postJson(route('customer.telegram.consume'), ['telegram_id' => '456789', 'username' => 'Tester', 'phone' => '123'])
            ->assertStatus(422)->assertExactJson(['status' => 'invalid']);
        $this->assertGuest('customer');
        $this->assertSame(0, Customer::query()->count());
        $capability = $this->start(false);
        $this->prove($capability, '456789', false);
        $this->consume()->assertStatus(422);
        $this->assertGuest('customer');
        $this->assertSame(0, Customer::query()->count());
    }

    public function test_limits_and_provider_unavailability_fail_closed(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->start(false);
        }
        $this->withCookie(config('session.cookie'), session()->getId())->postJson(route('customer.telegram.login'))->assertStatus(422);
        Cache::flush();
        config(['cache.limiter' => 'missing-auth-cache']);
        $this->postJson(route('customer.telegram.login'))->assertStatus(422);
        config(['cache.limiter' => null, 'services.telegram.token' => '']);
        $this->postJson(route('customer.telegram.login'))->assertStatus(422);
    }

    public function test_fresh_telegram_customer_enrolls_and_verifies_unused_email_on_the_same_account(): void
    {
        Queue::fake();
        $customer = Customer::factory()->create(['telegram_id' => 456789, 'email' => null, 'email_verified_at' => null]);
        $this->prove($this->start(false), '456789');
        $this->consume()->assertOk();
        $this->withCookie(config('session.cookie'), session()->getId())
            ->post(route('customer.telegram.email'), ['email' => 'new@example.test'])->assertSessionHas('customer_login_sent');
        $work = CustomerAuthMailWork::query()->sole();
        $this->assertSame($customer->id, $work->authToken->customer_id);
        $this->assertNull($customer->fresh()->email_verified_at);
        $this->withCookie(config('session.cookie'), session()->getId())
            ->post(route('customer.verification.consume'), ['token' => $work->payload['token']])
            ->assertRedirect(route('customer.orders.index'));
        $this->assertNotNull($customer->fresh()->email_verified_at);
        $this->assertNull($customer->fresh()->password);
        $this->assertSame('new@example.test', $customer->fresh()->email);
        $this->assertSame('456789', (string) $customer->fresh()->telegram_id);
        $this->assertSame(1, Customer::query()->count());
    }

    public function test_telegram_email_enrollment_rejects_foreign_email_and_old_proof(): void
    {
        Queue::fake();
        $foreign = Customer::factory()->create(['email' => 'owned@example.test', 'email_verified_at' => now()]);
        $customer = Customer::factory()->create(['telegram_id' => 456789, 'email' => null, 'email_verified_at' => null]);
        $this->prove($this->start(false), '456789');
        $this->consume()->assertOk();
        $this->withCookie(config('session.cookie'), session()->getId())
            ->post(route('customer.telegram.email'), ['email' => $foreign->email]);
        $this->assertNull($customer->fresh()->email);
        $this->travel(301)->seconds();
        $this->withCookie(config('session.cookie'), session()->getId())
            ->post(route('customer.telegram.email'), ['email' => 'new@example.test']);
        $this->assertNull($customer->fresh()->email);
        $this->assertSame(0, CustomerAuthMailWork::query()->count());
    }

    public function test_private_confirmation_is_actor_bound_and_groups_cannot_supply_proof(): void
    {
        $customer = $this->emailCustomer();
        $this->freshLogin($customer);
        $capability = $this->start(true);
        $service = app(CustomerTelegramAuthenticationService::class);
        $this->assertNull($service->prepare($capability, '456789', '-100123', 'supergroup'));
        $this->assertNull($service->prepare($capability, '456789', '123456', 'private'));
        $confirmation = $service->prepare($capability, '456789', '456789', 'private');
        $this->assertNotNull($confirmation);
        $this->assertFalse($service->confirm($confirmation, '123456', '123456', 'private'));
        $this->assertFalse($service->confirm($confirmation, '456789', '-100123', 'group'));
        $this->assertTrue($service->confirm($confirmation, '456789', '456789', 'private'));
        $this->consume()->assertOk();
        $this->assertSame('456789', (string) $customer->fresh()->telegram_id);
    }

    public function test_cleanup_is_bounded_and_keeps_live_telegram_claims(): void
    {
        $this->freezeTime();
        $this->start(false);
        $expiredId = session('customer_telegram_claim_id');
        $this->travel(300)->seconds();
        $this->start(false);
        $liveId = session('customer_telegram_claim_id');
        $this->artisan('customers:cleanup-login-links', ['--chunk' => 1])->assertSuccessful();
        $this->assertDatabaseHas('customer_telegram_auth_claims', ['id' => $expiredId]);
        $this->artisan('customer-auth:cleanup', ['--limit' => 1])->assertSuccessful();
        $this->assertDatabaseMissing('customer_telegram_auth_claims', ['id' => $expiredId]);
        $this->assertDatabaseHas('customer_telegram_auth_claims', ['id' => $liveId]);
    }

    public function test_status_and_private_proof_budgets_fail_closed(): void
    {
        $customer = $this->emailCustomer();
        $this->freshLogin($customer);
        $capability = $this->start(true);
        $service = app(CustomerTelegramAuthenticationService::class);
        $request = Request::create('/akun/telegram/status', server: ['REMOTE_ADDR' => '192.0.2.18']);
        $request->setLaravelSession(app('session')->driver());
        for ($i = 0; $i < 120; $i++) {
            $this->assertSame('pending', $service->status($request));
        }
        $this->assertSame('unavailable', $service->status($request));
        for ($i = 0; $i < 30; $i++) {
            $confirmation = $service->prepare($capability, '456789', '456789', 'private');
            $this->assertNotNull($confirmation);
        }
        $this->assertFalse($service->confirm($confirmation, '456789', '456789', 'private'));
        $this->assertNull($service->prepare($capability, '456789', '456789', 'private'));
        $this->assertNull($customer->fresh()->telegram_id);
    }

    private function emailCustomer(): Customer
    {
        return Customer::factory()->create(['telegram_id' => null, 'email_verified_at' => now()])->fresh();
    }

    private function freshLogin(Customer $customer): void
    {
        $this->actingAs($customer, 'customer')->withSession([
            'customer_authenticated_at' => now()->timestamp,
            'customer_auth_version' => $customer->auth_version,
        ]);
        session()->save();
    }

    private function start(bool $link): string
    {
        $response = $this->withCookie(config('session.cookie'), session()->getId())
            ->postJson(route($link ? 'customer.telegram.link' : 'customer.telegram.login'))->assertCreated();
        $this->assertSame(300, $response->json('expires_in'));
        parse_str(parse_url($response->json('deep_link'), PHP_URL_QUERY), $query);
        $this->assertMatchesRegularExpression('/\Awa_[A-Za-z0-9_-]{43}\z/', $query['start']);

        return $query['start'];
    }

    private function prove(string $capability, string $actor, bool $success = true): void
    {
        $service = app(CustomerTelegramAuthenticationService::class);
        $confirmation = $service->prepare($capability, $actor, $actor, 'private');
        if ($confirmation === null) {
            $this->assertFalse($success);

            return;
        }
        $this->assertSame($success, $service->confirm($confirmation, $actor, $actor, 'private'));
    }

    private function claimStatus()
    {
        return $this->withCookie(config('session.cookie'), session()->getId())->getJson(route('customer.telegram.status'));
    }

    private function consume()
    {
        return $this->withCookie(config('session.cookie'), session()->getId())->postJson(route('customer.telegram.consume'));
    }
}
