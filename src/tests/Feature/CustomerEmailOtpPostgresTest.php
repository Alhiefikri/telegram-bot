<?php

namespace Tests\Feature;

use App\Enums\CustomerAuthTokenPurpose;
use App\Models\Customer;
use App\Models\CustomerAuthMailWork;
use App\Models\CustomerAuthToken;
use App\Services\Customer\CustomerEmailOtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RunsPostgresWorkers;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\RunsPostgresRace;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
class CustomerEmailOtpPostgresTest extends TestCase
{
    use RunsPostgresRace, RunsPostgresWorkers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requirePostgresConcurrency('Customer email OTP');
        if (! Schema::hasTable('customer_auth_mail_works')) {
            $this->assertSame(0, PostgresTestDatabase::migrateFresh());
        }
        Queue::fake();
    }

    public function test_concurrent_code_consumption_authenticates_exactly_one_browser(): void
    {
        [$customer, $challenge, $code, $token, $binding] = $this->issue();
        $results = $this->race(array_fill(0, 3, fn () => app(CustomerEmailOtpService::class)->consume(
            $customer->email, $challenge, $code, $this->browserRequest($binding),
        ) ? 'authenticated' : 'rejected'));
        sort($results);
        $this->assertSame(['authenticated', 'rejected', 'rejected'], $results);
        $this->assertNotNull($token->fresh()->consumed_at);
    }

    public function test_concurrent_wrong_attempts_stop_at_five_and_cannot_then_authenticate(): void
    {
        [$customer, $challenge, $code, $token, $binding] = $this->issue();
        $wrong = $code === '000000' ? '000001' : '000000';
        $results = $this->race(array_fill(0, 6, fn () => app(CustomerEmailOtpService::class)->consume(
            $customer->email, $challenge, $wrong, $this->browserRequest($binding),
        ) ? 'authenticated' : 'rejected'));
        $this->assertSame(array_fill(0, 6, 'rejected'), $results);
        $this->assertSame(5, $token->fresh()->attempts);
        $this->assertFalse(app(CustomerEmailOtpService::class)->consume($customer->email, $challenge, $code, $this->browserRequest($binding)));
    }

    public function test_competing_issuance_cannot_leave_multiple_active_codes_even_with_process_local_cache(): void
    {
        $customer = Customer::factory()->create(['email' => bin2hex(random_bytes(12)).'@example.test', 'email_verified_at' => now()]);
        $results = $this->race(array_fill(0, 3, fn () => app(CustomerEmailOtpService::class)->requestCode($customer->email, $this->browserRequest()) !== null ? 'issued' : 'limited'));
        sort($results);
        $this->assertSame(['issued', 'limited', 'limited'], $results);
        $token = CustomerAuthToken::query()->where('customer_id', $customer->id)->where('purpose', CustomerAuthTokenPurpose::EmailOtp->value)->sole();
        $this->assertTrue($token->isActiveFor(CustomerAuthTokenPurpose::EmailOtp));
        $this->assertSame(1, CustomerAuthMailWork::query()->where('auth_token_id', $token->id)->count());
    }

    private function issue(): array
    {
        $customer = Customer::factory()->create(['email' => bin2hex(random_bytes(12)).'@example.test', 'email_verified_at' => now()]);
        $request = $this->browserRequest();
        $challenge = app(CustomerEmailOtpService::class)->requestCode($customer->email, $request);
        $token = CustomerAuthToken::query()->where('customer_id', $customer->id)->where('purpose', CustomerAuthTokenPurpose::EmailOtp->value)->sole();
        $code = CustomerAuthMailWork::query()->where('auth_token_id', $token->id)->sole()->payload['token'];

        return [$customer, $challenge, $code, $token, $request->session()->get('customer_otp_binding')];
    }

    private function browserRequest(?string $binding = null): Request
    {
        $request = Request::create('/akun/login-code', 'POST', server: ['REMOTE_ADDR' => '192.0.2.10']);
        $request->setLaravelSession(app('session')->driver());
        $request->session()->start();
        if ($binding !== null) {
            $request->session()->put('customer_otp_binding', $binding);
        }

        return $request;
    }
}
