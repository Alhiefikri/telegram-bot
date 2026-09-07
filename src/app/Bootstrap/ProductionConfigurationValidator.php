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
        $this->validateSakurupiah();
    }

    private function validateSakurupiah(): void
    {
        $runtimeEnvironment = (string) $this->config->get('app.env', 'production');
        foreach (['sandbox', 'production'] as $environment) {
            $this->assertSakurupiahCredentialPair(
                $environment,
                required: $environment === $runtimeEnvironment
                    && $this->config->get('services.sakurupiah.'.$environment.'_enabled', false) === true,
            );
        }

        $productionEnabled = $this->config->get('services.sakurupiah.production_enabled', false) === true;
        $productionHosts = $this->sakurupiahHosts('production_allowed_hosts');
        if ($productionEnabled && $productionHosts === []) {
            throw new RuntimeException('SAKURUPIAH_PRODUCTION_ALLOWED_HOSTS must be configured when Sakurupiah production is enabled.');
        }

        $productionBaseUrl = $this->config->get('services.sakurupiah.production_base_url');
        if ($productionEnabled && blank($productionBaseUrl)) {
            throw new RuntimeException('SAKURUPIAH_PRODUCTION_BASE_URL must be configured when Sakurupiah production is enabled.');
        }
        if (! blank($productionBaseUrl)) {
            $this->assertSakurupiahUrl(
                $productionBaseUrl,
                'SAKURUPIAH_PRODUCTION_BASE_URL',
                '/api',
                $productionHosts !== [] ? $productionHosts : ['sakurupiah.id'],
            );
        }

        $sandboxBaseUrl = $this->config->get('services.sakurupiah.sandbox_base_url');
        if (! blank($sandboxBaseUrl)) {
            $this->assertSakurupiahUrl(
                $sandboxBaseUrl,
                'SAKURUPIAH_SANDBOX_BASE_URL',
                '/api-sanbox',
                $this->sakurupiahHosts('sandbox_allowed_hosts') ?: ['sakurupiah.id'],
            );
        }

        foreach ([
            'production_callback_url' => ['SAKURUPIAH_PRODUCTION_CALLBACK_URL', '/api/webhook/sakurupiah', 'production_callback_allowed_hosts'],
            'production_return_url' => ['SAKURUPIAH_PRODUCTION_RETURN_URL', null, 'production_return_allowed_hosts'],
        ] as $field => [$label, $path, $hostField]) {
            $value = $this->config->get('services.sakurupiah.'.$field);
            $hosts = $this->sakurupiahHosts($hostField);
            if ($productionEnabled && blank($value)) {
                throw new RuntimeException($label.' must be configured when Sakurupiah production is enabled.');
            }
            if ($productionEnabled && $hosts === []) {
                throw new RuntimeException('SAKURUPIAH_'.strtoupper($hostField).' must be configured when Sakurupiah production is enabled.');
            }
            if (! blank($value)) {
                $this->assertSakurupiahUrl($value, $label, $path, $hosts);
            }
        }
        $productionIps = $this->sakurupiahHosts('production_allowed_ips');

        foreach ($productionIps as $ip) {
            if (! $this->validSakurupiahIp($ip)) {
                throw new RuntimeException('SAKURUPIAH_PRODUCTION_ALLOWED_IPS must contain public IP addresses or CIDR ranges.');
            }
        }

        $this->assertSakurupiahDistinctCredentials();
        $this->assertSakurupiahTransportConfiguration();

        if ($productionEnabled) {
            $contract = (array) $this->config->get('services.sakurupiah.qris_contract', []);
            if (($contract['currency'] ?? null) !== 'IDR' || ($contract['currency_confirmed'] ?? false) !== true) {
                throw new RuntimeException('SAKURUPIAH_QRIS_CURRENCY_CONTRACT must be qualified before production enablement.');
            }
            $feePayer = $contract['fee_payer'] ?? null;
            $merchantFee = $contract['merchant_fee'] ?? null;
            if (! in_array($feePayer, ['merchant', 'customer'], true)
                || ($feePayer === 'merchant' && $merchantFee !== 1)
                || ($feePayer === 'customer' && $merchantFee !== 2)
                || ($contract['fee_confirmed'] ?? false) !== true
            ) {
                throw new RuntimeException('SAKURUPIAH_QRIS_FEE_CONTRACT must be qualified before production enablement.');
            }
            if (($contract['expiry_confirmed'] ?? false) !== true
                || ! is_int($contract['expiry_hours'] ?? null)
                || $contract['expiry_hours'] < 1
            ) {
                throw new RuntimeException('SAKURUPIAH_QRIS_EXPIRY_CONTRACT must be qualified before production enablement.');
            }
        }
    }

    private function assertSakurupiahCredentialPair(string $environment, bool $required = false): void
    {
        $prefix = strtoupper($environment);
        $apiId = $this->config->get('services.sakurupiah.'.$environment.'_api_id');
        $apiKey = $this->config->get('services.sakurupiah.'.$environment.'_api_key');
        $idConfigured = is_string($apiId) && filled($apiId);
        $keyConfigured = is_string($apiKey) && filled($apiKey);

        if ($idConfigured !== $keyConfigured) {
            throw new RuntimeException("SAKURUPIAH_{$prefix}_API_ID and SAKURUPIAH_{$prefix}_API_KEY must be configured together.");
        }
        if ($required && ! $idConfigured) {
            throw new RuntimeException("SAKURUPIAH_{$prefix}_API_ID and SAKURUPIAH_{$prefix}_API_KEY must be configured when Sakurupiah {$environment} is enabled.");
        }
        if (is_string($apiId) && trim($apiId) !== $apiId) {
            throw new RuntimeException("SAKURUPIAH_{$prefix}_API_ID must not contain surrounding whitespace.");
        }
        if (is_string($apiKey) && trim($apiKey) !== $apiKey) {
            throw new RuntimeException("SAKURUPIAH_{$prefix}_API_KEY must not contain surrounding whitespace.");
        }
    }

    private function assertSakurupiahDistinctCredentials(): void
    {
        foreach (['api_id' => 'API_ID', 'api_key' => 'API_KEY'] as $field => $label) {
            $sandbox = $this->config->get('services.sakurupiah.sandbox_'.$field);
            $production = $this->config->get('services.sakurupiah.production_'.$field);
            if (is_string($sandbox)
                && is_string($production)
                && filled($sandbox)
                && filled($production)
                && hash_equals($sandbox, $production)
            ) {
                throw new RuntimeException("SAKURUPIAH_SANDBOX_{$label} and SAKURUPIAH_PRODUCTION_{$label} must use different credentials.");
            }
        }
    }

    private function assertSakurupiahTransportConfiguration(): void
    {
        $timeout = $this->config->get('services.sakurupiah.http_timeout_seconds', 10);
        $connectTimeout = $this->config->get('services.sakurupiah.http_connect_timeout_seconds', 3);
        if (! is_numeric($timeout) || ! is_finite((float) $timeout) || (float) $timeout <= 0 || (float) $timeout > 60) {
            throw new RuntimeException('SAKURUPIAH_HTTP_TIMEOUT_SECONDS must be between 0 and 60 seconds.');
        }
        if (! is_numeric($connectTimeout) || ! is_finite((float) $connectTimeout) || (float) $connectTimeout <= 0 || (float) $connectTimeout > (float) $timeout) {
            throw new RuntimeException('SAKURUPIAH_HTTP_CONNECT_TIMEOUT_SECONDS must be positive and no greater than the HTTP timeout.');
        }

        $attempts = $this->config->get('services.sakurupiah.lookup_max_attempts', 3);
        if (! is_int($attempts) || $attempts < 1 || $attempts > 5) {
            throw new RuntimeException('SAKURUPIAH_LOOKUP_MAX_ATTEMPTS must be an integer between 1 and 5.');
        }

        $retryDelay = $this->config->get('services.sakurupiah.lookup_retry_delay_ms', 250);
        $retryMaxDelay = $this->config->get('services.sakurupiah.lookup_retry_max_delay_ms', 1000);
        if (! $this->validNonNegativeSakurupiahInt($retryDelay, 60_000)) {
            throw new RuntimeException('SAKURUPIAH_LOOKUP_RETRY_DELAY_MS must be an integer between 0 and 60000.');
        }
        if (! $this->validNonNegativeSakurupiahInt($retryMaxDelay, 60_000) || $retryMaxDelay < $retryDelay) {
            throw new RuntimeException('SAKURUPIAH_LOOKUP_RETRY_MAX_DELAY_MS must be no less than the retry delay and no greater than 60000.');
        }

        if (! $this->validSakurupiahInt($this->config->get('services.sakurupiah.lock_lease_seconds', 90), 1, 600)) {
            throw new RuntimeException('SAKURUPIAH_LOCK_LEASE_SECONDS must be an integer between 1 and 600.');
        }
        foreach (['webhook_rate_limit_per_minute' => 'SAKURUPIAH_WEBHOOK_RATE_LIMIT_PER_MINUTE', 'status_refresh_rate_limit_per_minute' => 'SAKURUPIAH_STATUS_REFRESH_RATE_LIMIT_PER_MINUTE'] as $field => $label) {
            if (! $this->validSakurupiahInt($this->config->get('services.sakurupiah.'.$field, 30), 1, 10_000)) {
                throw new RuntimeException($label.' must be an integer between 1 and 10000.');
            }
        }
        if (! $this->validSakurupiahInt($this->config->get('services.sakurupiah.readiness_cache_seconds', 60), 1, 300)) {
            throw new RuntimeException('SAKURUPIAH_READINESS_CACHE_SECONDS must be an integer between 1 and 300.');
        }

        $timezone = $this->config->get('services.sakurupiah.timezone', 'Asia/Jakarta');
        try {
            if (! is_string($timezone) || trim($timezone) === '') {
                throw new \InvalidArgumentException('Invalid timezone.');
            }
            new \DateTimeZone($timezone);
        } catch (\Throwable) {
            throw new RuntimeException('SAKURUPIAH_TIMEZONE must be a valid timezone identifier.');
        }
    }

    private function assertSakurupiahUrl(mixed $value, string $label, ?string $requiredPath, array $allowedHosts): void
    {
        if (! is_string($value)) {
            throw new RuntimeException("{$label} must be a valid HTTPS URL.");
        }

        $parts = parse_url($value);
        if (! is_array($parts)) {
            throw new RuntimeException("{$label} must be a valid HTTPS URL.");
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || $host === ''
            || $this->unsafeSakurupiahHost($host)
            || ! in_array($host, $allowedHosts, true)
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('port', $parts)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
        ) {
            throw new RuntimeException("{$label} must use a safe HTTPS host without credentials, ports, query parameters, or fragments.");
        }

        if ($requiredPath !== null && '/'.trim((string) ($parts['path'] ?? ''), '/') !== $requiredPath) {
            throw new RuntimeException("{$label} must use the {$requiredPath} path.");
        }
    }

    private function unsafeSakurupiahHost(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false
            || $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/', $host) !== 1;
    }

    /**
     * @return list<string>
     */
    private function sakurupiahHosts(string $field): array
    {
        $value = $this->config->get('services.sakurupiah.'.$field, []);
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        return array_values(array_filter(array_map(
            static fn (mixed $entry): ?string => is_string($entry)
                ? strtolower(rtrim(trim($entry), '.'))
                : null,
            (array) $value,
        ), static fn (mixed $entry): bool => is_string($entry) && $entry !== ''));
    }

    private function validSakurupiahIp(string $value): bool
    {
        $parts = explode('/', $value, 2);
        $ip = $parts[0];
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        if (! isset($parts[1])) {
            return true;
        }

        $prefix = $parts[1];
        $max = str_contains($ip, ':') ? 128 : 32;

        return ctype_digit($prefix) && (int) $prefix > 0 && (int) $prefix <= $max;
    }

    private function validSakurupiahInt(mixed $value, int $minimum, int $maximum): bool
    {
        return is_int($value) && $value >= $minimum && $value <= $maximum;
    }

    private function validNonNegativeSakurupiahInt(mixed $value, int $maximum): bool
    {
        return $this->validSakurupiahInt($value, 0, $maximum);
    }
}
