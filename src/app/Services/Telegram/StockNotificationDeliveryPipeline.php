<?php

namespace App\Services\Telegram;

use App\Enums\InventoryMovementType;
use App\Enums\TelegramOutboundEffectState;
use App\Jobs\SendProductStockNotifications;
use App\Models\InventoryMovement;
use App\Models\StockNotificationEvent;
use App\Models\StockNotificationRecipient;
use App\Models\TelegramOutboundEffect;
use App\Support\DogfoodTiming;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class StockNotificationDeliveryPipeline
{
    public const MAX_ATTEMPTS = 5;

    public const QUEUE = 'telegram-broadcast';

    private const RETRY_DELAY_SECONDS = 30;

    public function __construct(
        private TelegramProductNotificationService $notifications,
        private TelegramBotClient $bot,
        private ?TelegramOutboundEffectService $outboundEffects = null,
    ) {}

    public function materialize(int $movementId): ?StockNotificationEvent
    {
        return $this->snapshotEvent($movementId);
    }

    /** @return array{processed:int, transient_failures:int, has_more:bool} */
    public function deliver(int $movementId, int $batchSize = 100): array
    {
        $startedAt = DogfoodTiming::now();
        $event = $this->snapshotEvent($movementId);
        if (! $event) {
            $timingResult = [
                'sent' => 0,
                'failed' => 0,
                'skipped' => true,
                'recipient_count' => 0,
                'telegram_total_duration_ms' => 0.0,
                'per_recipient_telegram' => [],
                'duration_ms' => DogfoodTiming::elapsedMs($startedAt),
            ];
            DogfoodTiming::log('dogfood.telegram.broadcast_finished', $timingResult);

            return ['processed' => 0, 'transient_failures' => 0, 'has_more' => false];
        }

        $effects = $this->dueEffects($event->id, $batchSize);

        $processed = 0;
        $sent = 0;
        $failed = 0;
        $transientFailures = 0;
        $telegramTotalMs = 0.0;
        $perRecipient = [];
        foreach ($effects as $effect) {
            $recipientStartedAt = DogfoodTiming::now();
            $outcome = $this->deliverEffect($effect);
            $status = match ($outcome) {
                'sent' => 'ok',
                'skipped' => 'skipped',
                'unknown' => 'unknown',
                default => 'error',
            };
            $sent += $outcome === 'sent' ? 1 : 0;
            $failed += in_array($outcome, ['transient_failure', 'terminal_failure'], true) ? 1 : 0;
            $processed += $outcome === 'skipped' ? 0 : 1;
            $transientFailures += $outcome === 'transient_failure' ? 1 : 0;

            $recipientLog = [
                'recipient_correlation_id' => DogfoodTiming::correlation($effect->telegram_chat_id),
                'status' => $status,
                'duration_ms' => DogfoodTiming::elapsedMs($recipientStartedAt),
            ];
            $telegramTotalMs = round($telegramTotalMs + $recipientLog['duration_ms'], 2);
            $perRecipient[] = $recipientLog;
            DogfoodTiming::log('dogfood.telegram.broadcast_recipient', $recipientLog + [
                'recipient_index' => count($perRecipient),
            ]);
        }

        $hasMore = $transientFailures > 0 || $this->hasPendingEffects($event->id);
        $this->syncMovementProjection($movementId, $event->id);

        DogfoodTiming::log('dogfood.telegram.broadcast_finished', [
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => false,
            'recipient_count' => $effects->count(),
            'telegram_total_duration_ms' => $telegramTotalMs,
            'per_recipient_telegram' => $perRecipient,
            'duration_ms' => DogfoodTiming::elapsedMs($startedAt),
        ]);

        return [
            'processed' => $processed,
            'transient_failures' => $transientFailures,
            'has_more' => $hasMore,
        ];
    }

    public function reconcile(int $chunk = 100): int
    {
        $ledgerRecipientIds = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_STOCK_NOTIFICATION)
            ->whereIn('state', [
                TelegramOutboundEffectState::Pending->value,
                TelegramOutboundEffectState::Failed->value,
            ])
            ->where('attempts', '<', TelegramOutboundEffectService::STOCK_NOTIFICATION_MAX_ATTEMPTS)
            ->where(function ($error): void {
                $error->whereNull('last_error_code')
                    ->orWhereNotIn('last_error_code', [
                        'telegram_http_400',
                        'telegram_http_403',
                        'stock_notification_attempts_exhausted',
                        'stock_notification_terminal_failure',
                    ]);
            })
            ->where(function ($due): void {
                $due->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('id')
            ->limit(max(1, $chunk))
            ->pluck('source_id');
        $recipientMovementIds = StockNotificationRecipient::query()
            ->whereIn('id', $ledgerRecipientIds)
            ->with('event:id,inventory_movement_id')
            ->get()
            ->pluck('event.inventory_movement_id')->filter()->unique()->values();

        $legacyRecipientMovementIds = StockNotificationRecipient::query()
            ->whereNull('sent_at')
            ->whereNull('terminal_failed_at')
            ->where(function ($attempts): void {
                $attempts
                    ->where('attempts', '<', self::MAX_ATTEMPTS)
                    ->orWhere(function ($staleClaim): void {
                        $staleClaim
                            ->where(function ($claim): void {
                                $claim->whereNotNull('claim_token')
                                    ->orWhereNotNull('claim_expires_at');
                            })
                            ->where(function ($expired): void {
                                $expired->whereNull('claim_expires_at')
                                    ->orWhere('claim_expires_at', '<=', now());
                            });
                    });
            })
            ->where(fn ($query) => $query->whereNull('claim_expires_at')->orWhere('claim_expires_at', '<=', now()))
            ->whereNotExists(function ($effects): void {
                $effects
                    ->selectRaw('1')
                    ->from('telegram_outbound_effects')
                    ->where('telegram_outbound_effects.source_type', StockNotificationRecipient::class)
                    ->where('telegram_outbound_effects.effect_type', TelegramOutboundEffectService::TYPE_STOCK_NOTIFICATION)
                    ->whereRaw('telegram_outbound_effects.source_id = CAST(stock_notification_recipients.id AS TEXT)');
            })
            ->with('event:id,inventory_movement_id')->orderBy('id')->limit(max(1, $chunk))->get()
            ->pluck('event.inventory_movement_id')->filter()->unique()->values();

        // The old one-shot worker recorded a permanent started marker before it
        // fanned out. Treat those in-flight rows as unmaterialized events so a
        // deploy cannot leave them stranded forever.
        $legacyMovementIds = InventoryMovement::query()
            ->whereNotNull('stock_notification_started_at')->whereNull('stock_notification_sent_at')
            ->orderBy('id')->limit(max(1, $chunk))->pluck('id');
        $movementIds = $recipientMovementIds
            ->merge($legacyRecipientMovementIds)
            ->merge($legacyMovementIds)
            ->unique()
            ->values();

        foreach ($movementIds as $movementId) {
            SendProductStockNotifications::dispatch($movementId)->onQueue(self::QUEUE);
        }

        return $movementIds->count();
    }

    private function snapshotEvent(int $movementId): ?StockNotificationEvent
    {
        return DB::transaction(function () use ($movementId): ?StockNotificationEvent {
            $movement = InventoryMovement::query()->with('product')->whereKey($movementId)->lockForUpdate()->first();
            if (! $movement || ! $this->isNotifiable($movement)) {
                return null;
            }

            $event = StockNotificationEvent::firstOrCreate([
                'inventory_movement_id' => $movement->id,
            ], ['kind' => $movement->type->value]);
            if ($event->recipients_snapshotted_at === null) {
                $deliveries = $movement->type === InventoryMovementType::Adjustment
                    ? $this->notifications->stockAddedDeliveries($movement->product, $movement->quantity, $movement->available_before)
                    : $this->notifications->productSoldOutDeliveries($movement->product);
                $now = now();
                $rows = array_map(fn (array $delivery): array => [
                    'stock_notification_event_id' => $event->id,
                    'customer_id' => $delivery['customer_id'],
                    'recipient_type' => $delivery['recipient_type'],
                    'telegram_id' => $delivery['telegram_id'],
                    'locale' => $delivery['locale'],
                    'text' => $delivery['text'],
                    'reply_markup' => $delivery['reply_markup'] === [] ? null : json_encode($delivery['reply_markup'], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $deliveries);
                if ($rows !== []) {
                    StockNotificationRecipient::query()->insertOrIgnore($rows);
                }
                $event->forceFill(['recipients_snapshotted_at' => $now])->save();
            }

            StockNotificationRecipient::query()
                ->where('stock_notification_event_id', $event->id)
                ->orderBy('id')
                ->chunkById(200, function ($recipients) use ($movement): void {
                    foreach ($recipients as $recipient) {
                        $this->materializeRecipientEffect($movement, $recipient);
                    }
                });

            return $event;
        }, 5);
    }

    private function dueEffects(int $eventId, int $batchSize)
    {
        return TelegramOutboundEffect::query()
            ->where('source_type', StockNotificationRecipient::class)
            ->where('effect_type', TelegramOutboundEffectService::TYPE_STOCK_NOTIFICATION)
            ->whereIn('source_id', StockNotificationRecipient::query()
                ->where('stock_notification_event_id', $eventId)
                ->selectRaw('CAST(id AS TEXT)'))
            ->whereIn('state', [
                TelegramOutboundEffectState::Pending->value,
                TelegramOutboundEffectState::Failed->value,
            ])
            ->where('attempts', '<', TelegramOutboundEffectService::STOCK_NOTIFICATION_MAX_ATTEMPTS)
            ->where(function ($error): void {
                $error->whereNull('last_error_code')
                    ->orWhereNotIn('last_error_code', [
                        'telegram_http_400',
                        'telegram_http_403',
                        'stock_notification_attempts_exhausted',
                        'stock_notification_terminal_failure',
                    ]);
            })
            ->where(function ($due): void {
                $due->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->orderByRaw('CAST(source_id AS BIGINT)')
            ->limit(max(1, $batchSize))
            ->get();
    }

    private function hasPendingEffects(int $eventId): bool
    {
        return TelegramOutboundEffect::query()
            ->where('source_type', StockNotificationRecipient::class)
            ->where('effect_type', TelegramOutboundEffectService::TYPE_STOCK_NOTIFICATION)
            ->whereIn('source_id', StockNotificationRecipient::query()
                ->where('stock_notification_event_id', $eventId)
                ->selectRaw('CAST(id AS TEXT)'))
            ->whereIn('state', [
                TelegramOutboundEffectState::Pending->value,
                TelegramOutboundEffectState::Failed->value,
            ])
            ->where('attempts', '<', TelegramOutboundEffectService::STOCK_NOTIFICATION_MAX_ATTEMPTS)
            ->where(function ($error): void {
                $error->whereNull('last_error_code')
                    ->orWhereNotIn('last_error_code', [
                        'telegram_http_400',
                        'telegram_http_403',
                        'stock_notification_attempts_exhausted',
                        'stock_notification_terminal_failure',
                    ]);
            })
            ->where(function ($due): void {
                $due->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->exists();
    }

    private function materializeRecipientEffect(
        InventoryMovement $movement,
        StockNotificationRecipient $recipient,
    ): ?TelegramOutboundEffect {
        $effectKey = TelegramOutboundEffectService::stockNotificationEffectKey(
            (string) $movement->getKey(),
            (string) $recipient->getKey(),
        );
        $existing = TelegramOutboundEffect::query()->where('effect_key', $effectKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        // A legacy worker may still own this row. Let its lease finish before
        // creating a second delivery unit; an expired legacy claim is evidence
        // of an unknown outcome and is never silently retried.
        if ($recipient->sent_at === null
            && $recipient->claim_expires_at !== null
            && $recipient->claim_expires_at->isFuture()) {
            return null;
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
        } elseif ($recipient->claim_token !== null || $recipient->claim_expires_at !== null) {
            $state = TelegramOutboundEffectState::Unknown->value;
            $unknownAt = $recipient->claim_expires_at ?? now();
            $nextAttemptAt = null;
            $lastErrorCode = 'legacy_stock_notification_claim_ambiguous';
            $lastError = 'Legacy stock notification delivery outcome is unknown and requires review.';
        } elseif ($recipient->terminal_failed_at !== null) {
            $state = TelegramOutboundEffectState::Failed->value;
            $failedAt = $recipient->failed_at ?? $recipient->terminal_failed_at;
            $nextAttemptAt = null;
            $lastErrorCode = $this->legacyFailureCode($recipient->last_error, true);
            $lastError = 'Legacy stock notification delivery reached a terminal failure.';
        } elseif ($recipient->failed_at !== null) {
            $state = TelegramOutboundEffectState::Failed->value;
            $failedAt = $recipient->failed_at;
            $nextAttemptAt = (int) $recipient->attempts >= self::MAX_ATTEMPTS
                ? null
                : $recipient->failed_at->copy()->addSeconds(30);
            $lastErrorCode = (int) $recipient->attempts >= self::MAX_ATTEMPTS
                ? 'stock_notification_attempts_exhausted'
                : $this->legacyFailureCode($recipient->last_error, false);
            $lastError = 'Legacy stock notification delivery failed before completion.';
        }

        return $this->outboundEffects()->recordStockNotificationRecipient(
            recipient: $recipient,
            movementId: $movement->getKey(),
            state: $state,
            sentAt: $sentAt,
            failedAt: $failedAt,
            unknownAt: $unknownAt,
            nextAttemptAt: $nextAttemptAt,
            lastErrorCode: $lastErrorCode,
            lastError: $lastError,
        );
    }

    private function deliverEffect(TelegramOutboundEffect $effect): string
    {
        $claimed = $this->outboundEffects()->claim((string) $effect->getKey());
        if ($claimed === null) {
            return 'skipped';
        }

        try {
            $recipient = StockNotificationRecipient::query()->find($claimed->source_id);
            if ($recipient === null) {
                throw new \RuntimeException('stock_notification_recipient_missing');
            }

            $response = $this->bot->sendMessage(
                $claimed->telegram_chat_id,
                (string) $recipient->text,
                $recipient->reply_markup ?? [],
            );
            $messageId = data_get($response, 'result.message_id');
            if (data_get($response, 'ok') !== true || ! is_numeric($messageId)) {
                throw new \RuntimeException('Telegram stock notification did not return a successful message ID.');
            }

            return $this->outboundEffects()->finalizeSent(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                (string) $messageId,
            ) ? 'sent' : 'skipped';
        } catch (Throwable $exception) {
            if ($this->isStockNotificationAmbiguous($exception)) {
                $finalized = $this->outboundEffects()->finalizeUnknown(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    $exception,
                );
                $this->logEffectFailure($claimed, $exception, 'unknown');

                return $finalized ? 'unknown' : 'skipped';
            }

            $finalized = $this->outboundEffects()->finalizeFailed(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                $exception,
                self::RETRY_DELAY_SECONDS,
            );
            if (! $finalized) {
                return 'skipped';
            }

            $fresh = TelegramOutboundEffect::query()->find($claimed->getKey());
            $terminal = $fresh !== null && $this->outboundEffects()->isTerminalFailure($fresh);
            $this->logEffectFailure($claimed, $exception, $terminal ? 'terminal_failure' : 'transient_failure');

            return $terminal ? 'terminal_failure' : 'transient_failure';
        }
    }

    private function syncMovementProjection(int $movementId, int $eventId): void
    {
        $recipientCount = StockNotificationRecipient::query()
            ->where('stock_notification_event_id', $eventId)
            ->count();
        $effectQuery = TelegramOutboundEffect::query()
            ->where('source_type', StockNotificationRecipient::class)
            ->where('effect_type', TelegramOutboundEffectService::TYPE_STOCK_NOTIFICATION)
            ->whereIn('source_id', StockNotificationRecipient::query()
                ->where('stock_notification_event_id', $eventId)
                ->selectRaw('CAST(id AS TEXT)'));
        $effectCount = (clone $effectQuery)->count();
        $resolvedCount = (clone $effectQuery)
            ->where(function ($resolved): void {
                $resolved
                    ->where('state', TelegramOutboundEffectState::Sent->value)
                    ->orWhere(function ($failed): void {
                        $failed
                            ->where('state', TelegramOutboundEffectState::Failed->value)
                            ->where(function ($terminal): void {
                                $terminal
                                    ->whereIn('last_error_code', [
                                        'telegram_http_400',
                                        'telegram_http_403',
                                        'stock_notification_attempts_exhausted',
                                        'stock_notification_terminal_failure',
                                    ])
                                    ->orWhere('attempts', '>=', self::MAX_ATTEMPTS);
                            });
                    });
            })
            ->count();

        if ($effectCount === $recipientCount && $resolvedCount === $recipientCount) {
            InventoryMovement::query()
                ->whereKey($movementId)
                ->whereNull('stock_notification_sent_at')
                ->update(['stock_notification_sent_at' => now()]);
        }
    }

    private function isStockNotificationAmbiguous(Throwable $exception): bool
    {
        if ($exception instanceof RequestException
            && in_array($exception->response?->status(), [400, 403, 429], true)) {
            return false;
        }

        return $this->outboundEffects()->isAmbiguous($exception);
    }

    private function logEffectFailure(TelegramOutboundEffect $effect, Throwable $exception, string $status): void
    {
        Log::warning('telegram.stock_notification_effect_failed', [
            'effect_id' => $effect->id,
            'effect_type' => $effect->effect_type,
            'recipient_correlation_id' => DogfoodTiming::correlation($effect->telegram_chat_id),
            'attempts' => $effect->attempts,
            'status' => $status,
            'http_status' => $exception instanceof RequestException ? $exception->response?->status() : null,
            'exception' => $exception::class,
        ]);
    }

    private function legacyFailureCode(?string $error, bool $terminal): string
    {
        $error = strtolower((string) $error);
        if (str_contains($error, '403')) {
            return 'telegram_http_403';
        }
        if (str_contains($error, '400')) {
            return 'telegram_http_400';
        }

        return $terminal ? 'stock_notification_terminal_failure' : 'legacy_stock_notification_failure';
    }

    private function outboundEffects(): TelegramOutboundEffectService
    {
        return $this->outboundEffects ??= app(TelegramOutboundEffectService::class);
    }

    private function isNotifiable(InventoryMovement $movement): bool
    {
        return $movement->type === InventoryMovementType::Adjustment
            ? $movement->quantity > 0 && $movement->available_before === 0 && $movement->product !== null
            : $movement->type === InventoryMovementType::Sale && $movement->quantity < 0 && $movement->became_unavailable && $movement->product !== null;
    }
}
