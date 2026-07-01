<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SetTelegramWebhook extends Command
{
    protected $signature = 'telegram:webhook:set {--dry-run : Print the target without calling Telegram}';

    protected $description = 'Set the Telegram webhook to APP_URL/api/webhook/telegram.';

    public function handle(): int
    {
        $token = trim((string) config('services.telegram.token'));
        $secret = trim((string) config('services.telegram.webhook_secret'));
        $appUrl = rtrim(trim((string) config('app.url')), '/');
        $apiBaseUrl = rtrim((string) config('services.telegram.api_base_url', 'https://api.telegram.org'), '/');

        if ($token === '') {
            $this->warn('Telegram bot token is empty. Webhook setup skipped.');

            return self::SUCCESS;
        }

        if ($secret === '') {
            $this->warn('Telegram webhook secret is empty. Webhook setup skipped.');

            return self::SUCCESS;
        }

        if (! Str::startsWith($appUrl, 'https://')) {
            $this->warn('APP_URL must be a public HTTPS URL for Telegram webhooks. Webhook setup skipped.');

            return self::SUCCESS;
        }

        $webhookUrl = $appUrl.'/api/webhook/telegram';

        if ($this->option('dry-run')) {
            $this->line('Telegram webhook target: '.$webhookUrl);

            return self::SUCCESS;
        }

        $response = Http::post($apiBaseUrl.'/bot'.$token.'/setWebhook', [
            'url' => $webhookUrl,
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'callback_query'],
        ]);

        if (! $response->ok() || data_get($response->json(), 'ok') !== true) {
            $this->error('Telegram webhook setup failed: '.($response->json('description') ?: $response->body()));

            return self::FAILURE;
        }

        $this->info('Telegram webhook configured: '.$webhookUrl);

        return self::SUCCESS;
    }
}
