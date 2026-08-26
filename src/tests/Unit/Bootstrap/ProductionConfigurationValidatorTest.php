<?php

namespace Tests\Unit\Bootstrap;

use App\Bootstrap\ProductionConfigurationValidator;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ProductionConfigurationValidatorTest extends TestCase
{
    public function test_it_skips_validation_outside_production_without_booting_laravel(): void
    {
        $validator = new ProductionConfigurationValidator(new Repository([
            'app' => ['env' => 'local'],
        ]));

        $validator->validate();

        $this->addToAssertionCount(1);
    }

    public function test_it_accepts_a_complete_secure_production_configuration(): void
    {
        $validator = new ProductionConfigurationValidator(new Repository($this->validConfiguration()));

        $validator->validate();

        $this->addToAssertionCount(1);
    }

    public function test_production_validation_ignores_removed_binance_canary_age_setting(): void
    {
        $configuration = $this->validConfiguration();
        $configuration['services']['binance']['canary_max_age_hours'] = 0;

        (new ProductionConfigurationValidator(new Repository($configuration)))->validate();

        $this->addToAssertionCount(1);
    }

    #[DataProvider('invalidProductionConfigurationProvider')]
    public function test_every_production_invariant_fails_closed_with_an_actionable_message(
        string $path,
        mixed $value,
        string $message,
    ): void {
        $configuration = $this->validConfiguration();
        data_set($configuration, $path, $value);
        $validator = new ProductionConfigurationValidator(new Repository($configuration));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        $validator->validate();
    }

    public static function invalidProductionConfigurationProvider(): array
    {
        return [
            'missing app key' => ['app.key', '', 'APP_KEY must be configured in production.'],
            'missing Telegram token' => ['services.telegram.token', '', 'TELEGRAM_BOT_TOKEN must be configured in production.'],
            'missing Telegram webhook secret' => ['services.telegram.webhook_secret', '', 'TELEGRAM_WEBHOOK_SECRET must be configured in production.'],
            'debug enabled' => ['app.debug', true, 'APP_DEBUG must be false in production.'],
            'Binance API key without secret' => ['services.binance.api_key', 'binance-key', 'BINANCE_API_KEY and BINANCE_API_SECRET must be configured together.'],
            'Binance API secret without key' => ['services.binance.api_secret', 'binance-secret', 'BINANCE_API_KEY and BINANCE_API_SECRET must be configured together.'],
            'insecure application URL' => ['app.url', 'http://store.example.test', 'APP_URL must use HTTPS in production.'],
            'example application URL' => ['app.url', 'https://example.com/', 'APP_URL must not use the example domain in production.'],
            'dogfood timing enabled' => ['services.dogfood_timing.enabled', true, 'Dogfood runtime flags are not allowed in production.'],
            'fake Telegram outbound enabled' => ['services.dogfood_timing.fake_telegram_outbound', true, 'Dogfood runtime flags are not allowed in production.'],
        ];
    }

    private function validConfiguration(): array
    {
        return [
            'app' => [
                'env' => 'production',
                'debug' => false,
                'url' => 'https://store.example.test',
                'key' => 'base64:test-key',
            ],
            'services' => [
                'telegram' => [
                    'token' => 'telegram-token',
                    'webhook_secret' => 'telegram-secret',
                ],
                'binance' => [
                    'api_key' => '',
                    'api_secret' => '',
                    'base_url' => 'https://api.binance.com',
                ],
                'dogfood_timing' => [
                    'enabled' => false,
                    'fake_telegram_outbound' => false,
                    'allow_runtime' => false,
                ],
            ],
        ];
    }
}
