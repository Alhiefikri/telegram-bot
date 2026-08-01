<?php

namespace App\Bootstrap;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

class ProductionConfigurationValidator
{
    public function __construct(private readonly Repository $config) {}

    public function validate(): void
    {
        $this->assertValid();
    }

    public function assertValid(): void
    {
        if (in_array($this->config->get('app.env'), ['local', 'testing'], true)) {
            return;
        }

        $required = [
            'APP_KEY' => $this->config->get('app.key'),
            'TELEGRAM_BOT_TOKEN' => $this->config->get('services.telegram.token'),
            'TELEGRAM_WEBHOOK_SECRET' => $this->config->get('services.telegram.webhook_secret'),
        ];

        foreach ($required as $name => $value) {
            if (blank($value)) {
                throw new RuntimeException("{$name} must be configured in production.");
            }
        }

        if ((bool) $this->config->get('app.debug')) {
            throw new RuntimeException('APP_DEBUG must be false in production.');
        }

        $pakasirBaseUrl = (string) $this->config->get('services.pakasir.base_url', '');
        if (! blank($pakasirBaseUrl) && ! str_starts_with($pakasirBaseUrl, 'https://')) {
            throw new RuntimeException('PAKASIR_BASE_URL must use HTTPS in production.');
        }

        $binanceBaseUrl = (string) $this->config->get('services.binance.base_url', '');
        if (! blank($binanceBaseUrl) && ! str_starts_with($binanceBaseUrl, 'https://')) {
            throw new RuntimeException('BINANCE_API_BASE_URL must use HTTPS in production.');
        }

        $binanceApiKeyConfigured = filled($this->config->get('services.binance.api_key'));
        $binanceApiSecretConfigured = filled($this->config->get('services.binance.api_secret'));
        if ($binanceApiKeyConfigured !== $binanceApiSecretConfigured) {
            throw new RuntimeException('BINANCE_API_KEY and BINANCE_API_SECRET must be configured together.');
        }

        if ((string) $this->config->get('services.binance.verification_mode') === 'order_id'
            && $binanceApiKeyConfigured
            && ! filled($this->config->get('services.binance.pay_id'))
            && ! filled($this->config->get('services.binance.pay_uid'))) {
            throw new RuntimeException('BINANCE_PAY_ID or BINANCE_PAY_UID must be configured for Order ID verification.');
        }

        if (! str_starts_with((string) $this->config->get('app.url'), 'https://')) {
            throw new RuntimeException('APP_URL must use HTTPS in production.');
        }

        if (in_array(rtrim((string) $this->config->get('app.url'), '/'), ['https://example.com'], true)) {
            throw new RuntimeException('APP_URL must not use the example domain in production.');
        }

        if (((bool) $this->config->get('services.dogfood_timing.enabled') || (bool) $this->config->get('services.dogfood_timing.fake_telegram_outbound')) && ! (bool) $this->config->get('services.dogfood_timing.allow_runtime')) {
            throw new RuntimeException('Dogfood runtime flags are not allowed in production.');
        }
    }
}
