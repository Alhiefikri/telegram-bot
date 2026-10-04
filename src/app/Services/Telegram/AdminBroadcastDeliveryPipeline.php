<?php

namespace App\Services\Telegram;

use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Models\Customer;
use App\Models\TelegramBroadcast;
use App\Models\TelegramBroadcastRecipient;
use App\Models\TelegramOutboundEffect;
use App\Support\DogfoodTiming;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin Broadcast is an effect-family adapter. The shared outbound ledger
 * owns dispatchability, claim ownership, leases, ambiguity classification,
 * retry timing, and token-checked finalization. This adapter owns the
 * recipient snapshot, localized rendering, rate limiting, and broadcast
 * aggregate projection boundary.
 */
final class AdminBroadcastDeliveryPipeline
{
    public const RECIPIENT_RETRY_DELAY_SECONDS = 30;

    public const BATCH_SIZE = 25;

    public const MAX_ATTEMPTS = TelegramOutboundEffectPolicy::ADMIN_BROADCAST_MAX_ATTEMPTS;

    public const QUEUE = 'telegram-broadcast';

    public function __construct(
        private readonly TelegramBotClient $bot,
        private readonly TelegramBroadcastRateLimiter $rateLimiter,
        private readonly TelegramLocale $locale,
        private readonly TelegramOutboundEffectService $outboundEffects,
        private readonly TelegramOutboundEffectLedgerProtocol $ledger,
        private readonly TelegramOutboundResponseValidator $responseValidator,
    ) {}

