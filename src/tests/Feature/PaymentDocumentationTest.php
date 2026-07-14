<?php

namespace Tests\Feature;

use Tests\TestCase;

class PaymentDocumentationTest extends TestCase
{
    public function test_operator_docs_describe_manual_default_and_pakasir_rollout_controls(): void
    {
        $readme = file_get_contents(base_path('README.md'));

        $this->assertStringContainsString('manual `/payment` tetap default operasional', $readme);
        $this->assertStringContainsString('Pakasir hosted checkout', $readme);
        $this->assertStringContainsString('Pakasir native QRIS', $readme);
        $this->assertStringContainsString('Transaction Detail API', $readme);
        $this->assertStringContainsString('rollback cepat ke hosted', $readme);
        $this->assertStringContainsString('Mode pembayaran', $readme);
        $this->assertStringContainsString('pakasir:validate-native-qris', $readme);
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
}
