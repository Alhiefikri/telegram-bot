<?php

namespace Tests\Feature;

use Illuminate\Support\Env;
use Tests\TestCase;

class PaymentDocumentationTest extends TestCase
{
    public function test_operator_docs_describe_manual_default_and_pakasir_retirement_controls(): void
    {
        $readme = file_get_contents(base_path('README.md'));

        $this->assertStringContainsString('Payment Provider Switch', $readme);
        $this->assertStringContainsString('Pakasir legacy settlement', $readme);
        $this->assertStringContainsString('Pakasir is retired for new checkout', $readme);
        $this->assertStringContainsString('Transaction Detail verification', $readme);
        $this->assertStringContainsString('pakasir:retirement-status --json', $readme);
        $this->assertStringContainsString('Mode pembayaran', $readme);
        $this->assertStringNotContainsString('pakasir:validate-native-qris', $readme);
        $this->assertStringNotContainsString('selama KYC Pakasir', $readme);
    }

    public function test_env_examples_keep_provider_credentials_without_mode_or_transport_switches(): void
    {
        $rootEnvExample = file_get_contents(base_path('../.env.example'));
        $appEnvExample = file_get_contents(base_path('.env.example'));

        foreach ([$rootEnvExample, $appEnvExample] as $envExample) {
            foreach (['PAKASIR_SLUG', 'PAKASIR_API_KEY'] as $variable) {
                $this->assertStringContainsString($variable.'=', $envExample);
            }
            foreach ([
                'TELEGRAM_PAYMENT_MODE',
                'PAKASIR_QRIS_ONLY',
                'PAKASIR_CHECKOUT_FLOW',
                'PAKASIR_BASE_URL',
                'PAKASIR_REDIRECT_URL',
                'PAKASIR_QRIS_FEE_PAYER',
                'PAKASIR_HTTP_TIMEOUT_SECONDS',
            ] as $variable) {
                $this->assertStringNotContainsString($variable.'=', $envExample);
            }
        }
    }

