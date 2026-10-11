<?php

namespace Tests\Feature;

use Illuminate\Mail\Mailer;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Mail;
use Mockery;
use RuntimeException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\TestCase;

class CustomerAuthMailOperationsTest extends TestCase
{
    public function test_secret_backed_resend_configuration_builds_starttls_not_implicit_tls(): void
    {
        $mail = $this->mailConfiguration([
            'MAIL_MAILER' => 'smtp',
            'MAIL_SCHEME' => 'smtp',
            'MAIL_HOST' => 'smtp.resend.com',
            'MAIL_PORT' => '587',
            'MAIL_USERNAME' => 'resend',
            'MAIL_PASSWORD' => 'disposable-provider-key',
            'MAIL_FROM_ADDRESS' => 'no-reply@notify.lhiestore.web.id',
        ]);
        config()->set('mail', $mail);
        Mail::purge('smtp');
        $transport = Mail::mailer('smtp')->getSymfonyTransport();
        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertTrue($transport->isAutoTls());
        $this->assertTrue($transport->isTlsRequired());
        $this->assertFalse($transport->getStream()->isTLS());
        $this->assertSame('smtp.resend.com', $transport->getStream()->getHost());
        $this->assertSame(587, $transport->getStream()->getPort());
        $this->assertSame('resend', $transport->getUsername());
        $this->assertSame('disposable-provider-key', $transport->getPassword());
        $this->assertGreaterThan(0, $mail['mailers']['smtp']['timeout']);
        $this->assertLessThan(55, $mail['mailers']['smtp']['timeout']);
        $this->assertSame('no-reply@notify.lhiestore.web.id', $mail['from']['address']);
    }

    public function test_local_smtp_override_remains_usable_without_requiring_resend_tls(): void
    {
        $mail = $this->mailConfiguration(['MAIL_HOST' => 'mailpit', 'MAIL_PORT' => '1025', 'MAIL_SCHEME' => 'smtp']);
        $this->assertFalse($mail['mailers']['smtp']['require_tls']);
        config()->set('mail', $mail);
        Mail::purge('smtp');
        $transport = Mail::mailer('smtp')->getSymfonyTransport();
        $this->assertFalse($transport->isTlsRequired());
        $this->assertSame(1025, $transport->getStream()->getPort());
    }

    public function test_smoke_refuses_production_and_missing_operator_approval_without_sending(): void
    {
        Mail::shouldReceive('mailer')->never();
        $this->app['env'] = 'production';
        $this->artisan('customer-auth:smoke-mail', $this->approval())->assertFailed();
        $this->app['env'] = 'staging';
        $this->artisan('customer-auth:smoke-mail')->assertFailed();
        $this->artisan('customer-auth:smoke-mail', ['--confirm-real-send' => true])->assertFailed();
    }

    public function test_smoke_rejects_fake_mailers_and_unsafe_resend_configuration(): void
    {
        Mail::shouldReceive('mailer')->never();
        foreach ([
            ['mail.default' => 'array'],
            ['mail.default' => 'log'],
            ['mail.default' => 'failover'],
            ['mail.mailers.smtp.scheme' => 'tls'],
            ['mail.mailers.smtp.port' => 465],
            ['mail.mailers.smtp.password' => ''],
            ['mail.mailers.smtp.require_tls' => false],
            ['mail.mailers.smtp.auto_tls' => false],
            ['mail.mailers.smtp.url' => 'smtp://alternate.invalid'],
            ['mail.from.address' => 'unverified@example.test'],
        ] as $override) {
            $this->configureSmoke();
            config()->set($override);
            $this->artisan('customer-auth:smoke-mail', $this->approval())->assertFailed();
        }
    }

    public function test_smoke_does_not_expose_provider_exceptions_or_secret_recipients(): void
    {
        $this->configureSmoke();
        $mailer = Mockery::mock(Mailer::class);
        Mail::shouldReceive('mailer')->once()->with('smtp')->andReturn($mailer);
        $mailer->shouldReceive('raw')->once()->andThrow(new RuntimeException('approved-inbox@example.test disposable-provider-key raw-auth-code'));
        $this->artisan('customer-auth:smoke-mail', $this->approval())
            ->expectsQuestion('Approved non-production inbox (hidden)', 'approved-inbox@example.test')
            ->doesntExpectOutputToContain('approved-inbox@example.test')
            ->doesntExpectOutputToContain('disposable-provider-key')
            ->doesntExpectOutputToContain('raw-auth-code')
            ->assertFailed();
    }

    public function test_cancelled_mail_send_and_invalid_inbox_never_claim_smtp_acceptance(): void
    {
        $this->configureSmoke();
        $mailer = Mockery::mock(Mailer::class);
        Mail::shouldReceive('mailer')->once()->with('smtp')->andReturn($mailer);
        $mailer->shouldReceive('raw')->once()->andReturnNull();
        $this->artisan('customer-auth:smoke-mail', $this->approval())
            ->expectsQuestion('Approved non-production inbox (hidden)', 'approved-inbox@example.test')
            ->assertFailed();
        $this->artisan('customer-auth:smoke-mail', $this->approval())
            ->expectsQuestion('Approved non-production inbox (hidden)', 'not-an-email')
            ->assertFailed();
    }

    private function approval(): array
    {
        return ['--confirm-real-send' => true, '--confirm-tracking-disabled' => true];
    }

    private function configureSmoke(): void
    {
        $this->app['env'] = 'staging';
        config()->set('mail.default', 'smtp');
        config()->set('mail.from.address', 'no-reply@notify.lhiestore.web.id');
        config()->set('mail.mailers.smtp', [
            'transport' => 'smtp', 'scheme' => 'smtp', 'host' => 'smtp.resend.com', 'port' => 587,
            'username' => 'resend', 'password' => 'disposable-provider-key', 'auto_tls' => true, 'require_tls' => true,
        ]);
    }

    private function mailConfiguration(array $overrides): array
    {
        $original = [];
        foreach ($overrides as $key => $value) {
            $original[$key] = [
                'env_present' => array_key_exists($key, $_ENV), 'env' => $_ENV[$key] ?? null,
                'server_present' => array_key_exists($key, $_SERVER), 'server' => $_SERVER[$key] ?? null,
                'getenv' => getenv($key),
            ];
        }
        try {
            foreach ($overrides as $key => $value) {
                $_ENV[$key] = $_SERVER[$key] = $value;
                putenv($key.'='.$value);
            }
            Env::enablePutenv();

            return require config_path('mail.php');
        } finally {
            foreach ($original as $key => $values) {
                if ($values['env_present']) {
                    $_ENV[$key] = $values['env'];
                } else {
                    unset($_ENV[$key]);
                }
                if ($values['server_present']) {
                    $_SERVER[$key] = $values['server'];
                } else {
                    unset($_SERVER[$key]);
                }
                $values['getenv'] === false ? putenv($key) : putenv($key.'='.$values['getenv']);
            }
            Env::enablePutenv();
        }
    }
}
