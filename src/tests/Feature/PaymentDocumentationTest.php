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

    public function test_env_examples_are_manual_first_while_pakasir_credentials_remain_available(): void
    {
        $rootEnvExample = file_get_contents(base_path('../.env.example'));
        $appEnvExample = file_get_contents(base_path('.env.example'));

        foreach ([$rootEnvExample, $appEnvExample] as $envExample) {
            $this->assertStringContainsString('TELEGRAM_PAYMENT_MODE=manual', $envExample);
            $this->assertStringContainsString('PAKASIR_SLUG=', $envExample);
            $this->assertStringContainsString('PAKASIR_API_KEY=', $envExample);
            $this->assertStringContainsString('PAKASIR_CHECKOUT_FLOW=hosted', $envExample);
            $this->assertMatchesRegularExpression('/^PAKASIR_REDIRECT_URL=$/m', $envExample);
            $this->assertStringNotContainsString('PAKASIR_REDIRECT_URL=https://example.com/thanks', $envExample);
            $this->assertStringNotContainsString('PAKASIR_REDIRECT_URL=${APP_URL}', $envExample);
        }
    }
}
