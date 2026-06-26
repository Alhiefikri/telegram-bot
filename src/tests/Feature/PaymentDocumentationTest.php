<?php

namespace Tests\Feature;

use Tests\TestCase;

class PaymentDocumentationTest extends TestCase
{
    public function test_operator_docs_describe_pakasir_as_the_only_active_gateway(): void
    {
        $readme = file_get_contents(base_path('README.md'));

        $this->assertStringContainsString('Pakasir', $readme);
        $this->assertStringContainsString('hosted-link checkout', $readme);
        $this->assertStringContainsString('API-key verification', $readme);
    }

    public function test_env_examples_are_pakasir_first(): void
    {
        $rootEnvExample = file_get_contents(base_path('../.env.example'));
        $appEnvExample = file_get_contents(base_path('.env.example'));

        foreach ([$rootEnvExample, $appEnvExample] as $envExample) {
            $this->assertStringContainsString('TELEGRAM_PAYMENT_MODE=pakasir', $envExample);
            $this->assertStringContainsString('PAKASIR_SLUG=', $envExample);
            $this->assertStringContainsString('PAKASIR_API_KEY=', $envExample);
        }
    }
}