    public function test_sakurupiah_runtime_configuration_keeps_only_credential_pairs_in_env(): void
    {
        $envExamples = [
            'root .env.example' => file_get_contents(base_path('../.env.example')),
            'src/.env.example' => file_get_contents(base_path('.env.example')),
        ];
        $compose = file_get_contents(base_path('../docker-compose.yml'));
        [$sharedAppEnvironment] = explode("\nservices:", $compose, 2);
        $phpunit = file_get_contents(base_path('phpunit.xml'));
        $credentialVariables = [
            'SAKURUPIAH_SANDBOX_API_ID',
            'SAKURUPIAH_SANDBOX_API_KEY',
            'SAKURUPIAH_PRODUCTION_API_ID',
            'SAKURUPIAH_PRODUCTION_API_KEY',
        ];
        $nonEnvironmentSettings = [
            'SAKURUPIAH_SANDBOX_ENABLED',
            'SAKURUPIAH_SANDBOX_BASE_URL',
            'SAKURUPIAH_SANDBOX_CALLBACK_URL',
            'SAKURUPIAH_SANDBOX_RETURN_URL',
            'SAKURUPIAH_SANDBOX_ALLOWED_HOSTS',
            'SAKURUPIAH_SANDBOX_CALLBACK_ALLOWED_HOSTS',
            'SAKURUPIAH_SANDBOX_RETURN_ALLOWED_HOSTS',
            'SAKURUPIAH_SANDBOX_ALLOWED_IPS',
            'SAKURUPIAH_PRODUCTION_ENABLED',
            'SAKURUPIAH_PRODUCTION_BASE_URL',
            'SAKURUPIAH_PRODUCTION_CALLBACK_URL',
            'SAKURUPIAH_PRODUCTION_RETURN_URL',
            'SAKURUPIAH_PRODUCTION_ALLOWED_HOSTS',
            'SAKURUPIAH_PRODUCTION_CALLBACK_ALLOWED_HOSTS',
            'SAKURUPIAH_PRODUCTION_RETURN_ALLOWED_HOSTS',
            'SAKURUPIAH_PRODUCTION_ALLOWED_IPS',
            'SAKURUPIAH_TIMEZONE',
            'SAKURUPIAH_HTTP_TIMEOUT_SECONDS',
            'SAKURUPIAH_HTTP_CONNECT_TIMEOUT_SECONDS',
            'SAKURUPIAH_LOOKUP_MAX_ATTEMPTS',
            'SAKURUPIAH_LOOKUP_RETRY_DELAY_MS',
            'SAKURUPIAH_LOOKUP_RETRY_MAX_DELAY_MS',
            'SAKURUPIAH_LOCK_LEASE_SECONDS',
            'SAKURUPIAH_WEBHOOK_RATE_LIMIT_PER_MINUTE',
            'SAKURUPIAH_STATUS_REFRESH_RATE_LIMIT_PER_MINUTE',
            'SAKURUPIAH_READINESS_CACHE_SECONDS',
            'SAKURUPIAH_RECONCILE_TERMINAL_LOOKBACK_HOURS',
            'SAKURUPIAH_RECONCILE_MAX_EXECUTION_SECONDS',
        ];

        foreach ($envExamples as $name => $envExample) {
            foreach ($credentialVariables as $variable) {
                $this->assertMatchesRegularExpression(
                    '/^'.preg_quote($variable, '/').'=$/m',
                    $envExample,
                    $name.' must document '.$variable.' as a blank secret input.',
                );
            }
            foreach ($nonEnvironmentSettings as $variable) {
                $this->assertStringNotContainsString($variable.'=', $envExample, $name.' must not expose '.$variable.' as ENV.');
            }
            foreach ([
                'SAKURUPIAH_QRIS_CURRENCY',
                'SAKURUPIAH_QRIS_FEE_PAYER',
                'SAKURUPIAH_QRIS_EXPIRY_HOURS',
                'SAKURUPIAH_CHANNEL_LABEL',
            ] as $presentationVariable) {
                $this->assertStringNotContainsString($presentationVariable.'=', $envExample);
            }
        }

        foreach ($credentialVariables as $variable) {
            $this->assertStringContainsString(
                '  '.$variable.': ${'.$variable,
                $sharedAppEnvironment,
                $variable.' must be forwarded to app and scheduler containers.',
            );
        }
        foreach ($nonEnvironmentSettings as $variable) {
            $this->assertStringNotContainsString(
                $variable.': ${'.$variable,
                $sharedAppEnvironment,
                $variable.' must not be forwarded from ENV.',
            );
        }
        foreach ($credentialVariables as $variable) {
            $this->assertSame(
                2,
                preg_match_all(
                    '/<(?:env|server) name="'.preg_quote($variable, '/').'" value="" force="true"\/>/',
                    $phpunit,
                ),
                $variable.' must be isolated in both env and server test scopes.',
            );
        }

        $this->assertFalse(config('services.sakurupiah.production_enabled'));
        $this->assertSame('https://sakurupiah.id/api/', config('services.sakurupiah.production_base_url'));
        $this->assertSame(
            'https://lhiestore.web.id/api/webhook/sakurupiah',
            config('services.sakurupiah.production_callback_url'),
        );
        $this->assertSame('https://lhiestore.web.id/shop/orders', config('services.sakurupiah.production_return_url'));
        $this->assertSame(['sakurupiah.id'], config('services.sakurupiah.production_allowed_hosts'));
        $this->assertSame(['lhiestore.web.id'], config('services.sakurupiah.production_callback_allowed_hosts'));
        $this->assertSame(['lhiestore.web.id'], config('services.sakurupiah.production_return_allowed_hosts'));
        $this->assertSame([], config('services.sakurupiah.production_allowed_ips'));
    }

