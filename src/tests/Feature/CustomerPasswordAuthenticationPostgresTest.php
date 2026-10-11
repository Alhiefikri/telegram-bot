<?php

namespace Tests\Feature;

use App\Enums\CustomerAuthTokenPurpose;
use App\Models\Customer;
use App\Models\CustomerAuthMailWork;
use App\Models\CustomerAuthToken;
use App\Services\Customer\CustomerPasswordAuthenticationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RunsPostgresWorkers;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\RunsPostgresRace;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
class CustomerPasswordAuthenticationPostgresTest extends TestCase
{
    use RunsPostgresRace, RunsPostgresWorkers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requirePostgresConcurrency('Customer passwords');
        if (! Schema::hasTable('customer_auth_mail_works')) {
            $this->assertSame(0, PostgresTestDatabase::migrateFresh());
        }
        Queue::fake();
    }

    public function test_competing_registrations_preserve_one_customer_and_only_the_winning_credentials(): void
    {
        $email = bin2hex(random_bytes(12)).'@example.test';
        $results = $this->race(array_map(fn ($index) => function () use ($index, $email): string {
            app(CustomerPasswordAuthenticationService::class)->register($email, 'Distinct password '.$index, 'Enrollment '.$index);

            return 'generic';
        }, [0, 1, 2]));
        $this->assertSame(['generic', 'generic', 'generic'], $results);
        $customer = Customer::query()->where('email', $email)->sole();
        $challenge = CustomerAuthToken::query()->where('customer_id', $customer->id)->sole();
        $work = CustomerAuthMailWork::query()->where('auth_token_id', $challenge->id)->sole();
        $this->assertNull($customer->password);
        $this->assertNull($customer->email_verified_at);
        $this->assertTrue(app(CustomerPasswordAuthenticationService::class)->verify($work->payload['token'], $this->browserRequest()));
        $winner = (int) substr($customer->first_name, -1);
        Auth::guard('customer')->logout();
        $this->assertTrue(app(CustomerPasswordAuthenticationService::class)->login($email, 'Distinct password '.$winner, $this->browserRequest()));
        Auth::guard('customer')->logout();
        $this->assertFalse(app(CustomerPasswordAuthenticationService::class)->login($email, 'Distinct password '.(($winner + 1) % 3), $this->browserRequest()));
    }

    public function test_concurrent_verification_authenticates_only_one_browser(): void
    {
        $email = bin2hex(random_bytes(12)).'@example.test';
        app(CustomerPasswordAuthenticationService::class)->register($email, 'Concurrent verification password', null);
        $customer = Customer::query()->where('email', $email)->sole();
        $challenge = CustomerAuthToken::query()->where('customer_id', $customer->id)->sole();
        $token = CustomerAuthMailWork::query()->where('auth_token_id', $challenge->id)->sole()->payload['token'];

        $results = $this->race(array_fill(0, 3, fn () => app(CustomerPasswordAuthenticationService::class)->verify($token, $this->browserRequest()) ? 'authenticated' : 'rejected'));
        sort($results);
        $this->assertSame(['authenticated', 'rejected', 'rejected'], $results);
        $this->assertNotNull($customer->fresh()->email_verified_at);
        $this->assertNotNull($challenge->fresh()->consumed_at);
        $this->assertTrue(app(CustomerPasswordAuthenticationService::class)->login($email, 'Concurrent verification password', $this->browserRequest()));
    }

    public function test_concurrent_reset_consumption_changes_credentials_and_auth_version_once(): void
    {
        $customer = Customer::factory()->create(['email' => bin2hex(random_bytes(12)).'@example.test', 'email_verified_at' => now()]);
        $customer->forceFill(['password' => 'Original race password'])->save();
        app(CustomerPasswordAuthenticationService::class)->requestReset($customer->email);
        $challenge = CustomerAuthToken::query()->where('customer_id', $customer->id)->where('purpose', CustomerAuthTokenPurpose::PasswordReset->value)->sole();
        $token = CustomerAuthMailWork::query()->where('auth_token_id', $challenge->id)->sole()->payload['token'];

        $results = $this->race(array_fill(0, 3, fn () => app(CustomerPasswordAuthenticationService::class)->reset($token, 'Replacement race password', $this->browserRequest()) ? 'reset' : 'rejected'));
        sort($results);
        $this->assertSame(['rejected', 'rejected', 'reset'], $results);
        $this->assertSame(1, $customer->fresh()->auth_version);
        $this->assertNotNull($challenge->fresh()->consumed_at);
        $this->assertFalse(app(CustomerPasswordAuthenticationService::class)->login($customer->email, 'Original race password', $this->browserRequest()));
        $this->assertTrue(app(CustomerPasswordAuthenticationService::class)->login($customer->email, 'Replacement race password', $this->browserRequest()));
    }

    private function browserRequest(): Request
    {
        $request = Request::create('/akun', 'POST', server: ['REMOTE_ADDR' => '192.0.2.9']);
        $request->setLaravelSession(app('session')->driver());
        $request->session()->start();

        return $request;
    }
}
