<?php

namespace Tests\Unit\Bootstrap;

use App\Bootstrap\ProductionConfigurationValidator;
use App\Services\Sakurupiah\SakurupiahContractPolicy;
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

    public function test_enabled_sakurupiah_requires_a_complete_credential_pair(): void
    {
        $configuration = $this->validConfiguration();
        $configuration['services']['sakurupiah'] = [
            'production_enabled' => true,
            'production_api_id' => 'production-id',
            'production_api_key' => '',
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SAKURUPIAH_PRODUCTION_API_ID and SAKURUPIAH_PRODUCTION_API_KEY must be configured together.');

        (new ProductionConfigurationValidator(new Repository($configuration)))->validate();
    }

    public function test_enabled_sakurupiah_rejects_insecure_production_urls(): void
    {
        $configuration = $this->validConfiguration();
        $configuration['services']['sakurupiah'] = array_replace(
            $this->validSakurupiahProductionConfiguration(),
            ['production_base_url' => 'http://sakurupiah.id/api/'],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SAKURUPIAH_PRODUCTION_BASE_URL must use a safe HTTPS host');

        (new ProductionConfigurationValidator(new Repository($configuration)))->validate();
    }

    public function test_enabled_sakurupiah_rejects_callback_hosts_outside_the_allowlist(): void
    {
        $configuration = $this->validConfiguration();
        $configuration['services']['sakurupiah'] = array_replace(
            $this->validSakurupiahProductionConfiguration(),
            ['production_callback_url' => 'https://evil.example.test/api/webhook/sakurupiah'],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SAKURUPIAH_PRODUCTION_CALLBACK_URL must use a safe HTTPS host');

        (new ProductionConfigurationValidator(new Repository($configuration)))->validate();
    }

    public function test_enabled_sakurupiah_rejects_unqualified_qris_contract(): void
    {
        $configuration = $this->validConfiguration();
        $configuration['services']['sakurupiah'] = array_replace(
            $this->validSakurupiahProductionConfiguration(),
            ['qris_contract' => ['currency_confirmed' => false]],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SAKURUPIAH_QRIS_CURRENCY_CONTRACT must be qualified');

        (new ProductionConfigurationValidator(new Repository($configuration)))->validate();
    }

    public function test_enabled_sakurupiah_rejects_inconsistent_qris_fee_mapping(): void
    {
        $configuration = $this->validConfiguration();
        $configuration['services']['sakurupiah'] = array_replace(
            $this->validSakurupiahProductionConfiguration(),
            ['qris_contract' => [
                'currency' => 'IDR',
                'currency_confirmed' => true,
                'fee_payer' => 'customer',
                'merchant_fee' => 1,
                'fee_confirmed' => true,
                'expiry_hours' => 24,
                'expiry_confirmed' => true,
            ]],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SAKURUPIAH_QRIS_FEE_CONTRACT must be qualified');

        (new ProductionConfigurationValidator(new Repository($configuration)))->validate();
    }

    public function test_enabled_sakurupiah_rejects_an_unqualified_external_contract_gate(): void
    {
        $configuration = $this->validConfiguration();
        $policy = $this->qualifiedSakurupiahContractPolicy();
        $policy['gates']['amount_evidence'] = false;
        $configuration['services']['sakurupiah'] = array_replace(
            $this->validSakurupiahProductionConfiguration(),
            ['contract_policy' => $policy],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SAKURUPIAH_EXTERNAL_AMOUNT_EVIDENCE');

        (new ProductionConfigurationValidator(new Repository($configuration)))->validate();
    }

    public function test_enabled_sakurupiah_rejects_missing_owner_approval_reference(): void
    {
        $configuration = $this->validConfiguration();
        $policy = $this->qualifiedSakurupiahContractPolicy();
        $policy['owner_approval_reference'] = null;
        $configuration['services']['sakurupiah'] = array_replace(
            $this->validSakurupiahProductionConfiguration(),
            ['contract_policy' => $policy],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('redacted Owner approval reference');

        (new ProductionConfigurationValidator(new Repository($configuration)))->validate();
    }

    public function test_sakurupiah_sandbox_and_production_credentials_cannot_be_reused(): void
    {
        $configuration = $this->validConfiguration();
        $sakurupiah = $this->validSakurupiahProductionConfiguration();
        $sakurupiah['sandbox_api_id'] = $sakurupiah['production_api_id'];
        $sakurupiah['sandbox_api_key'] = $sakurupiah['production_api_key'];
        $configuration['services']['sakurupiah'] = $sakurupiah;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must use different credentials');

        (new ProductionConfigurationValidator(new Repository($configuration)))->validate();
    }

    public function test_enabled_sakurupiah_rejects_private_production_ip_allowlists(): void
    {
        $configuration = $this->validConfiguration();
        $configuration['services']['sakurupiah'] = array_replace(
            $this->validSakurupiahProductionConfiguration(),
            ['production_allowed_ips' => ['10.0.0.1']],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SAKURUPIAH_PRODUCTION_ALLOWED_IPS must contain public IP addresses or CIDR ranges.');

        (new ProductionConfigurationValidator(new Repository($configuration)))->validate();
    }

    public function test_enabled_sakurupiah_rejects_unbounded_production_cidr_allowlists(): void
    {
        $configuration = $this->validConfiguration();
        $configuration['services']['sakurupiah'] = array_replace(
            $this->validSakurupiahProductionConfiguration(),
            ['production_allowed_ips' => ['8.8.8.8/0']],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SAKURUPIAH_PRODUCTION_ALLOWED_IPS must contain public IP addresses or CIDR ranges.');

        (new ProductionConfigurationValidator(new Repository($configuration)))->validate();
    }

    /**
     * @return array<string, mixed>
     */
    private function validSakurupiahProductionConfiguration(): array
    {
        return [
            'sandbox_enabled' => false,
            'sandbox_api_id' => '',
            'sandbox_api_key' => '',
            'production_enabled' => true,
            'production_base_url' => 'https://sakurupiah.id/api/',
            'production_api_id' => 'production-id',
            'production_api_key' => 'production-key',
            'production_callback_url' => 'https://store.example.test/api/webhook/sakurupiah',
            'production_return_url' => 'https://store.example.test/shop/orders',
            'production_allowed_hosts' => ['sakurupiah.id'],
            'production_callback_allowed_hosts' => ['store.example.test'],
            'production_return_allowed_hosts' => ['store.example.test'],
            'production_allowed_ips' => ['8.8.8.8'],
            'http_timeout_seconds' => 10.0,
            'http_connect_timeout_seconds' => 3.0,
            'lookup_max_attempts' => 3,
            'lookup_retry_delay_ms' => 250,
            'lookup_retry_max_delay_ms' => 1000,
            'lock_lease_seconds' => 90,
            'webhook_rate_limit_per_minute' => 30,
            'status_refresh_rate_limit_per_minute' => 3,
            'readiness_cache_seconds' => 60,
            'timezone' => 'Asia/Jakarta',
            'qris_contract' => [
                'currency' => 'IDR',
                'currency_confirmed' => true,
                'fee_payer' => 'merchant',
                'merchant_fee' => 1,
                'fee_confirmed' => true,
                'expiry_hours' => 24,
                'expiry_confirmed' => true,
            ],
            'contract_policy' => $this->qualifiedSakurupiahContractPolicy(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function qualifiedSakurupiahContractPolicy(): array
    {
        $evidenceReferences = [];
        foreach (array_keys(SakurupiahContractPolicy::REQUIRED_GATES) as $gate) {
            $evidenceReferences[$gate] = 'issue-415-redacted-'.$gate;
        }

        return [
            'record_version' => SakurupiahContractPolicy::CURRENT_RECORD_VERSION,
            'record_reference' => 'issue-415-redacted-evidence',
            'gates' => array_fill_keys(array_keys(SakurupiahContractPolicy::REQUIRED_GATES), true),
            'evidence_references' => $evidenceReferences,
            'automatic_fulfillment_approved' => true,
            'owner_approval_reference' => 'issue-415-owner-approval',
        ];
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
            'insecure Pakasir URL' => ['services.pakasir.base_url', 'http://pakasir.example.test', 'PAKASIR_BASE_URL must use HTTPS in production.'],
            'unsupported Pakasir QRIS fee payer' => ['services.pakasir.qris_fee.payer', 'buyer', 'PAKASIR_QRIS_FEE_PAYER must be customer or merchant.'],
            'nonpositive Pakasir QRIS threshold' => ['services.pakasir.qris_fee.threshold', 0, 'PAKASIR_QRIS_FEE_THRESHOLD must be a positive integer.'],
            'negative Pakasir QRIS low rate' => ['services.pakasir.qris_fee.low_bps', -1, 'Pakasir QRIS fee configuration values must be non-negative integers.'],
            'excessive Pakasir QRIS rate' => ['services.pakasir.qris_fee.high_bps', 10_001, 'Pakasir QRIS fee configuration exceeds production safety limits.'],
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
                'pakasir' => [
                    'base_url' => 'https://pakasir.example.test',
                    'qris_fee' => [
                        'payer' => 'customer',
                        'threshold' => 105_000,
                        'low_bps' => 70,
                        'low_fixed' => 310,
                        'high_bps' => 100,
                        'high_fixed' => 0,
                    ],
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
