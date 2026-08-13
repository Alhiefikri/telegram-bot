<?php

namespace App\Services\Telegram;

use App\Jobs\SendTelegramBroadcastBatch;
use App\Models\Customer;
use App\Models\TelegramBroadcast;
use App\Models\TelegramBroadcastRecipient;
use App\Support\DogfoodTiming;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class TelegramBroadcastService
{
    private const CLAIM_TTL_MINUTES = 15;

    private const RETRY_DELAY_SECONDS = 30;

    public const ADMIN_BATCH_SIZE = 25;

    public const MAX_RECIPIENT_ATTEMPTS = 5;

    public function __construct(private TelegramBotClient $bot) {}

    /**
     * @param  array<string, mixed>  $replyMarkup
     * @return array{broadcast_id:int|null, sent:int, failed:int, skipped:bool, queued:bool, recipient_count:int, telegram_total_duration_ms:float, per_recipient_telegram:list<array{}>}
     */
    public function queueAdminBroadcast(string $message, array $replyMarkup = []): array
    {
        if (blank(config('services.telegram.token'))) {
            return [
                'broadcast_id' => null,
                'sent' => 0,
                'failed' => 0,
                'skipped' => true,
                'queued' => false,
                'recipient_count' => 0,
                'telegram_total_duration_ms' => 0.0,
                'per_recipient_telegram' => [],
            ];
        }

        $broadcast = TelegramBroadcast::create([
            'broadcast_key' => (string) Str::uuid(),
            'source' => 'admin',
            'message' => $message,
            'reply_markup' => $replyMarkup === [] ? null : $replyMarkup,
            'status' => TelegramBroadcast::STATUS_QUEUED,
        ]);

        SendTelegramBroadcastBatch::dispatch($broadcast->id, now()->toISOString())
            ->onQueue('telegram-broadcast')
            ->afterCommit();

        return [
            'broadcast_id' => $broadcast->id,
            'sent' => 0,
            'failed' => 0,
            'skipped' => false,
            'queued' => true,
            'recipient_count' => 0,
            'telegram_total_duration_ms' => 0.0,
            'per_recipient_telegram' => [],
        ];
    }

    public function processAdminBroadcastBatch(int $broadcastId, int $batchSize = self::ADMIN_BATCH_SIZE): TelegramBroadcastBatchResult
    {
        $broadcast = TelegramBroadcast::query()->findOrFail($broadcastId);

        if (blank(config('services.telegram.token'))) {
            $broadcast->forceFill([
                'status' => TelegramBroadcast::STATUS_SKIPPED,
                'completed_at' => now(),
            ])->save();

            return new TelegramBroadcastBatchResult(0, 0, 0, 0, false);
        }

        if ($broadcast->recipients_materialized_at === null) {
            $this->ensureAdminBroadcastRecipients($broadcast);
        }

        $recipients = TelegramBroadcastRecipient::query()
            ->where('broadcast_id', $broadcast->id)
            ->whereNull('sent_at')
            ->whereNull('terminal_failed_at')
            ->where('attempts', '<', self::MAX_RECIPIENT_ATTEMPTS)
            ->where(function ($query): void {
                $query->whereNull('send_started_at')
                    ->orWhere('send_started_at', '<=', now()->subMinutes(self::CLAIM_TTL_MINUTES));
            })
            ->where(function ($query): void {
                $query->whereNull('failed_at')
                    ->orWhere('failed_at', '<=', now()->subSeconds(self::RETRY_DELAY_SECONDS));
            })
            ->orderBy('id')
            ->limit(max(1, $batchSize))
            ->get();

        if ($recipients->isNotEmpty()) {
            $broadcast->forceFill(['status' => TelegramBroadcast::STATUS_IN_PROGRESS])->save();
        }

        $sent = 0;
        $transientFailures = 0;
        $terminalFailures = 0;

        foreach ($recipients as $recipient) {
            $outcome = $this->sendAdminBroadcastRecipient($broadcast, $recipient);
            $sent += $outcome === 'sent' ? 1 : 0;
            $transientFailures += $outcome === 'transient_failure' ? 1 : 0;
            $terminalFailures += $outcome === 'terminal_failure' ? 1 : 0;
        }

        $hasMore = $this->refreshAdminBroadcastStatus($broadcast->id);

        return new TelegramBroadcastBatchResult(
            processed: $recipients->count(),
            sent: $sent,
            transientFailures: $transientFailures,
            terminalFailures: $terminalFailures,
            hasMore: $hasMore,
        );
    }

    /**
     * @param  string|callable(Customer): string  $message
     * @param  string|callable(Customer): string|null  $title
     * @param  array|callable(Customer): array  $replyMarkup
     * @return array{sent:int, failed:int, skipped:bool, recipient_count:int, telegram_total_duration_ms:float, per_recipient_telegram:list<array{recipient_correlation_id:?string, status:string, duration_ms:float, exception?:string}>}
     */
    public function sendToActiveUsers(string|callable $message, string|callable|null $title = null, array|callable $replyMarkup = []): array
    {
        if (blank(config('services.telegram.token'))) {
            $result = [
                'sent' => 0,
                'failed' => 0,
                'skipped' => true,
                'recipient_count' => 0,
                'telegram_total_duration_ms' => 0.0,
                'per_recipient_telegram' => [],
            ];

            DogfoodTiming::log('dogfood.telegram.broadcast_finished', $result);

            return $result;
        }

        $sent = 0;
        $failed = 0;
        $seen = [];
        $perRecipient = [];
        $telegramTotalMs = 0.0;

        Customer::query()
            ->whereNotNull('telegram_id')
            ->orderBy('id')
            ->select(['id', 'telegram_id', 'locale'])
            ->chunkById(100, function ($customers) use (&$sent, &$failed, &$seen, &$perRecipient, &$telegramTotalMs, $message, $title, $replyMarkup): void {
                foreach ($customers as $customer) {
                    $chatId = (string) $customer->telegram_id;
                    if ($chatId === '' || isset($seen[$chatId])) {
                        continue;
                    }

                    $seen[$chatId] = true;
                    $startedAt = DogfoodTiming::now();
                    $recipient = [
                        'recipient_correlation_id' => DogfoodTiming::correlation($chatId),
                        'status' => 'ok',
                        'duration_ms' => 0.0,
                    ];

                    try {
                        $resolvedMessage = is_string($message) ? $message : $message($customer);
                        $resolvedTitle = $title === null
                            ? ($customer->locale === 'en' ? '📣 Info from Admin' : '📣 Info dari Admin')
                            : (is_string($title) ? $title : $title($customer));
                        $resolvedReplyMarkup = is_array($replyMarkup) ? $replyMarkup : $replyMarkup($customer);
                        $text = implode("\n", [
                            $resolvedTitle,
                            '━━━━━━━━━━━━━━',
                            $resolvedMessage,
                            '',
                            $customer->locale === 'en' ? 'Need help? Reply with /support.' : 'Butuh bantuan? Balas /support ya.',
                        ]);
                        $this->bot->sendMessage($chatId, $text, $resolvedReplyMarkup);
                        $sent++;
                    } catch (Throwable $e) {
                        report($e);
                        $failed++;
                        $recipient['status'] = 'error';
                        $recipient['exception'] = $e::class;
                    } finally {
                        $recipient['duration_ms'] = DogfoodTiming::elapsedMs($startedAt);
                        $telegramTotalMs = round($telegramTotalMs + $recipient['duration_ms'], 2);
                        $perRecipient[] = $recipient;

                        DogfoodTiming::log('dogfood.telegram.broadcast_recipient', $recipient + [
                            'recipient_index' => count($perRecipient),
                        ]);
                    }
                }
            });

        $result = [
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => false,
            'recipient_count' => count($perRecipient),
            'telegram_total_duration_ms' => $telegramTotalMs,
            'per_recipient_telegram' => $perRecipient,
        ];

        DogfoodTiming::log('dogfood.telegram.broadcast_finished', $result);

        return $result;
    }

    private function ensureAdminBroadcastRecipients(TelegramBroadcast $broadcast): void
    {
        Customer::query()
            ->whereNotNull('telegram_id')
            ->select(['id', 'telegram_id', 'locale'])
            ->orderBy('id')
            ->chunkById(200, function ($customers) use ($broadcast): void {
                $now = now();
                $rows = $customers
                    ->filter(fn (Customer $customer): bool => trim((string) $customer->telegram_id) !== '')
                    ->map(fn (Customer $customer): array => [
                        'broadcast_id' => $broadcast->id,
                        'customer_id' => $customer->id,
                        'telegram_id' => (string) $customer->telegram_id,
                        'locale' => $customer->locale === 'en' ? 'en' : 'id',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])
                    ->values()
                    ->all();

                if ($rows !== []) {
                    TelegramBroadcastRecipient::query()->insertOrIgnore($rows);
                }
            });

        $this->refreshAdminBroadcastStatus($broadcast->id, true);
    }

    private function sendAdminBroadcastRecipient(TelegramBroadcast $broadcast, TelegramBroadcastRecipient $recipient): string
    {
        $token = (string) Str::uuid();
        $claimed = DB::transaction(function () use ($recipient, $token): ?TelegramBroadcastRecipient {
            $locked = TelegramBroadcastRecipient::query()
                ->whereKey($recipient->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || $locked->sent_at !== null || $locked->terminal_failed_at !== null) {
                return null;
            }

            if ($locked->attempts >= self::MAX_RECIPIENT_ATTEMPTS) {
                return null;
            }

            if ($locked->send_started_at !== null && $locked->send_started_at->gt(now()->subMinutes(self::CLAIM_TTL_MINUTES))) {
                return null;
            }

            $locked->forceFill([
                'send_started_at' => now(),
                'send_claim_token' => $token,
                'failed_at' => null,
                'last_error' => null,
                'attempts' => $locked->attempts + 1,
            ])->save();

            return $locked->fresh();
        }, 5);

        if (! $claimed) {
            return 'skipped';
        }

        $startedAt = DogfoodTiming::now();
        $recipientLog = [
            'broadcast_id' => $broadcast->id,
            'recipient_id' => $claimed->id,
            'recipient_correlation_id' => DogfoodTiming::correlation($claimed->telegram_id),
            'status' => 'ok',
            'duration_ms' => 0.0,
        ];

        try {
            // Telegram sendMessage has no idempotency key. A stored sent_at prevents normal
            // duplicate or retried jobs from resending successful recipients.
            $this->bot->sendMessage(
                $claimed->telegram_id,
                $this->adminBroadcastText($broadcast->message, $claimed->locale === 'en'),
                $this->adminBroadcastReplyMarkup($broadcast->reply_markup, $claimed->locale === 'en'),
            );
        } catch (Throwable $e) {
            $terminal = $this->isPermanentRecipientFailure($e) || $claimed->attempts >= self::MAX_RECIPIENT_ATTEMPTS;
            TelegramBroadcastRecipient::query()
                ->whereKey($claimed->id)
                ->where('send_claim_token', $token)
                ->whereNull('sent_at')
                ->update([
                    'send_started_at' => null,
                    'send_claim_token' => null,
                    'failed_at' => now(),
                    'terminal_failed_at' => $terminal ? now() : null,
                    'last_error' => substr(TelegramBotApiRedactor::redactText($e::class.': '.$e->getMessage()), 0, 2000),
                    'attempts' => $terminal ? self::MAX_RECIPIENT_ATTEMPTS : $claimed->attempts,
                ]);

            $recipientLog['status'] = 'error';
            $recipientLog['exception'] = $e::class;
            $recipientLog['terminal'] = $terminal;
            $recipientLog['duration_ms'] = DogfoodTiming::elapsedMs($startedAt);
            DogfoodTiming::log('dogfood.telegram.broadcast_recipient', $recipientLog);

            Log::warning('telegram.admin_broadcast_recipient_failed', [
                'broadcast_id' => $broadcast->id,
                'recipient_id' => $claimed->id,
                'customer_id' => $claimed->customer_id,
                'attempts' => $claimed->attempts,
                'terminal' => $terminal,
                'status' => $e instanceof RequestException ? $e->response?->status() : null,
                'exception' => $e::class,
                'message' => TelegramBotApiRedactor::redactText($e->getMessage()),
            ]);

            return $terminal ? 'terminal_failure' : 'transient_failure';
        }

        TelegramBroadcastRecipient::query()
            ->whereKey($claimed->id)
            ->where('send_claim_token', $token)
            ->whereNull('sent_at')
            ->update([
                'sent_at' => now(),
                'send_started_at' => null,
                'send_claim_token' => null,
                'failed_at' => null,
                'terminal_failed_at' => null,
                'last_error' => null,
            ]);

        $recipientLog['duration_ms'] = DogfoodTiming::elapsedMs($startedAt);
        DogfoodTiming::log('dogfood.telegram.broadcast_recipient', $recipientLog);

        return 'sent';
    }

    private function refreshAdminBroadcastStatus(int $broadcastId, bool $materialized = false): bool
    {
        $recipientCount = TelegramBroadcastRecipient::query()->where('broadcast_id', $broadcastId)->count();
        $sentCount = TelegramBroadcastRecipient::query()->where('broadcast_id', $broadcastId)->whereNotNull('sent_at')->count();
        $terminalFailedCount = TelegramBroadcastRecipient::query()->where('broadcast_id', $broadcastId)->whereNotNull('terminal_failed_at')->count();
        $retryableCount = TelegramBroadcastRecipient::query()
            ->where('broadcast_id', $broadcastId)
            ->whereNull('sent_at')
            ->whereNull('terminal_failed_at')
            ->where('attempts', '<', self::MAX_RECIPIENT_ATTEMPTS)
            ->count();
        $exhaustedCount = TelegramBroadcastRecipient::query()
            ->where('broadcast_id', $broadcastId)
            ->whereNull('sent_at')
            ->whereNull('terminal_failed_at')
            ->where('attempts', '>=', self::MAX_RECIPIENT_ATTEMPTS)
            ->count();
        if ($exhaustedCount > 0) {
            TelegramBroadcastRecipient::query()
                ->where('broadcast_id', $broadcastId)
                ->whereNull('sent_at')
                ->whereNull('terminal_failed_at')
                ->where('attempts', '>=', self::MAX_RECIPIENT_ATTEMPTS)
                ->update(['terminal_failed_at' => now()]);
            $terminalFailedCount += $exhaustedCount;
            $retryableCount = max(0, $retryableCount - $exhaustedCount);
        }

        $pendingCount = max(0, $recipientCount - $sentCount - $terminalFailedCount);
        $complete = $recipientCount > 0 && $pendingCount === 0;
        $status = match (true) {
            $recipientCount === 0 => TelegramBroadcast::STATUS_COMPLETED,
            $complete && $sentCount === 0 => TelegramBroadcast::STATUS_FAILED,
            $complete && $terminalFailedCount > 0 => TelegramBroadcast::STATUS_PARTIALLY_FAILED,
            $complete => TelegramBroadcast::STATUS_COMPLETED,
            $sentCount > 0 || $terminalFailedCount > 0 => TelegramBroadcast::STATUS_IN_PROGRESS,
            default => TelegramBroadcast::STATUS_QUEUED,
        };

        $updates = [
            'status' => $status,
            'recipient_count' => $recipientCount,
            'sent_count' => $sentCount,
            'failed_count' => $terminalFailedCount,
            'terminal_failed_count' => $terminalFailedCount,
            'pending_count' => $pendingCount,
            'completed_at' => $complete || $recipientCount === 0 ? now() : null,
        ];
        if ($materialized) {
            $updates['recipients_materialized_at'] = now();
        }

        TelegramBroadcast::query()->whereKey($broadcastId)->update($updates);

        DogfoodTiming::log('dogfood.telegram.broadcast_finished', [
            'broadcast_id' => $broadcastId,
            'recipient_count' => $recipientCount,
            'sent' => $sentCount,
            'failed' => $terminalFailedCount,
            'pending' => $pendingCount,
            'status' => $status,
        ]);

        return $retryableCount > 0;
    }

    private function adminBroadcastText(string $message, bool $english): string
    {
        return implode("\n", [
            $english ? '📣 Info from Admin' : '📣 Info dari Admin',
            '━━━━━━━━━━━━━━',
            $message,
            '',
            $english ? 'Need help? Reply with /support.' : 'Butuh bantuan? Balas /support ya.',
        ]);
    }

    /**
     * Broadcast buttons may be stored either as one Telegram reply markup or
     * as an `id` / `en` map when the button label is localized per recipient.
     *
     * @param  array<string, mixed>|null  $replyMarkup
     * @return array<string, mixed>
     */
    private function adminBroadcastReplyMarkup(?array $replyMarkup, bool $english): array
    {
        if ($replyMarkup === null || $replyMarkup === []) {
            return [];
        }

        if (array_key_exists('id', $replyMarkup) || array_key_exists('en', $replyMarkup)) {
            $localized = $replyMarkup[$english ? 'en' : 'id'] ?? [];

            return is_array($localized) ? $localized : [];
        }

        return $replyMarkup;
    }

    private function isPermanentRecipientFailure(Throwable $e): bool
    {
        return $e instanceof RequestException && in_array($e->response?->status(), [400, 403], true);
    }
}
