<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramBotApiRequestFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class CheckTelegramWebhook extends Command
{
    protected $signature = 'ops:check-telegram-webhook {--max-pending=0 : Maximum acceptable Telegram pending_update_count}';

    protected $description = 'Verify Telegram webhook registration without printing secrets or private URLs.';

    public function handle(TelegramBotApiRequestFactory $requests): int
    {
        $maxPending = (int) $this->option('max-pending');
        $token = trim((string) config('services.telegram.token'));
        $secret = trim((string) config('services.telegram.webhook_secret'));
        $appUrl = rtrim(trim((string) config('app.url')), '/');
        $target = $appUrl.'/api/webhook/telegram';
        $healthy = true;

        if ($maxPending < 0) {
            $this->error('Telegram webhook: max pending updates must be zero or greater');

            return self::FAILURE;
        }

        if ($token === '') {
            $this->error('Telegram webhook: bot token configured=false');

            return self::FAILURE;
        }

        if ($secret === '') {
            $this->error('Telegram webhook: webhook secret configured=false');

            return self::FAILURE;
        }

        if (! Str::startsWith($target, 'https://')) {
            $this->error('Telegram webhook: target_https=false');

            return self::FAILURE;
        }

        try {
            $response = $requests->request()->get($requests->url('getWebhookInfo'));
        } catch (Throwable) {
            $this->error('Telegram webhook: request failed');

            return self::FAILURE;
        }

        $body = $response->json();
        $telegramOk = $response->ok() && data_get($body, 'ok') === true;
        $registeredUrl = (string) data_get($body, 'result.url', '');
        $webhookConfigured = $registeredUrl !== '';
        $webhookMatchesAppUrl = $webhookConfigured && hash_equals($target, $registeredUrl);
        $pendingUpdates = data_get($body, 'result.pending_update_count');
        $lastErrorDate = data_get($body, 'result.last_error_date');
        $lastErrorMessage = data_get($body, 'result.last_error_message');
        $lastErrorPresent = $lastErrorMessage !== null;

        // An error is only considered active/blocking if it happened recently (within 5 minutes)
        // to avoid blocking deployments due to stale historical errors.
        $lastErrorActive = false;
        if ($lastErrorPresent) {
            if (is_numeric($lastErrorDate)) {
                $lastErrorActive = (time() - (int) $lastErrorDate) <= 300;
            } else {
                $lastErrorActive = true;
            }
        }

        $checks = [
            'telegram_ok' => $telegramOk,
            'target_https' => true,
            'webhook_secret_configured' => true,
            'webhook_url_configured' => $webhookConfigured,
            'webhook_matches_app_url' => $webhookMatchesAppUrl,
            'last_error_present' => $lastErrorPresent,
        ];

        foreach ($checks as $name => $value) {
            $this->line('Telegram webhook '.$name.'='.($value ? 'true' : 'false'));
        }

        $this->line('Telegram webhook pending_update_count='.(is_numeric($pendingUpdates) ? (string) $pendingUpdates : 'unknown'));

        if (! $telegramOk || ! $webhookConfigured || ! $webhookMatchesAppUrl || $lastErrorActive || ! is_numeric($pendingUpdates) || (int) $pendingUpdates > $maxPending) {
            $healthy = false;
        }

        if (! $healthy) {
            $this->error('Telegram webhook: failed release gate');

            return self::FAILURE;
        }

        $this->info('Telegram webhook: ok');

        return self::SUCCESS;
    }
}
