<?php

namespace Tests\Feature;

use App\Enums\CustomerAuthTokenPurpose;
use App\Jobs\SendCustomerAuthMail;
use App\Models\Customer;
use App\Models\CustomerAuthMailWork;
use App\Models\CustomerAuthToken;
use App\Models\CustomerLoginLink;
use App\Notifications\CustomerAuthMailNotification;
use App\Notifications\CustomerLoginLinkNotification;
use App\Services\Customer\CustomerAuthMailDeliveryService;
use App\Services\Customer\CustomerEmailOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

class CustomerAuthMailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_otp_mail_preserves_leading_zeroes_and_never_puts_code_in_a_url(): void
    {
        Notification::fake();
        [$token, $work, $code] = $this->work(CustomerAuthTokenPurpose::EmailOtp);
        $this->assertStringNotContainsString($code, $work->getRawOriginal('payload'));
        $this->assertStringNotContainsString($token->email, $work->getRawOriginal('payload'));
        $this->assertArrayNotHasKey('payload', $work->toArray());

        app(CustomerAuthMailDeliveryService::class)->deliver($work->id);

        Notification::assertSentOnDemand(CustomerAuthMailNotification::class, function ($notification) use ($code): bool {
            $mail = $notification->toMail((object) []);

            return in_array($code, $mail->introLines, true) && $mail->actionUrl === null;
        });
        $this->assertSame('sent', $work->fresh()->status);
        $this->assertNull($work->fresh()->payload);
        $this->assertSame(0, $token->fresh()->attempts);
        $this->assertNull($token->fresh()->consumed_at);
    }

    public function test_secret_bearing_notification_queue_bodies_and_id_work_are_encrypted(): void
    {
        $code = '004281';
        $linkToken = bin2hex(random_bytes(32));
        $notifiable = (new AnonymousNotifiable)->route('mail', 'secret-recipient@example.test');
        $notifications = [
            new CustomerAuthMailNotification(CustomerAuthTokenPurpose::EmailOtp, $code),
            new CustomerAuthMailNotification(CustomerAuthTokenPurpose::EmailVerification, $linkToken),
            new CustomerAuthMailNotification(CustomerAuthTokenPurpose::PasswordReset, $linkToken),
            new CustomerLoginLinkNotification(route('customer.login-link.show').'#token='.$linkToken),
        ];
        $encoder = new class extends SyncQueue
        {
            public function encode(object $job): string
            {
                return $this->createPayload($job, 'notifications');
            }
        };
        $encoder->setContainer($this->app);
        foreach ($notifications as $notification) {
            $this->assertSame('notifications', $notification->queue);
            $encoded = $encoder->encode(new SendQueuedNotifications($notifiable, $notification, ['mail']));
            foreach ([$code, $linkToken, 'secret-recipient@example.test'] as $secret) {
                $this->assertStringNotContainsString($secret, $encoded);
            }
            $payload = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
            $decoded = unserialize($this->app['encrypter']->decrypt($payload['data']['command']));
            $this->assertInstanceOf(SendQueuedNotifications::class, $decoded);
            $this->assertSame($notification->toMail($notifiable)->toArray(), $decoded->notification->toMail($notifiable)->toArray());
        }
        $payload = json_decode($encoder->encode(new SendCustomerAuthMail(42)), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(42, unserialize($this->app['encrypter']->decrypt($payload['data']['command']))->workId);
    }

    public function test_payload_must_match_six_digit_code_and_bound_challenge_digest(): void
    {
        Notification::fake();
        foreach (['12345', '1234567', '12a456', '999999', 'wrong_email', 'wrong_challenge'] as $case) {
            [$token, $work, $code] = $this->work(CustomerAuthTokenPurpose::EmailOtp, $case === 'wrong_challenge');
            $work->forceFill(['payload' => [
                'email' => $case === 'wrong_email' ? 'other@example.test' : $token->email,
                'token' => in_array($case, ['wrong_email', 'wrong_challenge'], true) ? $code : $case,
            ]])->save();
            app(CustomerAuthMailDeliveryService::class)->deliver($work->id);
            $this->assertSame('failed', $work->fresh()->status);
            $this->assertNull($work->fresh()->payload);
        }
        Notification::assertNothingSent();
    }

    public function test_consumed_exhausted_expired_and_version_invalid_work_is_never_sent(): void
    {
        Notification::fake();
        foreach (['consumed', 'exhausted', 'auth_version', 'enrollment_version', 'email', 'email_verified_at'] as $case) {
            [$token, $work] = $this->work(CustomerAuthTokenPurpose::EmailOtp);
            if ($case === 'consumed') {
                $token->forceFill(['consumed_at' => now()])->save();
            } elseif ($case === 'exhausted') {
                $token->forceFill(['attempts' => 5])->save();
            } elseif ($case === 'email_verified_at') {
                $token->customer->forceFill(['email_verified_at' => null])->save();
            } else {
                $token->customer->forceFill([$case => $case === 'email' ? 'changed@example.test' : $token->$case + 1])->save();
            }
            app(CustomerAuthMailDeliveryService::class)->deliver($work->id);
            $this->assertSame('cancelled', $work->fresh()->status);
            $this->assertNull($work->fresh()->payload);
        }
        [, $work] = $this->work(CustomerAuthTokenPurpose::EmailOtp);
        $this->travel(10)->minutes();
        app(CustomerAuthMailDeliveryService::class)->deliver($work->id);
        $this->assertSame('cancelled', $work->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_provider_failure_is_sanitized_and_retry_reuses_the_same_grant(): void
    {
        [$token, $work, $code] = $this->work(CustomerAuthTokenPurpose::EmailOtp);
        Log::spy();
        Mail::mailer()->setSymfonyTransport(new class extends ArrayTransport
        {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new RuntimeException('SMTP provider rejected '.$message->toString().' API key resend-secret');
            }
        });
        $mail = app(CustomerAuthMailDeliveryService::class);
        try {
            $mail->deliver($work->id);
            $this->fail('A provider failure must surface as a safe failed-job exception.');
        } catch (RuntimeException $error) {
            foreach ([$code, $token->email, 'resend-secret'] as $secret) {
                $this->assertStringNotContainsString($secret, $error->getMessage());
                $this->assertStringNotContainsString($secret, $error->getTraceAsString());
            }
            $this->assertNull($error->getPrevious());
        }
        $this->assertSame('pending', $work->fresh()->status);
        $this->assertSame(1, $work->fresh()->attempts);
        $this->assertSame($code, $work->fresh()->payload['token']);
        $this->assertSame('mail_delivery_failed', $work->fresh()->last_error_code);
        Log::shouldHaveReceived('warning')->once()->with('customer.auth_mail_failed', ['work_id' => $work->id, 'outcome' => 'delivery_unavailable']);

        $transport = new ArrayTransport;
        Mail::mailer()->setSymfonyTransport($transport);
        $mail->deliver($work->id);
        $this->assertCount(0, $transport->messages());
        $this->travel(60)->seconds();
        $mail->deliver($work->id);
        $this->assertCount(1, $transport->messages());
        $this->assertStringContainsString($code, $transport->messages()->sole()->toString());
        $this->assertSame('sent', $work->fresh()->status);
        $this->assertNull($work->fresh()->payload);
        $this->assertSame(1, CustomerAuthToken::query()->count());
        $this->assertNull($token->fresh()->consumed_at);
    }

    public function test_five_provider_failures_clear_secret_without_minting_another_token(): void
    {
        [$token, $work] = $this->work(CustomerAuthTokenPurpose::PasswordReset);
        Mail::mailer()->setSymfonyTransport(new class extends ArrayTransport
        {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new RuntimeException('Provider unavailable.');
            }
        });
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                app(CustomerAuthMailDeliveryService::class)->deliver($work->id);
                $this->fail('Delivery must fail.');
            } catch (RuntimeException) {
                $this->assertSame($attempt, $work->fresh()->attempts);
            }
            $this->travelTo($work->fresh()->next_attempt_at);
        }
        $this->assertSame('failed', $work->fresh()->status);
        $this->assertNull($work->fresh()->payload);
        $this->assertSame(1, CustomerAuthToken::query()->count());
        $this->assertNull($token->fresh()->consumed_at);
    }

    public function test_queue_dispatch_failure_keeps_encrypted_work_for_recovery(): void
    {
        [, $work] = $this->work(CustomerAuthTokenPurpose::EmailVerification);
        $dispatcher = Bus::getFacadeRoot();
        Log::spy();
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Redis payload contains private provider data'));
        $this->assertSame(0, app(CustomerAuthMailDeliveryService::class)->recover());
        $this->assertSame('pending', $work->fresh()->status);
        $this->assertSame(0, $work->fresh()->attempts);
        Log::shouldHaveReceived('warning')->once()->with('customer.auth_mail_dispatch_failed', ['work_id' => $work->id, 'outcome' => 'queue_unavailable']);
        Bus::swap($dispatcher);
        $this->assertSame(1, app(CustomerAuthMailDeliveryService::class)->recover());
        Queue::assertPushed(SendCustomerAuthMail::class, fn ($job) => $job->workId === $work->id);
        $this->assertSame(1, CustomerAuthToken::query()->count());
    }

    public function test_logging_mailer_and_logging_failover_are_blocked_before_message_rendering(): void
    {
        Notification::fake();
        foreach (['log', 'unsafe-failover'] as $name) {
            config(['mail.default' => $name, 'mail.mailers.unsafe-failover' => ['transport' => 'failover', 'mailers' => ['array', 'log']]]);
            [, $work] = $this->work(CustomerAuthTokenPurpose::PasswordReset);
            try {
                app(CustomerAuthMailDeliveryService::class)->deliver($work->id);
                $this->fail('Logging authentication messages must be blocked.');
            } catch (RuntimeException $error) {
                $this->assertNull($error->getPrevious());
            }
            $this->assertSame('pending', $work->fresh()->status);
            $this->assertSame('mail_delivery_failed', $work->fresh()->last_error_code);
        }
        Notification::assertNothingSent();
    }

    public function test_recovery_redispatches_lost_wakeup_and_abandoned_lease_without_new_grants(): void
    {
        Notification::fake();
        [$token, $work] = $this->work(CustomerAuthTokenPurpose::EmailOtp);
        $work->forceFill(['attempts' => 1, 'next_attempt_at' => now()->addSeconds(120)])->save();
        $mail = app(CustomerAuthMailDeliveryService::class);
        $this->assertSame(0, $mail->recover());
        $this->travel(120)->seconds();
        $this->assertSame(1, $mail->recover());
        Queue::assertPushedOn('notifications', SendCustomerAuthMail::class, fn ($job) => $job->workId === $work->id);
        $mail->deliver($work->id);
        $this->assertSame('sent', $work->fresh()->status);
        $this->assertSame(2, $work->fresh()->attempts);
        $this->assertSame(1, CustomerAuthToken::query()->count());
        $this->assertNull($token->fresh()->consumed_at);
    }

    public function test_recovery_clears_revoked_work_even_during_backoff(): void
    {
        Notification::fake();
        [$token, $work] = $this->work(CustomerAuthTokenPurpose::EmailOtp);
        $token->forceFill(['consumed_at' => now()])->save();
        $work->forceFill(['next_attempt_at' => now()->addMinutes(5)])->save();
        $this->post(route('customer.login-link.request'), ['email' => 'unknown@example.test']);
        $link = CustomerLoginLink::query()->sole();
        $legacy = CustomerAuthMailWork::query()->where('login_link_id', $link->id)->sole();
        $link->forceFill(['used_at' => now()])->save();
        $legacy->forceFill(['next_attempt_at' => now()->addMinutes(5)])->save();
        $this->assertSame(0, app(CustomerAuthMailDeliveryService::class)->recover());
        foreach ([$work, $legacy] as $revoked) {
            $this->assertSame('cancelled', $revoked->fresh()->status);
            $this->assertNull($revoked->fresh()->payload);
        }
        Notification::assertNothingSent();
    }

    public function test_legacy_unknown_email_durable_delivery_and_consumption_remain_compatible(): void
    {
        Notification::fake();
        $this->post(route('customer.login-link.request'), ['email' => ' Unknown@Example.TEST '])
            ->assertRedirect(route('customer.account'))->assertSessionHas('customer_login_sent');
        $this->assertSame(0, Customer::query()->count());
        $link = CustomerLoginLink::query()->sole();
        $work = CustomerAuthMailWork::query()->sole();
        $plainToken = $work->payload['token'];
        $this->assertNull($work->auth_token_id);
        $this->assertSame($link->id, $work->login_link_id);
        $this->assertSame(hash('sha256', $plainToken), $link->token_hash);
        $this->assertStringNotContainsString($plainToken, $work->getRawOriginal('payload'));
        $this->assertSame(1, app(CustomerAuthMailDeliveryService::class)->recover());
        app(CustomerAuthMailDeliveryService::class)->deliver($work->id);
        Notification::assertSentOnDemand(CustomerLoginLinkNotification::class, fn ($notification) => $notification->toMail((object) [])->actionUrl === route('customer.login-link.show').'#token='.$plainToken);
        $this->post(route('customer.login-link.consume'), ['token' => $plainToken])->assertRedirect(route('customer.orders.index'));
        $this->assertAuthenticated('customer');
        $this->assertGuest('web');
        $this->assertNotNull($link->fresh()->used_at);
        $this->post(route('customer.login-link.consume'), ['token' => $plainToken])->assertSessionHas('customer_login_error');
    }

    public function test_reset_revokes_pending_legacy_mail_before_it_can_be_sent(): void
    {
        Notification::fake();
        [$reset, $resetWork, $plainReset] = $this->work(CustomerAuthTokenPurpose::PasswordReset);
        $this->post(route('customer.login-link.request'), ['email' => $reset->email]);
        $link = CustomerLoginLink::query()->sole();
        $work = CustomerAuthMailWork::query()->where('login_link_id', $link->id)->sole();
        $this->post(route('customer.password-reset.consume'), [
            'token' => $plainReset,
            'password' => 'New customer password after reset1!',
            'password_confirmation' => 'New customer password after reset1!',
        ])->assertRedirect(route('customer.account'));
        $this->assertNotNull($link->fresh()->used_at);
        $this->assertSame(0, app(CustomerAuthMailDeliveryService::class)->recover());
        foreach ([$work, $resetWork] as $revoked) {
            $this->assertSame('cancelled', $revoked->fresh()->status);
            $this->assertNull($revoked->fresh()->payload);
        }
        Notification::assertNothingSent();
    }

    private function work(CustomerAuthTokenPurpose $purpose, bool $wrongChallenge = false): array
    {
        $customer = Customer::factory()->create(['email' => bin2hex(random_bytes(8)).'@example.test', 'email_verified_at' => $purpose === CustomerAuthTokenPurpose::EmailVerification ? null : now()])->fresh();
        $challengeHash = hash('sha256', random_bytes(32));
        $plain = $purpose === CustomerAuthTokenPurpose::EmailOtp ? '004281' : bin2hex(random_bytes(32));
        $token = new CustomerAuthToken;
        $token->forceFill([
            'customer_id' => $customer->id, 'email' => $customer->email, 'purpose' => $purpose,
            'token_hash' => $purpose === CustomerAuthTokenPurpose::EmailOtp ? CustomerEmailOtpService::codeDigest($customer->email, $challengeHash, $plain) : hash('sha256', $plain),
            'challenge_hash' => $purpose === CustomerAuthTokenPurpose::EmailOtp ? ($wrongChallenge ? hash('sha256', random_bytes(32)) : $challengeHash) : null,
            'session_binding_hash' => $purpose === CustomerAuthTokenPurpose::EmailOtp ? hash('sha256', random_bytes(32)) : null,
            'auth_version' => $customer->auth_version, 'enrollment_version' => $customer->enrollment_version,
            'expires_at' => now()->addSeconds($purpose->ttlSeconds()),
        ])->save();
        app(CustomerAuthMailDeliveryService::class)->persist($token, $plain);

        return [$token, CustomerAuthMailWork::query()->where('auth_token_id', $token->id)->sole(), $plain];
    }
}
