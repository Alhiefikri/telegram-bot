<?php

namespace App\Services\KlikQris;

use App\Console\Commands\ReconcileKlikQrisPayments;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class KlikQrisProviderReadiness
{
    public function sandbox(): KlikQrisProviderReadinessReport
    {
        return new KlikQrisProviderReadinessReport(
            missing: $this->missingSandboxConfiguration(),
            invalid: $this->invalidSandboxConfiguration(),
        );
    }

    /**
     * @return list<string>
     */
    private function missingSandboxConfiguration(): array
    {
        $missing = [];

        if (config('services.klikqris.sandbox_enabled') !== true) {
            $missing[] = 'KLIKQRIS_SANDBOX_ENABLED';
        }

        if (blank(config('services.klikqris.sandbox_api_key'))) {
            $missing[] = 'KLIKQRIS_SANDBOX_API_KEY';
        }

        if (blank(config('services.klikqris.sandbox_merchant_id'))) {
            $missing[] = 'KLIKQRIS_SANDBOX_MERCHANT_ID';
        }

        return $missing;
    }

    /**
     * @return array<string, string>
     */
    private function invalidSandboxConfiguration(): array
    {
        $invalid = [];

        if (! app()->environment(['local', 'testing'])) {
            $invalid['APP_ENV'] = 'KlikQRIS sandbox checkout is allowed only when APP_ENV is local or testing.';
        }

        $baseUrl = config('services.klikqris.sandbox_base_url', 'https://klikqris.com/api/sandbox');
        if (! is_string($baseUrl) || blank($baseUrl)) {
            $invalid['KLIKQRIS_SANDBOX_BASE_URL'] = 'KLIKQRIS_SANDBOX_BASE_URL must be a sandbox URL string.';
        } else {
            $parts = parse_url($baseUrl);
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = strtolower((string) ($parts['host'] ?? ''));
            $path = '/'.trim((string) ($parts['path'] ?? ''), '/');

            if ($scheme !== 'https') {
                $invalid['KLIKQRIS_SANDBOX_BASE_URL_SCHEME'] = 'KLIKQRIS_SANDBOX_BASE_URL must use HTTPS.';
            }

            if ($host !== 'klikqris.com') {
                $invalid['KLIKQRIS_SANDBOX_BASE_URL_HOST'] = 'KLIKQRIS_SANDBOX_BASE_URL must use the klikqris.com host.';
            }

            if ($path !== '/api/sandbox') {
                $invalid['KLIKQRIS_SANDBOX_BASE_URL_PATH'] = 'KLIKQRIS_SANDBOX_BASE_URL must use the /api/sandbox path.';
            }
        }

        $ttl = (int) config('services.klikqris.qris_ttl_minutes', 15);
        if ($ttl < 5 || $ttl > 60) {
            $invalid['KLIKQRIS_QRIS_TTL_MINUTES'] = 'KLIKQRIS_QRIS_TTL_MINUTES must be between 5 and 60 minutes.';
        }

        if (config('services.klikqris.sandbox_enabled') === true && app()->environment(['local', 'testing'])) {
            $invalid += $this->invalidOperationalConfiguration();
        }

        return $invalid;
    }

    public function sandboxDisabledForEnvironment(): bool
    {
        return config('services.klikqris.sandbox_enabled') !== true
            || ! app()->environment(['local', 'testing']);
    }

    public function sandboxSchedulingEnabled(): bool
    {
        if ($this->sandboxDisabledForEnvironment()) {
            return false;
        }

        $report = new KlikQrisProviderReadinessReport(
            missing: $this->missingSandboxConfiguration(),
            invalid: array_filter(
                $this->invalidSandboxConfigurationWithoutSchedule(),
                fn (string $label): bool => $label !== 'KLIKQRIS_RECONCILIATION_SCHEDULE',
                ARRAY_FILTER_USE_KEY,
            ),
        );

        return $report->isReady();
    }

    /** @return array<string, string> */
    private function invalidSandboxConfigurationWithoutSchedule(): array
    {
        $invalid = [];

        if (! app()->environment(['local', 'testing'])) {
            $invalid['APP_ENV'] = 'KlikQRIS sandbox checkout is allowed only when APP_ENV is local or testing.';
        }

        $baseUrl = config('services.klikqris.sandbox_base_url', 'https://klikqris.com/api/sandbox');
        if (! is_string($baseUrl) || blank($baseUrl)) {
            $invalid['KLIKQRIS_SANDBOX_BASE_URL'] = 'KLIKQRIS_SANDBOX_BASE_URL must be a sandbox URL string.';
        } else {
            $parts = parse_url($baseUrl);
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = strtolower((string) ($parts['host'] ?? ''));
            $path = '/'.trim((string) ($parts['path'] ?? ''), '/');

            if ($scheme !== 'https') {
                $invalid['KLIKQRIS_SANDBOX_BASE_URL_SCHEME'] = 'KLIKQRIS_SANDBOX_BASE_URL must use HTTPS.';
            }

            if ($host !== 'klikqris.com') {
                $invalid['KLIKQRIS_SANDBOX_BASE_URL_HOST'] = 'KLIKQRIS_SANDBOX_BASE_URL must use the klikqris.com host.';
            }

            if ($path !== '/api/sandbox') {
                $invalid['KLIKQRIS_SANDBOX_BASE_URL_PATH'] = 'KLIKQRIS_SANDBOX_BASE_URL must use the /api/sandbox path.';
            }
        }

        $ttl = (int) config('services.klikqris.qris_ttl_minutes', 15);
        if ($ttl < 5 || $ttl > 60) {
            $invalid['KLIKQRIS_QRIS_TTL_MINUTES'] = 'KLIKQRIS_QRIS_TTL_MINUTES must be between 5 and 60 minutes.';
        }

        return $invalid + $this->invalidOperationalConfiguration(includeSchedule: false);
    }

    /** @return array<string, string> */
    private function invalidOperationalConfiguration(bool $includeSchedule = true): array
    {
        $invalid = [];

        if (Route::getRoutes()->getByName('klikqris.sandbox.webhook') === null) {
            $invalid['KLIKQRIS_CALLBACK_ROUTE'] = 'KlikQRIS sandbox callback route must be registered.';
        }

        $queueConnection = (string) config('queue.default');
        $queueDriver = (string) config("queue.connections.{$queueConnection}.driver");
        if ($queueConnection === '' || in_array($queueDriver, ['sync', 'null'], true)) {
            $invalid['QUEUE_CONNECTION'] = 'QUEUE_CONNECTION must use an asynchronous operational queue driver for KlikQRIS sandbox delivery.';
        }

        foreach (['jobs' => 'QUEUE_JOBS_TABLE', 'failed_jobs' => 'QUEUE_FAILED_JOBS_TABLE', 'job_batches' => 'QUEUE_JOB_BATCHES_TABLE'] as $table => $label) {
            if (! Schema::hasTable($table)) {
                $invalid[$label] = "Queue schema table {$table} must exist for KlikQRIS sandbox operations.";
            }
        }

        if (! class_exists(ReconcileKlikQrisPayments::class)) {
            $invalid['KLIKQRIS_RECONCILIATION_COMMAND'] = 'KlikQRIS reconciliation command must be registered.';
        }

        if ($includeSchedule && trim((string) config('services.klikqris.reconciliation_command')) !== 'klikqris:reconcile --chunk=100') {
            $invalid['KLIKQRIS_RECONCILIATION_SCHEDULE'] = 'KlikQRIS reconciliation must be scheduled by the Laravel scheduler.';
        }

        return $invalid;
    }
}
