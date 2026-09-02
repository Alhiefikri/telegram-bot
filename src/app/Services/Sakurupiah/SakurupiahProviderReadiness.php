<?php

namespace App\Services\Sakurupiah;

use App\Enums\SakurupiahEnvironment;

final class SakurupiahProviderReadiness
{
    public function __construct(private readonly ?SakurupiahEnvironment $environment = null) {}

    public function report(?SakurupiahEnvironment $environment = null): SakurupiahProviderReadinessReport
    {
        $environment ??= $this->environment ?? SakurupiahEnvironment::runtime();
        $missing = [];
        $invalid = [];

        $enabled = config($environment->configKey('enabled'), false);
        if ($enabled !== true) {
            $missing[] = 'provider enabled flag';
        }

        $apiId = config($environment->configKey('api_id'));
        $apiKey = config($environment->configKey('api_key'));
        if (! is_string($apiId) || trim($apiId) === '') {
            $missing[] = 'API ID';
        } elseif (trim($apiId) !== $apiId) {
            $invalid['API ID'] = 'Sakurupiah API ID must not contain surrounding whitespace.';
        }
        if (! is_string($apiKey) || trim($apiKey) === '') {
            $missing[] = 'API key';
        } elseif (trim($apiKey) !== $apiKey) {
            $invalid['API key'] = 'Sakurupiah API key must not contain surrounding whitespace.';
        }

        $baseUrl = config($environment->configKey('base_url'));
        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            $missing[] = 'base URL';
        } elseif (! self::isSafeBaseUrl($baseUrl, $environment)) {
            $invalid['base URL'] = 'Base URL must be an HTTPS Sakurupiah '.$environment->label().' endpoint.';
        }

        if (! $environment->isRuntimeAllowed()) {
            $invalid['APP_ENV'] = $environment === SakurupiahEnvironment::Production
                ? 'Sakurupiah production is allowed only when APP_ENV is production.'
                : 'Sakurupiah sandbox is allowed only when APP_ENV is local or testing.';
        }

        $other = $environment === SakurupiahEnvironment::Production
            ? SakurupiahEnvironment::Sandbox
            : SakurupiahEnvironment::Production;
        $otherBaseUrl = config($other->configKey('base_url'));
        $normalizedBaseUrl = is_string($baseUrl) ? rtrim($baseUrl, '/') : null;
        $normalizedOtherBaseUrl = is_string($otherBaseUrl) ? rtrim($otherBaseUrl, '/') : null;
        if ($normalizedBaseUrl !== null && $normalizedBaseUrl !== '' && $normalizedBaseUrl === $normalizedOtherBaseUrl) {
            $invalid['base URL environment'] = 'Sakurupiah environments must use different API endpoints.';
        }

        $otherApiKey = config($other->configKey('api_key'));
        if (is_string($apiKey) && $apiKey !== '' && is_string($otherApiKey) && $otherApiKey !== '' && hash_equals($apiKey, $otherApiKey)) {
            $invalid['credential environment'] = 'Sakurupiah sandbox and production API keys must be different.';
        }

        $timeout = config('services.sakurupiah.http_timeout_seconds', 10);
        $connectTimeout = config('services.sakurupiah.http_connect_timeout_seconds', 3);
        if (! is_numeric($timeout) || (float) $timeout <= 0 || (float) $timeout > 60) {
            $invalid['HTTP timeout'] = 'Sakurupiah HTTP timeout must be between 0 and 60 seconds.';
        }
        if (! is_numeric($connectTimeout) || (float) $connectTimeout <= 0 || (float) $connectTimeout > (float) $timeout) {
            $invalid['HTTP connect timeout'] = 'Sakurupiah connect timeout must be positive and no greater than the HTTP timeout.';
        }

        return new SakurupiahProviderReadinessReport($missing, $invalid);
    }

    public function sandbox(): SakurupiahProviderReadinessReport
    {
        return $this->report(SakurupiahEnvironment::Sandbox);
    }

    public function production(): SakurupiahProviderReadinessReport
    {
        return $this->report(SakurupiahEnvironment::Production);
    }

    public function checkout(): SakurupiahProviderReadinessReport
    {
        return $this->report(SakurupiahEnvironment::runtime());
    }

    public function active(): SakurupiahProviderReadinessReport
    {
        return $this->checkout();
    }

    public function forEnvironment(SakurupiahEnvironment $environment): SakurupiahProviderReadinessReport
    {
        return $this->report($environment);
    }

    public function assertReady(string $message = 'Sakurupiah provider is not ready.', ?SakurupiahEnvironment $environment = null): void
    {
        $this->report($environment)->assertReady($message);
    }

    private static function isSafeBaseUrl(string $baseUrl, SakurupiahEnvironment $environment): bool
    {
        $parts = parse_url($baseUrl);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
            || array_key_exists('port', $parts)
        ) {
            return false;
        }

        $path = '/'.trim((string) ($parts['path'] ?? ''), '/');
        if ($environment === SakurupiahEnvironment::Sandbox) {
            return $path === '/api-sanbox';
        }

        return $path === '/api';
    }
}
