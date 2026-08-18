<?php

namespace App\Services\Telegram;

use App\Jobs\SendTelegramBroadcastBatch;
use App\Models\Customer;
use App\Models\TelegramBroadcast;
use App\Support\DogfoodTiming;
use Carbon\CarbonInterface;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class TelegramBroadcastService
{
    /** Compatibility aliases retained for callers of the pre-pipeline seam. */
    public const RECIPIENT_RETRY_DELAY_SECONDS = AdminBroadcastDeliveryPipeline::RECIPIENT_RETRY_DELAY_SECONDS;

    public const ADMIN_BATCH_SIZE = AdminBroadcastDeliveryPipeline::BATCH_SIZE;

    public const MAX_RECIPIENT_ATTEMPTS = AdminBroadcastDeliveryPipeline::MAX_ATTEMPTS;

    public function __construct(
        private TelegramBotClient $bot,
        private TelegramLocale $locale,
        private AdminBroadcastDeliveryPipeline $delivery,
    ) {}

    /**
     * @param  array<string, mixed>  $replyMarkup
     * @return array{broadcast_id:int|null, sent:int, failed:int, skipped:bool, queued:bool, recipient_count:int, telegram_total_duration_ms:float, per_recipient_telegram:list<array{}>}
     */
    public function queueAdminBroadcast(string $message, array $replyMarkup = [], ?string $idempotencyKey = null, ?string $photoFileId = null): array
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

        $broadcastKey = $idempotencyKey === null
            ? (string) Str::uuid()
            : $this->stableBroadcastKey($idempotencyKey);

        $broadcast = DB::transaction(function () use ($broadcastKey, $message, $replyMarkup, $photoFileId): TelegramBroadcast {
            DB::table('telegram_broadcasts')->insertOrIgnore([
                'broadcast_key' => $broadcastKey,
                'source' => 'admin',
                'message' => $message,
                'photo_file_id' => $photoFileId,
                'reply_markup' => $replyMarkup === [] ? null : json_encode($replyMarkup, JSON_THROW_ON_ERROR),
                'status' => TelegramBroadcast::STATUS_QUEUED,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return TelegramBroadcast::query()->where('broadcast_key', $broadcastKey)->firstOrFail();
        });

        $this->dispatchAdminBroadcast($broadcast);

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
        return $this->delivery->deliver($broadcastId, $batchSize);
    }

    public function recoverableBroadcastsQuery(CarbonInterface $at): Builder
    {
        return $this->delivery->recoverableBroadcastsQuery($at);
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
                            ? $this->locale->translate($customer, 'notification.broadcast.title')
                            : (is_string($title) ? $title : $title($customer));
                        $resolvedReplyMarkup = is_array($replyMarkup) ? $replyMarkup : $replyMarkup($customer);
                        $text = $this->wrapBroadcastText(
                            $resolvedTitle,
                            $resolvedMessage,
                            $this->locale->translate($customer, 'notification.broadcast.help'),
                        );
                        $this->bot->sendMessage($chatId, $text, $resolvedReplyMarkup);
                        $sent++;
                    } catch (Throwable $exception) {
                        report($exception);
                        $failed++;
                        $recipient['status'] = 'error';
                        $recipient['exception'] = $exception::class;
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

    private function dispatchAdminBroadcast(TelegramBroadcast $broadcast): void
    {
        Cache::lock('telegram:admin-broadcast-dispatch:'.$broadcast->id, 30)
            ->block(5, function () use ($broadcast): void {
                $current = TelegramBroadcast::query()->findOrFail($broadcast->id);
                if ($current->queue_dispatched_at !== null) {
                    return;
                }

                $job = new SendTelegramBroadcastBatch($current->id, now()->toISOString());
                try {
                    $pendingDispatch = $job::dispatch($current->id, $job->jobEnqueuedAt)
                        ->onQueue(AdminBroadcastDeliveryPipeline::QUEUE)
                        ->afterCommit();
                    unset($pendingDispatch);
                } catch (Throwable $exception) {
                    (new UniqueLock(app(CacheRepository::class)))->release($job);

                    throw $exception;
                }

                TelegramBroadcast::query()
                    ->whereKey($current->id)
                    ->whereNull('queue_dispatched_at')
                    ->update(['queue_dispatched_at' => now()]);
            });
    }

    private function wrapBroadcastText(string $title, string $message, string $help): string
    {
        return implode("\n", [
            $title,
            '━━━━━━━━━━━━━━',
            $message,
            '',
            $help,
        ]);
    }

    private function stableBroadcastKey(string $idempotencyKey): string
    {
        $hex = hash('sha256', 'telegram-admin-broadcast:'.$idempotencyKey);

        return implode('-', [
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ]);
    }
}
