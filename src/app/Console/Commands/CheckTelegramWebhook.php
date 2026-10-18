<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramBotApiRequestFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class CheckTelegramWebhook extends Command
{
    protected $signature = 'ops:check-telegram-webhook
        {--max-pending=0 : Maximum acceptable Telegram pending_update_count per sample}
        {--observation-seconds=0 : Bounded period to observe pending updates; zero takes one sample}
        {--sample-interval=5 : Seconds between pending-update samples}
        {--tolerate-errors-since= : Ignore webhook errors at or after this Unix timestamp}';

    protected $description = 'Verify Telegram webhook registration without printing secrets or private URLs.';

    public function handle(TelegramBotApiRequestFactory $requests): int
    {
        $maxPending = filter_var($this->option('max-pending'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $observationSeconds = filter_var($this->option('observation-seconds'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $sampleInterval = filter_var($this->option('sample-interval'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $tolerateErrorsSince = $this->option('tolerate-errors-since');
        $token = trim((string) config('services.telegram.token'));
        $secret = trim((string) config('services.telegram.webhook_secret'));
        $appUrl = rtrim(trim((string) config('app.url')), '/');
        $target = $appUrl.'/api/webhook/telegram';
        $healthy = true;

        if ($maxPending === false || $observationSeconds === false || $sampleInterval === false) {
            $this->error('Telegram webhook: pending-update thresholds must be zero or greater and sample interval must be positive');

            return self::FAILURE;
        }

        if ($tolerateErrorsSince !== null && ! ctype_digit((string) $tolerateErrorsSince)) {
            $this->error('Telegram webhook: tolerate-errors-since must be a Unix timestamp');

            return self::FAILURE;
        }

        if ($tolerateErrorsSince !== null) {
            $tolerateErrorsSince = (int) $tolerateErrorsSince;
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

        $deadline = hrtime(true) / 1_000_000_000 + $observationSeconds;
        $samples = [];
        $firstResponse = null;
        do {
            try {
                $response = $requests->request()->get($requests->url('getWebhookInfo'));
            } catch (Throwable) {
                $this->error('Telegram webhook: request failed');

                return self::FAILURE;
            }

            $body = $response->json();
            $samples[] = $body;
            $firstResponse ??= $response;

            $remaining = $deadline - hrtime(true) / 1_000_000_000;
            if ($remaining <= 0) {
                break;
            }

            // Cap the wait to the remaining budget. One terminal probe may finish
            // after the deadline, bounded by the request factory's HTTP timeout.
            usleep((int) ceil(min($sampleInterval, $remaining) * 1_000_000));
        } while (true);

        $body = $samples[0];
        $telegramOk = $firstResponse->ok() && data_get($body, 'ok') === true;
        $registeredUrl = (string) data_get($body, 'result.url', '');
        $webhookConfigured = $registeredUrl !== '';
        $webhookMatchesAppUrl = $webhookConfigured && hash_equals($target, $registeredUrl);
        $lastErrorDate = data_get($body, 'result.last_error_date');
        $lastErrorMessage = data_get($body, 'result.last_error_message');
        $lastErrorPresent = $lastErrorMessage !== null;

        // Keep blocking recent webhook errors unless they were recorded after this
        // candidate began cutover; Telegram dates are Unix timestamps in seconds.
        $lastErrorActive = false;
        if ($lastErrorPresent) {
            if (is_numeric($lastErrorDate)) {
                $errorRecordedSinceCutover = $tolerateErrorsSince !== null
                    && (int) $lastErrorDate >= $tolerateErrorsSince;
                $lastErrorActive = ! $errorRecordedSinceCutover
                    && (time() - (int) $lastErrorDate) <= 300;
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

        $pendingValues = array_map(static fn (array $sample): mixed => data_get($sample, 'result.pending_update_count'), $samples);
        $pendingHealthy = collect($pendingValues)->contains(static fn (mixed $pending): bool => is_numeric($pending) && (int) $pending <= $maxPending);
        $this->line('Telegram webhook pending_update_count_samples='.implode(',', array_map(static fn (mixed $pending): string => is_numeric($pending) ? (string) $pending : 'unknown', $pendingValues)));

        if (! $telegramOk || ! $webhookConfigured || ! $webhookMatchesAppUrl || $lastErrorActive || ! $pendingHealthy) {
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
