<?php

namespace App\Bootstrap;

use App\Services\Bsc\BscReadiness;
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

        $bscRpcUrl = (string) $this->config->get('services.bsc.rpc_url', '');
        if (! blank($bscRpcUrl) && ! str_starts_with($bscRpcUrl, 'https://')) {
            throw new RuntimeException('BSC_RPC_URL must use HTTPS in production.');
        }
        if ((int) $this->config->get('services.bsc.chain_id', 56) !== 56) {
            throw new RuntimeException('BSC_CHAIN_ID must be 56 in production.');
        }
        $bscContract = (string) $this->config->get('services.bsc.usdt_contract', '');
        if (! blank($bscContract) && preg_match('/^0x[0-9a-fA-F]{40}$/', $bscContract) !== 1) {
            throw new RuntimeException('BSC_USDT_CONTRACT must be a valid EVM address in production.');
        }
        if (! blank($bscContract) && strtolower($bscContract) !== BscReadiness::APPROVED_USDT_CONTRACT) {
            throw new RuntimeException('BSC_USDT_CONTRACT must be the approved BNB Smart Chain USDT contract in production.');
        }
        if ((int) $this->config->get('services.bsc.expected_token_decimals', 18) !== 18) {
            throw new RuntimeException('BSC_USDT_DECIMALS must be 18 for the approved BNB Smart Chain USDT contract.');
        }
    }
}