    public function test_sakurupiah_production_enablement_follows_complete_credential_pair(): void
    {
        $keys = [
            'SAKURUPIAH_PRODUCTION_API_ID',
            'SAKURUPIAH_PRODUCTION_API_KEY',
        ];
        $original = [];
        foreach ($keys as $key) {
            $original[$key] = [
                'env_present' => array_key_exists($key, $_ENV),
                'env' => $_ENV[$key] ?? null,
                'server_present' => array_key_exists($key, $_SERVER),
                'server' => $_SERVER[$key] ?? null,
                'getenv' => getenv($key),
            ];
        }

        try {
            foreach ($keys as $key) {
                $_ENV[$key] = 'production-'.$key;
                $_SERVER[$key] = 'production-'.$key;
                putenv($key.'=production-'.$key);
            }
            Env::enablePutenv();
            $services = require config_path('services.php');
            $this->assertTrue($services['sakurupiah']['production_enabled']);

            $_ENV['SAKURUPIAH_PRODUCTION_API_KEY'] = '';
            $_SERVER['SAKURUPIAH_PRODUCTION_API_KEY'] = '';
            putenv('SAKURUPIAH_PRODUCTION_API_KEY=');
            Env::enablePutenv();
            $services = require config_path('services.php');
            $this->assertFalse($services['sakurupiah']['production_enabled']);
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
                if ($values['getenv'] === false) {
                    putenv($key);
                } else {
                    putenv($key.'='.$values['getenv']);
                }
            }
            Env::enablePutenv();
        }
    }

    public function test_binance_pay_id_is_blank_in_env_examples_and_forwarded_by_shared_compose_environment(): void
    {
        $envExamples = [
            'root .env.example' => file_get_contents(base_path('../.env.example')),
            'src/.env.example' => file_get_contents(base_path('.env.example')),
        ];
        $compose = file_get_contents(base_path('../docker-compose.yml'));
        [$sharedAppEnvironment] = explode("\nservices:", $compose, 2);
        $violations = [];

        foreach ($envExamples as $name => $envExample) {
            if (preg_match('/^BINANCE_PAY_ID=$/m', $envExample) !== 1) {
                $violations[] = $name.' must document BINANCE_PAY_ID with an empty value.';
            }
        }

        if (! str_contains($sharedAppEnvironment, '  BINANCE_PAY_ID: ${BINANCE_PAY_ID:-}')) {
            $violations[] = 'docker-compose.yml shared x-app-environment must forward BINANCE_PAY_ID to app and scheduler.';
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }

    public function test_klikqris_examples_and_compose_keep_only_provider_credentials(): void
    {
        $envExamples = [
            file_get_contents(base_path('../.env.example')),
            file_get_contents(base_path('.env.example')),
        ];
        $compose = file_get_contents(base_path('../docker-compose.yml'));
        [$sharedAppEnvironment] = explode("\nservices:", $compose, 2);
        $credentialVariables = [
            'KLIKQRIS_SANDBOX_API_KEY',
            'KLIKQRIS_SANDBOX_MERCHANT_ID',
            'KLIKQRIS_PRODUCTION_API_KEY',
            'KLIKQRIS_PRODUCTION_MERCHANT_ID',
        ];
        $nonEnvironmentSettings = [
            'KLIKQRIS_SANDBOX_ENABLED',
            'KLIKQRIS_SANDBOX_BASE_URL',
            'KLIKQRIS_SANDBOX_CALLBACK_URL',
            'KLIKQRIS_SANDBOX_CALLBACK_ALLOWED_HOSTS',
            'KLIKQRIS_PRODUCTION_ENABLED',
            'KLIKQRIS_PRODUCTION_BASE_URL',
            'KLIKQRIS_PRODUCTION_CALLBACK_URL',
            'KLIKQRIS_PRODUCTION_ALLOWED_HOSTS',
            'KLIKQRIS_PRODUCTION_CALLBACK_ALLOWED_HOSTS',
            'KLIKQRIS_QRIS_TTL_MINUTES',
            'KLIKQRIS_HTTP_TIMEOUT_SECONDS',
            'KLIKQRIS_HTTP_CONNECT_TIMEOUT_SECONDS',
            'KLIKQRIS_STATUS_MAX_ATTEMPTS',
            'KLIKQRIS_RECOVERY_ALERT_MAX_ATTEMPTS',
            'KLIKQRIS_STATUS_RETRY_DELAY_MS',
            'KLIKQRIS_WEBHOOK_RATE_LIMIT_PER_MINUTE',
            'KLIKQRIS_STATUS_REFRESH_RATE_LIMIT_PER_MINUTE',
            'KLIKQRIS_RECONCILE_TERMINAL_LOOKBACK_HOURS',
            'KLIKQRIS_RECONCILE_MAX_EXECUTION_SECONDS',
            'KLIKQRIS_RECONCILE_CURSOR_SCAN_CAP',
            'KLIKQRIS_REPORTING_CACHE_SECONDS',
            'KLIKQRIS_REPORTING_MAX_LIMIT',
        ];

        foreach ($envExamples as $envExample) {
            foreach ($credentialVariables as $variable) {
                $this->assertMatchesRegularExpression('/^'.preg_quote($variable, '/').'=$/m', $envExample);
            }
            foreach ($nonEnvironmentSettings as $variable) {
                $this->assertStringNotContainsString($variable.'=', $envExample);
            }
        }

        foreach ($credentialVariables as $variable) {
            $this->assertStringContainsString('  '.$variable.': ${'.$variable, $sharedAppEnvironment);
        }
        foreach ($nonEnvironmentSettings as $variable) {
            $this->assertStringNotContainsString($variable.': ${'.$variable, $sharedAppEnvironment);
        }

        $this->assertSame('https://klikqris.com/api/sandbox', config('services.klikqris.sandbox_base_url'));
        $this->assertSame('https://klikqris.com/api', config('services.klikqris.production_base_url'));
        $this->assertFalse(config('services.klikqris.production_enabled'));
    }

    public function test_sakurupiah_runbook_documents_operations_and_rollback(): void
    {
        $runbook = file_get_contents(base_path('../docs/runbook-sakurupiah-payment.md'));

        foreach ([
            'sakurupiah:check-readiness --json',
            'sakurupiah:reconcile --chunk=100',
            'ops:health',
            'verification_pending',
            'Outcome-unknown recovery',
            'Callback duplicates',
            'Late payment',
            'Stop-new-checkout',
            'Rollback',
            'Telegram testing-bot boundary',
            'getMe',
            '--force-recreate --no-build',
            'does not require rebuilding',
            'production bot',
            'sakurupiah-release-evidence.md',
            'complete production credential pair',
            'Admin → Mode pembayaran',
        ] as $section) {
            $this->assertStringContainsString($section, $runbook);
        }
        $this->assertStringContainsString('production_enabled', $runbook);
        $this->assertStringNotContainsString('contract_policy', $runbook);
        $this->assertStringNotContainsString('Adding credentials alone never selects Sakurupiah', $runbook);
        $this->assertStringContainsString('Docker Compose reads the', $runbook);
        $this->assertStringContainsString('Laravel Artisan uses `src`', $runbook);
        $this->assertStringNotContainsString('RAILWAY_', $runbook);
    }

    public function test_sakurupiah_release_evidence_records_commands_and_activation_status(): void
    {
        $evidence = file_get_contents(base_path('../docs/sakurupiah-release-evidence.md'));

        foreach ([
            'issue #416',
            'NOT ACTIVATED',
            'credential pair',
            'composer test',
            'test:concurrency-gate',
            '127.0.0.1:5432',
            'npx playwright test',
            'default',
            'one paid transition produces one sale',
        ] as $section) {
            $this->assertStringContainsString($section, $evidence);
        }
        $this->assertStringNotContainsString('RAILWAY_', $evidence);
        $this->assertStringNotContainsString('automatic_fulfillment_approved: true', $evidence);
        $this->assertStringNotContainsString('external qualification gate', $evidence);
    }

    public function test_bsc_operator_contract_documents_database_activation_and_shared_queue_configuration(): void
    {
        $readme = file_get_contents(base_path('README.md'));
        $runbook = file_get_contents(base_path('../docs/runbook-bsc-payment.md'));
        $compose = file_get_contents(base_path('../docker-compose.yml'));

        $this->assertStringContainsString('bsc:check-readiness --json', $readme);
        $this->assertStringContainsString('ManualFulfillmentReview', $readme);
        $this->assertStringContainsString('BSC_RPC_URL', $runbook);
        $this->assertStringContainsString('approved USDT contract', $runbook);
        $this->assertStringContainsString('payment_bsc_details', $runbook);
        $this->assertStringContainsString('BSC_RPC_URL', $compose);
        $this->assertStringNotContainsString('BSC_USDT_DECIMALS', $compose);
        $this->assertStringNotContainsString('BSC_PENDING_BACKLOG_ALERT_THRESHOLD', $compose);
        $this->assertStringNotContainsString('RAILWAY_', $runbook);
    }

    public function test_bsc_environment_examples_keep_only_the_rpc_credential(): void
    {
        $envExamples = [
            file_get_contents(base_path('../.env.example')),
            file_get_contents(base_path('.env.example')),
        ];
        $compose = file_get_contents(base_path('../docker-compose.yml'));
        [$sharedAppEnvironment] = explode("\nservices:", $compose, 2);
        $nonEnvironmentSettings = [
            'BSC_INDEXED_SOURCE',
            'BSC_INDEXER_URL',
            'BSC_CHAIN_ID',
            'BSC_USDT_CONTRACT',
            'BSC_USDT_DECIMALS',
            'BSC_EXPLORER_BASE_URL',
            'BSC_EXPLORER_ALLOWED_HOSTS',
            'BSC_RPC_TIMEOUT_SECONDS',
            'BSC_RPC_CONNECT_TIMEOUT_SECONDS',
            'BSC_RPC_MAX_ATTEMPTS',
            'BSC_RPC_RETRY_DELAY_MS',
            'BSC_MAX_SCAN_BLOCKS',
            'BSC_MAX_SCAN_LOGS',
            'BSC_PENDING_BACKLOG_ALERT_THRESHOLD',
            'BSC_LATE_PAYMENT_LOOKBACK_HOURS',
            'BSC_OWNER_ALERT_COOLDOWN_SECONDS',
            'BSC_READINESS_CACHE_SECONDS',
        ];

        foreach ($envExamples as $envExample) {
            $this->assertMatchesRegularExpression('/^BSC_RPC_URL=$/m', $envExample);
            foreach ($nonEnvironmentSettings as $variable) {
                $this->assertStringNotContainsString($variable.'=', $envExample);
            }
            $this->assertStringNotContainsString('BSC_RECEIVER_ADDRESS=', $envExample);
        }

        $this->assertStringContainsString(
            '  BSC_RPC_URL: ${BSC_RPC_URL:-}',
            $sharedAppEnvironment,
        );
        foreach ($nonEnvironmentSettings as $variable) {
            $this->assertStringNotContainsString($variable.': ${'.$variable, $sharedAppEnvironment);
        }
    }
}
