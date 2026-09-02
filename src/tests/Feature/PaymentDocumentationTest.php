<?php

namespace Tests\Feature;

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

    public function test_env_examples_keep_pakasir_credentials_without_mode_or_flow_switches(): void
    {
        $rootEnvExample = file_get_contents(base_path('../.env.example'));
        $appEnvExample = file_get_contents(base_path('.env.example'));

        foreach ([$rootEnvExample, $appEnvExample] as $envExample) {
            $this->assertStringNotContainsString('TELEGRAM_PAYMENT_MODE=', $envExample);
            $this->assertStringNotContainsString('PAKASIR_QRIS_ONLY=', $envExample);
            $this->assertStringNotContainsString('PAKASIR_CHECKOUT_FLOW=', $envExample);
            $this->assertStringContainsString('PAKASIR_SLUG=', $envExample);
            $this->assertStringContainsString('PAKASIR_API_KEY=', $envExample);
            $this->assertMatchesRegularExpression('/^PAKASIR_REDIRECT_URL=$/m', $envExample);
            $this->assertStringNotContainsString('PAKASIR_REDIRECT_URL=https://example.com/thanks', $envExample);
            $this->assertStringNotContainsString('PAKASIR_REDIRECT_URL=${APP_URL}', $envExample);
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

    public function test_klikqris_sandbox_configuration_is_forwarded_by_shared_compose_environment(): void
    {
        $compose = file_get_contents(base_path('../docker-compose.yml'));
        [$sharedAppEnvironment] = explode("\nservices:", $compose, 2);

        foreach ([
            'KLIKQRIS_SANDBOX_ENABLED',
            'KLIKQRIS_SANDBOX_BASE_URL',
            'KLIKQRIS_SANDBOX_API_KEY',
            'KLIKQRIS_SANDBOX_MERCHANT_ID',
            'KLIKQRIS_QRIS_TTL_MINUTES',
            'KLIKQRIS_HTTP_TIMEOUT_SECONDS',
            'KLIKQRIS_HTTP_CONNECT_TIMEOUT_SECONDS',
            'KLIKQRIS_STATUS_MAX_ATTEMPTS',
            'KLIKQRIS_RECOVERY_ALERT_MAX_ATTEMPTS',
            'KLIKQRIS_STATUS_RETRY_DELAY_MS',
            'KLIKQRIS_WEBHOOK_RATE_LIMIT_PER_MINUTE',
            'KLIKQRIS_STATUS_REFRESH_RATE_LIMIT_PER_MINUTE',
        ] as $variable) {
            $this->assertStringContainsString(
                '  '.$variable.': ${'.$variable,
                $sharedAppEnvironment,
                $variable.' must be forwarded to app and scheduler containers.',
            );
        }
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
