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

        $pakasirFeePayer = (string) $this->config->get('services.pakasir.qris_fee.payer', 'customer');
        if (! in_array($pakasirFeePayer, ['customer', 'merchant'], true)) {
            throw new RuntimeException('PAKASIR_QRIS_FEE_PAYER must be customer or merchant.');
        }
        if ((int) $this->config->get('services.pakasir.qris_fee.threshold', 105_000) <= 0) {
            throw new RuntimeException('PAKASIR_QRIS_FEE_THRESHOLD must be a positive integer.');
        }
        foreach (['low_bps' => 70, 'low_fixed' => 310, 'high_bps' => 100, 'high_fixed' => 0] as $key => $default) {
            $value = $this->config->get('services.pakasir.qris_fee.'.$key, $default);
            if (! is_int($value) || $value < 0) {
                throw new RuntimeException('Pakasir QRIS fee configuration values must be non-negative integers.');
            }
            if ($value > 10_000) {
                throw new RuntimeException('Pakasir QRIS fee configuration exceeds production safety limits.');
            }
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

        if ((int) $this->config->get('services.binance.canary_max_age_hours', 24) < 1) {
            throw new RuntimeException('BINANCE_ORDER_ID_CANARY_MAX_AGE_HOURS must be a positive integer.');
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
