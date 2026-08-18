<?php

namespace App\Services\Telegram;

use App\Enums\TelegramOutboundEffectState;
use App\Jobs\SendTelegramBroadcastBatch;
use App\Models\Customer;
use App\Models\TelegramBroadcast;
use App\Models\TelegramBroadcastRecipient;
use App\Models\TelegramOutboundEffect;
use App\Support\DogfoodTiming;
use Carbon\CarbonInterface;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class TelegramBroadcastService
{
    public const RECIPIENT_RETRY_DELAY_SECONDS = 30;

    private const MAX_PROVIDER_RETRY_AFTER_SECONDS = 300;

    public const ADMIN_BATCH_SIZE = 25;

    public const MAX_RECIPIENT_ATTEMPTS = 5;

    public function __construct(
        private TelegramBotClient $bot,
        private TelegramBroadcastRateLimiter $rateLimiter,
        private TelegramLocale $locale,
        private TelegramOutboundEffectService $outboundEffects,
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

        $this->ensureAdminBroadcastEffects($broadcast);
        $effects = $this->dueAdminBroadcastEffects($broadcast, $batchSize);

        if ($effects->isNotEmpty()) {
            $broadcast->forceFill(['status' => TelegramBroadcast::STATUS_IN_PROGRESS])->save();
        }

        $sent = 0;
        $transientFailures = 0;
        $terminalFailures = 0;
        $unknown = 0;
        $processed = 0;
        $retryAfterSeconds = null;

        foreach ($effects as $effect) {
            $rateLimit = $this->rateLimiter->tryAcquire();
            if (! $rateLimit->allowed) {
                $retryAfterSeconds = max($retryAfterSeconds ?? 0, $rateLimit->retryAfterSeconds);
                break;
            }

            $outcome = $this->sendAdminBroadcastRecipient($broadcast, $effect);
            if ($outcome['status'] === 'skipped') {
                continue;
            }

            $processed++;
            if (isset($outcome['retry_after_seconds'])) {
                $retryAfterSeconds = max($retryAfterSeconds ?? 0, $outcome['retry_after_seconds']);
            }
            $sent += $outcome['status'] === 'sent' ? 1 : 0;
            $transientFailures += $outcome['status'] === 'transient_failure' ? 1 : 0;
            $terminalFailures += $outcome['status'] === 'terminal_failure' ? 1 : 0;
            $unknown += $outcome['status'] === 'unknown' ? 1 : 0;

            if (isset($outcome['retry_after_seconds'])) {
                break;
            }
        }

        $hasMore = $this->refreshAdminBroadcastStatus($broadcast->id);
        if ($hasMore
            && $retryAfterSeconds === null
            && $transientFailures === 0
            && $processed >= $this->rateLimiter->maxMessagesPerWindow()) {
            $retryAfterSeconds = 1;
        }

        return new TelegramBroadcastBatchResult(
            processed: $processed,
            sent: $sent,
            transientFailures: $transientFailures,
            terminalFailures: $terminalFailures,
            hasMore: $hasMore,
            retryAfterSeconds: $retryAfterSeconds,
            unknown: $unknown,
        );
    }

    public function recoverableBroadcastsQuery(CarbonInterface $at): Builder
    {
        return TelegramBroadcast::query()
            ->whereNotIn('status', [
                TelegramBroadcast::STATUS_COMPLETED,
                TelegramBroadcast::STATUS_PARTIALLY_FAILED,
                TelegramBroadcast::STATUS_FAILED,
                TelegramBroadcast::STATUS_SKIPPED,
            ])
            ->where(function ($query) use ($at): void {
                $query->whereNull('recipients_materialized_at')
                    ->orWhereExists(function ($effects) use ($at): void {
                        $effects
                            ->selectRaw('1')
                            ->from('telegram_outbound_effects')
                            ->where('telegram_outbound_effects.source_type', TelegramBroadcastRecipient::class)
                            ->where('telegram_outbound_effects.effect_type', TelegramOutboundEffectService::TYPE_ADMIN_BROADCAST)
                            ->whereIn('telegram_outbound_effects.state', [
                                TelegramOutboundEffectState::Pending->value,
                                TelegramOutboundEffectState::Failed->value,
                            ])
                            ->where('telegram_outbound_effects.attempts', '<', TelegramOutboundEffectService::ADMIN_BROADCAST_MAX_ATTEMPTS)
                            ->where(function ($due): void {
                                $due
                                    ->whereNull('telegram_outbound_effects.last_error_code')
                                    ->orWhereNotIn('telegram_outbound_effects.last_error_code', [
                                        'telegram_http_400',
                                        'telegram_http_403',
                                        'broadcast_recipient_attempts_exhausted',
                                        'broadcast_recipient_terminal_failure',
                                    ]);
                            })
                            ->where(function ($nextAttempt) use ($at): void {
                                $nextAttempt
                                    ->whereNull('telegram_outbound_effects.next_attempt_at')
                                    ->orWhere('telegram_outbound_effects.next_attempt_at', '<=', $at);
                            })
                            ->whereExists(function ($recipients): void {
                                $recipients
                                    ->selectRaw('1')
                                    ->from('telegram_broadcast_recipients')
                                    ->whereColumn('telegram_broadcast_recipients.broadcast_id', 'telegram_broadcasts.id')
                                    ->whereRaw('telegram_outbound_effects.source_id = CAST(telegram_broadcast_recipients.id AS TEXT)');
                            });
                    })
                    ->orWhereHas('recipients', function ($recipients) use ($at): void {
                        $recipients
                            ->whereNull('sent_at')
                            ->whereNull('terminal_failed_at')
                            ->where('attempts', '<', self::MAX_RECIPIENT_ATTEMPTS)
                            ->where(function ($retry) use ($at): void {
                                $retry->whereNull('retry_at')->orWhere('retry_at', '<=', $at);
                            })
                            ->whereNotExists(function ($effects): void {
                                $effects
                                    ->selectRaw('1')
                                    ->from('telegram_outbound_effects')
                                    ->where('telegram_outbound_effects.source_type', TelegramBroadcastRecipient::class)
                                    ->where('telegram_outbound_effects.effect_type', TelegramOutboundEffectService::TYPE_ADMIN_BROADCAST)
                                    ->whereRaw('telegram_outbound_effects.source_id = CAST(telegram_broadcast_recipients.id AS TEXT)');
                            });
                    });
            });
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
                DB::transaction(function () use ($customers, $broadcast): void {
                    foreach ($customers as $customer) {
                        $telegramId = trim((string) $customer->telegram_id);
                        if ($telegramId === '') {
                            continue;
                        }

                        TelegramBroadcastRecipient::query()->insertOrIgnore([
                            'broadcast_id' => $broadcast->id,
                            'customer_id' => $customer->id,
                            'telegram_id' => $telegramId,
                            'locale' => $this->locale->code($customer),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $recipient = TelegramBroadcastRecipient::query()
                            ->where('broadcast_id', $broadcast->id)
                            ->where('telegram_id', $telegramId)
                            ->firstOrFail();

                        $this->materializeAdminBroadcastEffect($broadcast, $recipient);
                    }
                }, 5);
            });

        $this->refreshAdminBroadcastStatus($broadcast->id, true);
    }

    private function ensureAdminBroadcastEffects(TelegramBroadcast $broadcast): void
    {
        TelegramBroadcastRecipient::query()
            ->where('broadcast_id', $broadcast->id)
            ->orderBy('id')
            ->chunkById(200, function ($recipients) use ($broadcast): void {
                DB::transaction(function () use ($recipients, $broadcast): void {
                    foreach ($recipients as $recipient) {
                        $this->materializeAdminBroadcastEffect($broadcast, $recipient);
                    }
                }, 5);
            });
    }

    private function materializeAdminBroadcastEffect(
        TelegramBroadcast $broadcast,
        TelegramBroadcastRecipient $recipient,
    ): ?TelegramOutboundEffect {
        $effectKey = TelegramOutboundEffectService::adminBroadcastRecipientEffectKey(
            (string) $broadcast->broadcast_key,
            (string) $recipient->getKey(),
        );
        $existing = TelegramOutboundEffect::query()->where('effect_key', $effectKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        $state = TelegramOutboundEffectState::Pending->value;
        $sentAt = null;
        $failedAt = null;
        $unknownAt = null;
        $nextAttemptAt = now();
        $lastErrorCode = null;
        $lastError = null;

        if ($recipient->sent_at !== null) {
            $state = TelegramOutboundEffectState::Sent->value;
            $sentAt = $recipient->sent_at;
            $nextAttemptAt = null;
        } elseif ($recipient->terminal_failed_at !== null) {
            $state = TelegramOutboundEffectState::Failed->value;
            $failedAt = $recipient->terminal_failed_at;
            $nextAttemptAt = null;
            $lastErrorCode = 'broadcast_recipient_terminal_failure';
            $lastError = 'Legacy Admin Broadcast recipient failure is terminal.';
        } elseif ($recipient->failed_at !== null) {
            $state = TelegramOutboundEffectState::Failed->value;
            $failedAt = $recipient->failed_at;
            $nextAttemptAt = $recipient->retry_at ?? $recipient->failed_at->copy()->addSeconds(self::RECIPIENT_RETRY_DELAY_SECONDS);
            $lastErrorCode = 'legacy_broadcast_failure';
            $lastError = 'Legacy Admin Broadcast delivery failed before completion.';
            if ((int) $recipient->attempts >= TelegramOutboundEffectService::ADMIN_BROADCAST_MAX_ATTEMPTS) {
                $nextAttemptAt = null;
                $lastErrorCode = 'broadcast_recipient_attempts_exhausted';
            }
        }

        return $this->outboundEffects->recordAdminBroadcastRecipient(
            broadcast: $broadcast,
            recipient: $recipient,
            state: $state,
            sentAt: $sentAt,
            failedAt: $failedAt,
            unknownAt: $unknownAt,
            nextAttemptAt: $nextAttemptAt,
            lastErrorCode: $lastErrorCode,
            lastError: $lastError,
        );
    }

    private function dueAdminBroadcastEffects(TelegramBroadcast $broadcast, int $batchSize)
    {
        return $this->broadcastEffectsQuery($broadcast->id)
            ->whereIn('state', [
                TelegramOutboundEffectState::Pending->value,
                TelegramOutboundEffectState::Failed->value,
            ])
            ->where('attempts', '<', TelegramOutboundEffectService::ADMIN_BROADCAST_MAX_ATTEMPTS)
            ->where(function (Builder $error): void {
                $error
                    ->whereNull('last_error_code')
                    ->orWhereNotIn('last_error_code', [
                        'telegram_http_400',
                        'telegram_http_403',
                        'broadcast_recipient_attempts_exhausted',
                        'broadcast_recipient_terminal_failure',
                    ]);
            })
            ->where(function (Builder $due): void {
                $due->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->orderByRaw('CAST(source_id AS BIGINT)')
            ->limit(min(self::ADMIN_BATCH_SIZE, max(1, $batchSize)))
            ->get();
    }

    private function broadcastEffectsQuery(int $broadcastId): Builder
    {
        return TelegramOutboundEffect::query()
            ->where('source_type', TelegramBroadcastRecipient::class)
            ->where('effect_type', TelegramOutboundEffectService::TYPE_ADMIN_BROADCAST)
            ->whereIn('source_id', TelegramBroadcastRecipient::query()
                ->where('broadcast_id', $broadcastId)
                ->selectRaw('CAST(id AS TEXT)'));
    }

    /**
     * @return array{status: 'skipped'|'sent'|'transient_failure'|'terminal_failure'|'unknown', retry_after_seconds?: int}
     */
    private function sendAdminBroadcastRecipient(TelegramBroadcast $broadcast, TelegramOutboundEffect $effect): array
    {
        $claimed = $this->outboundEffects->claim((string) $effect->getKey());
        if ($claimed === null) {
            return ['status' => 'skipped'];
        }

        $startedAt = DogfoodTiming::now();
        $recipientLog = [
            'broadcast_id' => $broadcast->id,
            'recipient_id' => $claimed->source_id,
            'recipient_correlation_id' => DogfoodTiming::correlation($claimed->telegram_chat_id),
            'operation' => filled($broadcast->photo_file_id) ? 'sendPhotoReference' : 'sendMessage',
            'status' => 'ok',
            'duration_ms' => 0.0,
        ];

        try {
            $replyMarkup = $this->adminBroadcastReplyMarkup($broadcast->reply_markup, (string) $claimed->locale);
            $response = filled($broadcast->photo_file_id)
                ? $this->bot->sendPhotoReference(
                    $claimed->telegram_chat_id,
                    $broadcast->photo_file_id,
                    $broadcast->message === '' ? null : $broadcast->message,
                    $replyMarkup,
                )
                : $this->bot->sendMessage(
                    $claimed->telegram_chat_id,
                    $this->adminBroadcastText($broadcast->message, (string) $claimed->locale),
                    $replyMarkup,
                );
            $messageId = data_get($response, 'result.message_id');

            if (data_get($response, 'ok') !== true || ! is_numeric($messageId)) {
                throw new RuntimeException('Telegram Admin Broadcast response did not return a successful message ID.');
            }

            if (! $this->outboundEffects->finalizeSent(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                (string) $messageId,
            )) {
                return ['status' => 'skipped'];
            }
        } catch (Throwable $exception) {
            $retryAfterSeconds = $this->providerRetryAfterSeconds($exception);

            if ($this->isBroadcastAmbiguous($exception)) {
                $finalized = $this->outboundEffects->finalizeUnknown(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    $exception,
                );
                $recipientLog['status'] = 'unknown';
                $recipientLog['exception'] = $exception::class;
                $recipientLog['duration_ms'] = DogfoodTiming::elapsedMs($startedAt);
                DogfoodTiming::log('dogfood.telegram.broadcast_recipient', $recipientLog);
                $this->logBroadcastRecipientFailure($broadcast, $claimed, $recipientLog, $exception, false);

                return $finalized ? ['status' => 'unknown'] : ['status' => 'skipped'];
            }

            $finalized = $this->outboundEffects->finalizeFailed(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                $exception,
                $retryAfterSeconds,
            );
            if (! $finalized) {
                return ['status' => 'skipped'];
            }

            $fresh = TelegramOutboundEffect::query()->find($claimed->getKey());
            $terminal = $fresh !== null && $this->outboundEffects->isTerminalFailure($fresh);
            $recipientLog['status'] = 'error';
            $recipientLog['exception'] = $exception::class;
            $recipientLog['terminal'] = $terminal;
            $recipientLog['retry_after_seconds'] = $retryAfterSeconds;
            $recipientLog['duration_ms'] = DogfoodTiming::elapsedMs($startedAt);
            DogfoodTiming::log('dogfood.telegram.broadcast_recipient', $recipientLog);
            $this->logBroadcastRecipientFailure($broadcast, $claimed, $recipientLog, $exception, $terminal);

            if ($retryAfterSeconds !== null) {
                return ['status' => 'transient_failure', 'retry_after_seconds' => $retryAfterSeconds];
            }

            return ['status' => $terminal ? 'terminal_failure' : 'transient_failure'];
        }

        $recipientLog['duration_ms'] = DogfoodTiming::elapsedMs($startedAt);
        DogfoodTiming::log('dogfood.telegram.broadcast_recipient', $recipientLog);

        return ['status' => 'sent'];
    }

    private function isBroadcastAmbiguous(Throwable $exception): bool
    {
        // Telegram's definite HTTP rejection (including flood control) is a
        // retryable/terminal failure, not an accepted-then-disconnected send.
        if ($exception instanceof RequestException
            && in_array($exception->response?->status(), [400, 403, 429], true)) {
            return false;
        }

        return $this->outboundEffects->isAmbiguous($exception);
    }

    private function logBroadcastRecipientFailure(
        TelegramBroadcast $broadcast,
        TelegramOutboundEffect $effect,
        array $recipientLog,
        Throwable $exception,
        bool $terminal,
    ): void {
        Log::warning('telegram.admin_broadcast_recipient_failed', [
            'operation' => $recipientLog['operation'],
            'broadcast_id' => $broadcast->id,
            'recipient_id' => $effect->source_id,
            'recipient_correlation_id' => $recipientLog['recipient_correlation_id'],
            'attempts' => $effect->attempts,
            'terminal' => $terminal,
            'status' => $exception instanceof RequestException ? $exception->response?->status() : null,
            'exception' => $exception::class,
        ]);
    }

    private function refreshAdminBroadcastStatus(int $broadcastId, bool $materialized = false): bool
    {
        $recipientCount = TelegramBroadcastRecipient::query()->where('broadcast_id', $broadcastId)->count();
        $effects = $this->broadcastEffectsQuery($broadcastId);
        $sentCount = (clone $effects)->where('state', TelegramOutboundEffectState::Sent->value)->count();
        $terminalFailedCount = (clone $effects)
            ->where('state', TelegramOutboundEffectState::Failed->value)
            ->where(function (Builder $terminal): void {
                $terminal
                    ->whereIn('last_error_code', [
                        'telegram_http_400',
                        'telegram_http_403',
                        'broadcast_recipient_attempts_exhausted',
                        'broadcast_recipient_terminal_failure',
                    ])
                    ->orWhere('attempts', '>=', TelegramOutboundEffectService::ADMIN_BROADCAST_MAX_ATTEMPTS);
            })
            ->count();
        $retryableCount = (clone $effects)
            ->whereIn('state', [
                TelegramOutboundEffectState::Pending->value,
                TelegramOutboundEffectState::Failed->value,
            ])
            ->where('attempts', '<', TelegramOutboundEffectService::ADMIN_BROADCAST_MAX_ATTEMPTS)
            ->where(function (Builder $error): void {
                $error
                    ->whereNull('last_error_code')
                    ->orWhereNotIn('last_error_code', [
                        'telegram_http_400',
                        'telegram_http_403',
                        'broadcast_recipient_attempts_exhausted',
                        'broadcast_recipient_terminal_failure',
                    ]);
            })
            ->count();

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
            'operation' => 'admin_broadcast',
            'broadcast_id' => $broadcastId,
            'recipient_count' => $recipientCount,
            'sent' => $sentCount,
            'failed' => $terminalFailedCount,
            'pending' => $pendingCount,
            'status' => $status,
        ]);

        return $retryableCount > 0;
    }

    /**
     * Broadcast buttons may be stored either as one Telegram reply markup or
     * as an `id` / `en` / `ms` map when the button label is localized per recipient.
     *
     * @param  array<string, mixed>|null  $replyMarkup
     * @return array<string, mixed>
     */
    private function adminBroadcastReplyMarkup(?array $replyMarkup, string $locale): array
    {
        if ($replyMarkup === null || $replyMarkup === []) {
            return [];
        }

        if (array_key_exists('id', $replyMarkup) || array_key_exists('en', $replyMarkup) || array_key_exists('ms', $replyMarkup)) {
            $locale = $this->locale->codeForLocale($locale);
            $localized = $replyMarkup[$locale] ?? $replyMarkup['en'] ?? $replyMarkup['id'] ?? [];

            return is_array($localized) ? $localized : [];
        }

        return $replyMarkup;
    }

    private function adminBroadcastText(string $message, string $locale): string
    {
        return $this->wrapBroadcastText(
            $this->locale->translateLocale($locale, 'notification.broadcast.title'),
            $message,
            $this->locale->translateLocale($locale, 'notification.broadcast.help'),
        );
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

    private function providerRetryAfterSeconds(Throwable $e): ?int
    {
        if (! $e instanceof RequestException || $e->response?->status() !== 429) {
            return null;
        }

        $retryAfter = data_get($e->response?->json(), 'parameters.retry_after')
            ?? $e->response?->header('Retry-After');
        if (! is_numeric($retryAfter)) {
            return 1;
        }

        return min(self::MAX_PROVIDER_RETRY_AFTER_SECONDS, max(1, (int) $retryAfter));
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
                        ->onQueue('telegram-broadcast')
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
