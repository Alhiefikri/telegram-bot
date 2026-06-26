<?php

namespace Tests\Feature;

use Tests\TestCase;

class PaymentDocumentationTest extends TestCase
{
    public function test_operator_docs_describe_pakasir_as_active_gateway_and_tripay_as_legacy(): void
    {
        $readme = file_get_contents(base_path('README.md'));

        $this->assertStringContainsString('Pakasir', $readme);
        $this->assertStringContainsString('hosted-link checkout', $readme);
        $this->assertStringContainsString('API-key verification', $readme);
        $this->assertStringContainsString('Tripay legacy', $readme);

        $this->assertStringNotContainsString('Tripay: payment provider untuk jalur pembayaran otomatis', $readme);
        $this->assertStringNotContainsString('Reconcile Tripay payments', $readme);
        $this->assertStringNotContainsString('Credential Tripay terisi jika jalur Tripay dipakai', $readme);
    }

    public function test_env_examples_are_pakasir_first_and_mark_tripay_as_legacy_optional(): void
    {
        $rootEnvExample = file_get_contents(base_path('../.env.example'));
        $appEnvExample = file_get_contents(base_path('.env.example'));

        foreach ([$rootEnvExample, $appEnvExample] as $envExample) {
            $this->assertStringContainsString('TELEGRAM_PAYMENT_MODE=pakasir', $envExample);
            $this->assertStringContainsString('PAKASIR_SLUG=', $envExample);
            $this->assertStringContainsString('PAKASIR_API_KEY=', $envExample);
            $this->assertStringContainsString('TRIPAY_LEGACY_OPTIONAL', $envExample);
        }
    }
}
