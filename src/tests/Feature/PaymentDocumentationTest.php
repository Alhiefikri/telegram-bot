<?php

namespace Tests\Feature;

use Tests\TestCase;

class PaymentDocumentationTest extends TestCase
{
    public function test_operator_docs_describe_manual_payment_as_temporary_default_and_pakasir_as_toggleable(): void
    {
        $readme = file_get_contents(base_path('README.md'));

        $this->assertStringContainsString('default production sementara memakai pembayaran manual `/payment` selama KYC Pakasir', $readme);
        $this->assertStringContainsString('Pakasir', $readme);
        $this->assertStringContainsString('hosted-link checkout', $readme);
        $this->assertStringContainsString('API-key verification', $readme);
        $this->assertStringContainsString('Mode pembayaran', $readme);
    }

    public function test_env_examples_are_manual_first_while_pakasir_credentials_remain_available(): void
    {
        $rootEnvExample = file_get_contents(base_path('../.env.example'));
        $appEnvExample = file_get_contents(base_path('.env.example'));

        foreach ([$rootEnvExample, $appEnvExample] as $envExample) {
            $this->assertStringContainsString('TELEGRAM_PAYMENT_MODE=manual', $envExample);
            $this->assertStringContainsString('PAKASIR_SLUG=', $envExample);
            $this->assertStringContainsString('PAKASIR_API_KEY=', $envExample);
        }
    }
}
