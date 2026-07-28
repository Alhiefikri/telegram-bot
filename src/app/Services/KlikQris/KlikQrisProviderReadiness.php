<?php

namespace App\Services\KlikQris;

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

        return $invalid;
    }
}