    public function deliver(int $broadcastId, int $batchSize = self::BATCH_SIZE): TelegramBroadcastBatchResult
    {
        $broadcast = TelegramBroadcast::query()->findOrFail($broadcastId);

        if (blank(config('services.telegram.token'))) {
            $broadcast->forceFill([
                'status' => TelegramBroadcast::STATUS_SKIPPED,
                'completed_at' => now(),
            ])->save();

            return new TelegramBroadcastBatchResult(0, 0, 0, 0, false);
        }

        $materialized = false;
        if ($broadcast->recipients_materialized_at === null) {
            $this->ensureAdminBroadcastRecipients($broadcast);
            $materialized = true;
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

        $hasMore = $this->syncAdminBroadcastProjection($broadcast->id, $materialized);
        $projection = TelegramBroadcast::query()->find($broadcast->id);
        DogfoodTiming::log('dogfood.telegram.broadcast_finished', [
            'operation' => 'admin_broadcast',
            'broadcast_id' => $broadcast->id,
            'recipient_count' => $projection?->recipient_count,
            'sent' => $projection?->sent_count,
            'failed' => $projection?->failed_count,
            'pending' => $projection?->pending_count,
            'status' => $projection?->status,
        ]);
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
        $readyEffectSourceIds = $this->readyAdminBroadcastEffectsQuery($at)->select('source_id');
        $readyBroadcastIds = TelegramBroadcastRecipient::query()
            ->whereIn(DB::raw('CAST(telegram_broadcast_recipients.id AS TEXT)'), $readyEffectSourceIds)
            ->select('broadcast_id');
        $legacyBroadcastIds = TelegramBroadcastRecipient::query()
            ->whereNull('sent_at')
            ->whereNull('terminal_failed_at')
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where(function (Builder $retry) use ($at): void {
                $retry->whereNull('retry_at')->orWhere('retry_at', '<=', $at);
            })
            ->whereNotExists(function ($effects): void {
                $effects
                    ->selectRaw('1')
                    ->from('telegram_outbound_effects')
                    ->where('telegram_outbound_effects.source_type', TelegramBroadcastRecipient::class)
                    ->where('telegram_outbound_effects.effect_type', TelegramOutboundEffectService::TYPE_ADMIN_BROADCAST)
                    ->whereRaw('telegram_outbound_effects.source_id = CAST(telegram_broadcast_recipients.id AS TEXT)');
            })
            ->select('broadcast_id');

        return TelegramBroadcast::query()
            ->where(function (Builder $query) use ($readyBroadcastIds, $legacyBroadcastIds): void {
                $query
                    ->whereNull('recipients_materialized_at')
                    ->orWhere(function (Builder $active) use ($readyBroadcastIds, $legacyBroadcastIds): void {
                        $active
                            ->whereNotIn('status', [
                                TelegramBroadcast::STATUS_COMPLETED,
                                TelegramBroadcast::STATUS_PARTIALLY_FAILED,
                                TelegramBroadcast::STATUS_FAILED,
                                TelegramBroadcast::STATUS_SKIPPED,
                            ])
                            ->where(function (Builder $work) use ($readyBroadcastIds, $legacyBroadcastIds): void {
                                $work
                                    ->whereIn('id', $readyBroadcastIds)
                                    ->orWhereIn('id', $legacyBroadcastIds);
                            });
                    });
            });
    }

    public function refreshProjection(int $broadcastId, bool $materialized = false): bool
    {
        return $this->syncAdminBroadcastProjection($broadcastId, $materialized);
    }

    public function synchronizeRecoveredUnknown(TelegramOutboundEffect $effect): void
    {
        $this->synchronizeUnknownProjection($effect);
        $broadcastId = TelegramBroadcastRecipient::query()->whereKey($effect->source_id)->value('broadcast_id');
        if ($broadcastId !== null) {
            $this->syncAdminBroadcastProjection((int) $broadcastId);
        }
    }

    /**
     * Repair recipient and aggregate projections for effects already finalized
     * as sent. Recovery never claims or dispatches these effects.
     *
     * @return int Number of recipient projections repaired.
     */
    public function reconcileSentProjections(int $chunk = 100): int
    {
        $repaired = 0;

        TelegramOutboundEffect::query()
            ->where('source_type', TelegramBroadcastRecipient::class)
            ->where('effect_type', TelegramOutboundEffectType::AdminBroadcastRecipient->value)
            ->where('state', TelegramOutboundEffectState::Sent->value)
            ->orderBy('id')
            ->chunkById(max(1, $chunk), function ($effects) use (&$repaired): void {
                $broadcastIds = [];

                foreach ($effects as $effect) {
                    $recipient = TelegramBroadcastRecipient::query()
                        ->whereKey($effect->source_id)
                        ->first(['id', 'broadcast_id']);
                    if ($recipient === null) {
                        continue;
                    }

                    if ($this->synchronizeSentProjection($effect)) {
                        $repaired++;
                    }
                    $broadcastIds[(int) $recipient->broadcast_id] = true;
                }

                foreach (array_keys($broadcastIds) as $broadcastId) {
                    DB::transaction(
                        fn (): bool => $this->syncAdminBroadcastProjection((int) $broadcastId),
                        5,
                    );
                }
            });

        return $repaired;
    }

    private function readyAdminBroadcastEffectsQuery(?CarbonInterface $at = null): Builder
    {
        return $this->ledger->readyToDispatchQuery($at, TelegramOutboundEffectType::AdminBroadcastRecipient->value)
            ->where('source_type', TelegramBroadcastRecipient::class);
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
            if ((int) $recipient->attempts >= self::MAX_ATTEMPTS) {
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
        return $this->readyAdminBroadcastEffectsQuery()
            ->whereIn('source_id', TelegramBroadcastRecipient::query()
                ->where('broadcast_id', $broadcast->id)
                ->selectRaw('CAST(id AS TEXT)'))
            ->orderByRaw('CAST(source_id AS BIGINT)')
            ->limit(min(self::BATCH_SIZE, max(1, $batchSize)))
            ->get();
    }

    /**
     * @return array{status: 'skipped'|'sent'|'transient_failure'|'terminal_failure'|'unknown', retry_after_seconds?: int}
     */
    private function sendAdminBroadcastRecipient(TelegramBroadcast $broadcast, TelegramOutboundEffect $effect): array
    {
        $claimed = $this->ledger->claim((string) $effect->getKey());
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
            $messageId = $this->responseValidator->messageIdFromResponse($response, 'Telegram Admin Broadcast');

            $finalized = $this->ledger->finalizeSent(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                (string) $messageId,
            );
            if ($finalized === null) {
                return ['status' => 'skipped'];
            }

            $this->synchronizeSentProjection($finalized);
        } catch (Throwable $exception) {
            $retryAfterSeconds = $this->ledger->retryAfterSeconds($exception);

            if ($this->ledger->isAmbiguous($exception)) {
                $finalized = $this->ledger->finalizeUnknown(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    $exception,
                );
                if ($finalized !== null) {
                    $this->synchronizeUnknownProjection($finalized);
                }
                $recipientLog['status'] = 'unknown';
                $recipientLog['exception'] = $exception::class;
                $recipientLog['duration_ms'] = DogfoodTiming::elapsedMs($startedAt);
                DogfoodTiming::log('dogfood.telegram.broadcast_recipient', $recipientLog);
                $this->logBroadcastRecipientFailure($broadcast, $claimed, $recipientLog, $exception, false);

                return $finalized ? ['status' => 'unknown'] : ['status' => 'skipped'];
            }

            $finalized = $this->ledger->finalizeFailed(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                $exception,
                $retryAfterSeconds,
            );
            if (! $finalized) {
                return ['status' => 'skipped'];
            }

            $this->synchronizeFailedProjection($finalized, $exception);

            $fresh = TelegramOutboundEffect::query()->find($claimed->getKey());
            $terminal = $fresh !== null && $this->ledger->isTerminalFailure($fresh);
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

    private function synchronizeSentProjection(TelegramOutboundEffect $effect): bool
    {
        if (! $this->responseValidator->isValidMessageId($effect->telegram_message_id)) {
            return false;
        }

        return TelegramBroadcastRecipient::query()
            ->whereKey($effect->source_id)
            ->whereNull('sent_at')
            ->update([
                'sent_at' => $effect->sent_at ?? now(),
                'failed_at' => null,
                'retry_at' => null,
                'terminal_failed_at' => null,
                'last_error' => null,
                'attempts' => (int) $effect->attempts,
                'updated_at' => now(),
            ]) === 1;
    }

    private function synchronizeUnknownProjection(TelegramOutboundEffect $effect): void
    {
        TelegramBroadcastRecipient::query()
            ->whereKey($effect->source_id)
            ->whereNull('sent_at')
            ->update([
                'attempts' => (int) $effect->attempts,
                'updated_at' => now(),
            ]);
    }

    private function synchronizeFailedProjection(TelegramOutboundEffect $effect, Throwable $exception): void
    {
        $terminal = $this->ledger->isTerminalFailure($effect);
        TelegramBroadcastRecipient::query()
            ->whereKey($effect->source_id)
            ->whereNull('sent_at')
            ->update([
                'failed_at' => $effect->failed_at ?? now(),
                'retry_at' => $terminal ? null : $effect->next_attempt_at,
                'terminal_failed_at' => $terminal ? ($effect->failed_at ?? now()) : null,
                'last_error' => $this->projectionError($exception),
                'attempts' => $terminal ? self::MAX_ATTEMPTS : (int) $effect->attempts,
                'updated_at' => now(),
            ]);
    }

    private function projectionError(Throwable $exception): string
    {
        return mb_substr(
            TelegramBotApiRedactor::redactText($exception::class.': '.$exception->getMessage()),
            0,
            2000,
        );
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

    private function syncAdminBroadcastProjection(int $broadcastId, bool $materialized = false): bool
    {
        $broadcast = TelegramBroadcast::query()->lockForUpdate()->find($broadcastId);
        if ($broadcast === null) {
            return false;
        }

        $recipientIds = TelegramBroadcastRecipient::query()
            ->where('broadcast_id', $broadcastId)
            ->selectRaw('CAST(id AS TEXT)');
        $effects = TelegramOutboundEffect::query()
            ->where('source_type', TelegramBroadcastRecipient::class)
            ->where('effect_type', TelegramOutboundEffectType::AdminBroadcastRecipient->value)
            ->whereIn('source_id', $recipientIds);
        $recipientCount = TelegramBroadcastRecipient::query()->where('broadcast_id', $broadcastId)->count();
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
                    ->orWhere('attempts', '>=', self::MAX_ATTEMPTS);
            })
            ->count();
        $retryableCount = $this->ledger->retryableQuery(TelegramOutboundEffectType::AdminBroadcastRecipient->value)
            ->where('source_type', TelegramBroadcastRecipient::class)
            ->whereIn('source_id', TelegramBroadcastRecipient::query()
                ->where('broadcast_id', $broadcastId)
                ->selectRaw('CAST(id AS TEXT)'))
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
            'completed_at' => $complete || $recipientCount === 0
                ? ($broadcast->completed_at ?? now())
                : null,
            'updated_at' => now(),
        ];
        if ($materialized) {
            $updates['recipients_materialized_at'] = now();
        }

        TelegramBroadcast::query()->whereKey($broadcastId)->update($updates);

        return $retryableCount > 0;
    }
}
