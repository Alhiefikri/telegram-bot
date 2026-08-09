<?php

namespace App\Services\KlikQris;

use App\Console\Commands\ReconcileKlikQrisPayments;
use App\Enums\KlikQrisEnvironment;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class KlikQrisProviderReadiness
{
    public function forEnvironment(KlikQrisEnvironment $environment): KlikQrisProviderReadinessReport
    {
        return new KlikQrisProviderReadinessReport(
            missing: $this->missingConfiguration($environment),
            invalid: $this->invalidConfiguration($environment),
        );
    }

    public function sandbox(): KlikQrisProviderReadinessReport
    {
        return $this->forEnvironment(KlikQrisEnvironment::Sandbox);
    }

    public function production(): KlikQrisProviderReadinessReport
    {
        return $this->forEnvironment(KlikQrisEnvironment::Production);
    }

    public function checkout(): KlikQrisProviderReadinessReport
    {
        return $this->forEnvironment(KlikQrisEnvironment::runtime());
    }

    public function active(): KlikQrisProviderReadinessReport
    {
        return $this->checkout();
    }

    /** @return list<string> */
    private function missingConfiguration(KlikQrisEnvironment $environment): array
    {
        $missing = [];

        if ($this->value($environment, 'enabled') !== true) {
            $missing[] = $this->label($environment, 'ENABLED');
        }

        foreach (match ($environment) {
            KlikQrisEnvironment::Sandbox => [
                'base_url' => 'BASE_URL',
                'api_key' => 'API_KEY',
                'merchant_id' => 'MERCHANT_ID',
            ],
            KlikQrisEnvironment::Production => [
                'base_url' => 'BASE_URL',
                'api_key' => 'API_KEY',
                'merchant_id' => 'MERCHANT_ID',
                'callback_url' => 'CALLBACK_URL',
                'webhook_secret' => 'WEBHOOK_SECRET',
            ],
        } as $field => $label) {
            if (! is_string($this->value($environment, $field)) || blank($this->value($environment, $field))) {
                $missing[] = $this->label($environment, $label);
            }
        }

        if ($environment === KlikQrisEnvironment::Production) {
            foreach ([
                'allowed_hosts' => 'ALLOWED_HOSTS',
                'callback_allowed_hosts' => 'CALLBACK_ALLOWED_HOSTS',
            ] as $field => $label) {
                if ($this->hosts($environment, $field) === []) {
                    $missing[] = $this->label($environment, $label);
                }
            }
        }

        return $missing;
    }

    /** @return array<string, string> */
    private function invalidConfiguration(KlikQrisEnvironment $environment): array
    {
        $invalid = [];

        if (! $environment->isRuntimeAllowed()) {
            $invalid['APP_ENV'] = $environment === KlikQrisEnvironment::Production
                ? 'KlikQRIS production checkout is allowed only when APP_ENV is production.'
                : 'KlikQRIS sandbox checkout is allowed only when APP_ENV is local or testing.';
        }

        $baseUrl = $this->value($environment, 'base_url');
        $baseUrlInvalid = $this->validateUrl(
            $baseUrl,
            $this->label($environment, 'BASE_URL'),
            $environment === KlikQrisEnvironment::Production
                ? $this->hosts($environment, 'allowed_hosts')
                : ['klikqris.com'],
            $environment === KlikQrisEnvironment::Sandbox ? '/api/sandbox' : null,
        );
        $invalid += $baseUrlInvalid;

        $callbackUrl = $this->value($environment, 'callback_url');
        if ($environment === KlikQrisEnvironment::Production || filled($callbackUrl)) {
            $invalid += $this->validateUrl(
                $callbackUrl,
                $this->label($environment, 'CALLBACK_URL'),
                $this->hosts($environment, 'callback_allowed_hosts'),
                $environment->callbackPath(),
            );
        }

        $ttl = (int) config('services.klikqris.qris_ttl_minutes', 15);
        if ($ttl < 5 || $ttl > 60) {
            $invalid['KLIKQRIS_QRIS_TTL_MINUTES'] = 'KLIKQRIS_QRIS_TTL_MINUTES must be between 5 and 60 minutes.';
        }

        if ($environment === KlikQrisEnvironment::Production) {
            $timeout = (float) config('services.klikqris.http_timeout_seconds', 10);
            $connectTimeout = (float) config('services.klikqris.http_connect_timeout_seconds', 3);
            if ($timeout <= 0 || $timeout > 60) {
                $invalid['KLIKQRIS_HTTP_TIMEOUT_SECONDS'] = 'KLIKQRIS_HTTP_TIMEOUT_SECONDS must be between 0 and 60 seconds.';
            }
            if ($connectTimeout <= 0 || $connectTimeout > $timeout) {
                $invalid['KLIKQRIS_HTTP_CONNECT_TIMEOUT_SECONDS'] = 'KLIKQRIS_HTTP_CONNECT_TIMEOUT_SECONDS must be positive and no greater than the HTTP timeout.';
            }
        }

        $invalid += $this->invalidCrossEnvironmentConfiguration($environment);

        if ($this->value($environment, 'enabled') === true && $environment->isRuntimeAllowed()) {
            $invalid += $this->invalidOperationalConfiguration($environment);
        }

        return $invalid;
    }

    /** @return array<string, string> */
    private function invalidCrossEnvironmentConfiguration(KlikQrisEnvironment $environment): array
    {
        $other = $environment === KlikQrisEnvironment::Production
            ? KlikQrisEnvironment::Sandbox
            : KlikQrisEnvironment::Production;
        $invalid = [];

        $baseUrl = $this->normalizedUrl($this->value($environment, 'base_url'));
        $otherBaseUrl = $this->normalizedUrl($this->value($other, 'base_url'));
        if ($baseUrl !== null && $baseUrl === $otherBaseUrl) {
            $invalid[$this->label($environment, 'BASE_URL_'.strtoupper($other->value))] = 'KlikQRIS environments must not share an API endpoint.';
        }

        if ($environment === KlikQrisEnvironment::Production && $this->isSandboxEndpoint($this->value($environment, 'base_url'))) {
            $invalid[$this->label($environment, 'BASE_URL_SANDBOX')] = 'KlikQRIS production must not use the sandbox API endpoint.';
        }
        if ($environment === KlikQrisEnvironment::Sandbox && $this->isSandboxEndpoint($this->value($other, 'base_url')) === false
            && filled($this->value($environment, 'base_url'))
            && $this->normalizedUrl($this->value($environment, 'base_url')) === $this->normalizedUrl($this->value($other, 'base_url'))) {
            $invalid[$this->label($environment, 'BASE_URL_PRODUCTION')] = 'KlikQRIS sandbox must not use the production API endpoint.';
        }

        foreach (['api_key' => 'API_KEY', 'merchant_id' => 'MERCHANT_ID', 'webhook_secret' => 'WEBHOOK_SECRET'] as $field => $label) {
            $value = $this->value($environment, $field);
            $otherValue = $this->value($other, $field);
            if (filled($value) && filled($otherValue) && hash_equals((string) $value, (string) $otherValue)) {
                $invalid[$this->label($environment, $label.'_'.strtoupper($other->value))] = 'KlikQRIS sandbox and production credentials must be different.';
            }
        }

        $callbackUrl = $this->normalizedUrl($this->value($environment, 'callback_url'));
        $otherCallbackUrl = $this->normalizedUrl($this->value($other, 'callback_url'));
        if ($callbackUrl !== null && $callbackUrl === $otherCallbackUrl) {
            $invalid[$this->label($environment, 'CALLBACK_URL_'.strtoupper($other->value))] = 'KlikQRIS environments must not share a callback URL.';
        }

        return $invalid;
    }

    public function sandboxDisabledForEnvironment(): bool
    {
        return $this->disabledForEnvironment(KlikQrisEnvironment::Sandbox);
    }

    public function productionDisabledForEnvironment(): bool
    {
        return $this->disabledForEnvironment(KlikQrisEnvironment::Production);
    }

    public function disabledForEnvironment(KlikQrisEnvironment $environment): bool
    {
        return $this->value($environment, 'enabled') !== true || ! $environment->isRuntimeAllowed();
    }

    public function sandboxSchedulingEnabled(): bool
    {
        return $this->schedulingEnabledFor(KlikQrisEnvironment::Sandbox);
    }

    public function productionSchedulingEnabled(): bool
    {
        return $this->schedulingEnabledFor(KlikQrisEnvironment::Production);
    }

    public function schedulingEnabled(): bool
    {
        return $this->schedulingEnabledFor(KlikQrisEnvironment::runtime());
    }

    public function schedulingEnabledFor(KlikQrisEnvironment $environment): bool
    {
        if ($this->disabledForEnvironment($environment)
            || trim((string) config('services.klikqris.reconciliation_command')) !== 'klikqris:reconcile --chunk=100') {
            return false;
        }

        return $this->reportWithoutSchedule($this->forEnvironment($environment))->isReady();
    }

    public function webhookSecret(KlikQrisEnvironment|string $environment): ?string
    {
        $environment = is_string($environment) ? KlikQrisEnvironment::tryFrom($environment) : $environment;
        if ($environment === null) {
            return null;
        }

        $secret = $this->value($environment, 'webhook_secret');

        return filled($secret) ? (string) $secret : null;
    }

    /** @return array<string, string> */
    private function invalidOperationalConfiguration(KlikQrisEnvironment $environment): array
    {
        $invalid = [];
        if (Route::getRoutes()->getByName($environment->routeName()) === null) {
            $routeLabel = $environment === KlikQrisEnvironment::Sandbox
                ? 'KLIKQRIS_CALLBACK_ROUTE'
                : $this->label($environment, 'CALLBACK_ROUTE');
            $invalid[$routeLabel] = "KlikQRIS {$environment->label()} callback route must be registered.";
        }

        $queueConnection = (string) config('queue.default');
        $queueDriver = (string) config("queue.connections.{$queueConnection}.driver");
        if ($queueConnection === '' || $queueDriver === '' || in_array($queueDriver, ['sync', 'null'], true)) {
            $invalid['QUEUE_CONNECTION'] = 'QUEUE_CONNECTION must use an asynchronous operational queue driver for KlikQRIS operations.';
        }

        foreach ([
            'jobs' => 'QUEUE_JOBS_TABLE',
            'failed_jobs' => 'QUEUE_FAILED_JOBS_TABLE',
            'job_batches' => 'QUEUE_JOB_BATCHES_TABLE',
        ] as $table => $label) {
            if (! Schema::hasTable($table)) {
                $invalid[$label] = "Queue schema table {$table} must exist for KlikQRIS operations.";
            }
        }

        foreach (['payments', 'payment_events'] as $table) {
            if (! Schema::hasTable($table)) {
                $invalid['PAYMENT_SCHEMA'] = 'Payment and payment event schema must exist for KlikQRIS operations.';
            }
        }

        if (! class_exists(ReconcileKlikQrisPayments::class)) {
            $invalid['KLIKQRIS_RECONCILIATION_COMMAND'] = 'KlikQRIS reconciliation command must be registered.';
        }

        if (trim((string) config('services.klikqris.reconciliation_command')) !== 'klikqris:reconcile --chunk=100') {
            $invalid['KLIKQRIS_RECONCILIATION_SCHEDULE'] = 'KlikQRIS reconciliation must be scheduled by the Laravel scheduler.';
        }

        return $invalid;
    }

    private function reportWithoutSchedule(KlikQrisProviderReadinessReport $report): KlikQrisProviderReadinessReport
    {
        $invalid = $report->invalid();
        unset($invalid['KLIKQRIS_RECONCILIATION_SCHEDULE']);

        return new KlikQrisProviderReadinessReport($report->missing(), $invalid);
    }

    /** @return array<string, string> */
    private function validateUrl(mixed $value, string $label, array $allowedHosts, ?string $requiredPath = null): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_string($value)) {
            return [$label.'_FORMAT' => "{$label} must be a valid HTTPS URL."];
        }

        if (blank($value)) {
            return [];
        }

        $parts = parse_url($value);
        if (! is_array($parts)) {
            return [$label.'_FORMAT' => "{$label} must be a valid HTTPS URL."];
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = '/'.trim((string) ($parts['path'] ?? ''), '/');
        $invalid = [];

        if ($scheme !== 'https') {
            $invalid[$label.'_SCHEME'] = "{$label} must use HTTPS.";
        }
        if ($host === '' || ($allowedHosts !== [] && ! in_array($host, $allowedHosts, true))) {
            $invalid[$label.'_HOST'] = "{$label} must use an allowed HTTPS host.";
        }
        if ($requiredPath !== null && $path !== $requiredPath) {
            $invalid[$label.'_PATH'] = "{$label} must use the {$requiredPath} path.";
        }
        if (array_key_exists('user', $parts) || array_key_exists('pass', $parts) || array_key_exists('query', $parts) || array_key_exists('fragment', $parts)) {
            $invalid[$label.'_FORMAT'] = "{$label} must not contain credentials, query parameters, or fragments.";
        }

        return $invalid;
    }

    /** @return list<string> */
    private function hosts(KlikQrisEnvironment $environment, string $field): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $host): string => strtolower(trim((string) $host)),
            (array) $this->value($environment, $field, []),
        )));
    }

    private function value(KlikQrisEnvironment $environment, string $field, mixed $default = null): mixed
    {
        return config($environment->configKey($field), $default);
    }

    private function label(KlikQrisEnvironment $environment, string $field): string
    {
        return 'KLIKQRIS_'.strtoupper($environment->value).'_'.strtoupper($field);
    }

    private function normalizedUrl(mixed $value): ?string
    {
        if (! is_string($value) || blank($value)) {
            return null;
        }

        return rtrim(strtolower(trim($value)), '/');
    }

    private function isSandboxEndpoint(mixed $value): bool
    {
        if (! is_string($value) || blank($value)) {
            return false;
        }

        $parts = parse_url($value);

        return is_array($parts)
            && strtolower((string) ($parts['host'] ?? '')) === 'klikqris.com'
            && '/'.trim((string) ($parts['path'] ?? ''), '/') === '/api/sandbox';
    }
}
