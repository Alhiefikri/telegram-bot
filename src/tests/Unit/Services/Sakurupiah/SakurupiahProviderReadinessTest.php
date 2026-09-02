<?php

namespace Tests\Unit\Services\Sakurupiah;

use App\Enums\SakurupiahEnvironment;
use App\Services\Sakurupiah\SakurupiahProviderReadiness;
use Tests\TestCase;

class SakurupiahProviderReadinessTest extends TestCase
{
    public function test_disabled_or_incomplete_environment_is_not_ready(): void
    {
        config()->set('services.sakurupiah', [
            'sandbox_enabled' => false,
            'sandbox_base_url' => 'https://sakurupiah.id/api-sanbox/',
            'sandbox_api_id' => 'api-test',
            'sandbox_api_key' => null,
        ]);

        $report = app(SakurupiahProviderReadiness::class)->report(SakurupiahEnvironment::Sandbox);

        $this->assertFalse($report->isReady());
        $this->assertContains('provider enabled flag', $report->missing());
        $this->assertContains('API key', $report->missing());
    }

    public function test_ready_sandbox_requires_the_provider_api_sanbox_path(): void
    {
        config()->set('services.sakurupiah', [
            'sandbox_enabled' => true,
            'sandbox_base_url' => 'https://sakurupiah.id/api/',
            'sandbox_api_id' => 'api-test',
            'sandbox_api_key' => 'secret-key',
        ]);

        $report = app(SakurupiahProviderReadiness::class)->report(SakurupiahEnvironment::Sandbox);

        $this->assertFalse($report->isReady());
        $this->assertArrayHasKey('base URL', $report->invalid());
    }

    public function test_production_configuration_is_rejected_outside_production_runtime(): void
    {
        config()->set('services.sakurupiah', [
            'production_enabled' => true,
            'production_base_url' => 'https://sakurupiah.id/api/',
            'production_api_id' => 'api-production',
            'production_api_key' => 'production-key',
        ]);

        $report = app(SakurupiahProviderReadiness::class)->production();

        $this->assertFalse($report->isReady());
        $this->assertArrayHasKey('APP_ENV', $report->invalid());
    }

    public function test_ready_sandbox_has_a_complete_separate_credential_pair(): void
    {
        config()->set('services.sakurupiah', [
            'sandbox_enabled' => true,
            'sandbox_base_url' => 'https://sakurupiah.id/api-sanbox/',
            'sandbox_api_id' => 'api-test',
            'sandbox_api_key' => 'secret-key',
        ]);

        $report = app(SakurupiahProviderReadiness::class)->report(SakurupiahEnvironment::Sandbox);

        $this->assertTrue($report->isReady());
    }
}
