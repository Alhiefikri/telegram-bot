<?php

namespace App\Services\Telegram\Concerns;

trait HasAdminWebAppButton
{
    protected function adminDashboardWebAppButton(string $text): ?array
    {
        $url = $this->adminDashboardWebAppUrl();

        if (! $this->isTelegramWebAppUrl($url)) {
            return null;
        }

        return [
            'text' => $text,
            'web_app' => ['url' => $url],
        ];
    }

    protected function adminDashboardInlineButton(string $text): ?array
    {
        $url = $this->adminDashboardWebAppUrl();

        if ($this->isTelegramWebAppUrl($url)) {
            return [
                'text' => $text,
                'web_app' => ['url' => $url],
            ];
        }

        if (! $this->isTelegramDashboardUrl($url)) {
            return null;
        }

        return [
            'text' => $text,
            'url' => $url,
        ];
    }

    protected function adminDashboardWebAppUrl(): string
    {
        $configuredUrl = config('services.telegram.mini_app_url');
        $url = is_string($configuredUrl) && trim($configuredUrl) !== ''
            ? trim($configuredUrl)
            : route('admin.dashboard');

        return $url.(str_contains($url, '?') ? '&' : '?').'v='.time();
    }

    protected function isTelegramWebAppUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        return $this->isTelegramDashboardUrl($url)
            && ! str_ends_with($host, '.ngrok-free.app')
            && ! str_ends_with($host, '.ngrok-free.dev');
    }

    protected function isTelegramDashboardUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        return strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && $host !== ''
            && ! in_array($host, ['localhost', '127.0.0.1'], true)
            && ! str_ends_with($host, '.test')
            && ! str_ends_with($host, '.local');
    }
}
