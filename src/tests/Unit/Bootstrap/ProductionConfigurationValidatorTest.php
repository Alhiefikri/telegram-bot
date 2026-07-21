<?php

namespace Tests\Unit\Bootstrap;

use App\Bootstrap\ProductionConfigurationValidator;
use RuntimeException;
use Tests\TestCase;

class ProductionConfigurationValidatorTest extends TestCase
{
    public function test_it_skips_validation_outside_production(): void
    {
        config([
            'app.env' => 'local',
            'app.debug' => true,
            'app.url' => 'http://example.test',
            'app.key' => null,
            'services.telegram.token' => null,
            'services.telegram.webhook_secret' => null,
        ]);

        (new ProductionConfigurationValidator)->validate();

        $this->assertTrue(true);
    }

    public function test_it_fails_closed_when_a_required_production_value_is_missing(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.url' => 'https://store.example.test',
            'app.key' => 'base64:test-key',
            'services.telegram.token' => '',
            'services.telegram.webhook_secret' => 'telegram-secret',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TELEGRAM_BOT_TOKEN must be configured in production.');

        (new ProductionConfigurationValidator)->validate();
    }

    public function test_it_rejects_insecure_production_configuration(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => true,
            'app.url' => 'https://store.example.test',
            'app.key' => 'base64:test-key',
            'services.telegram.token' => 'telegram-token',
            'services.telegram.webhook_secret' => 'telegram-secret',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_DEBUG must be false in production.');

        (new ProductionConfigurationValidator)->validate();
    }
}
