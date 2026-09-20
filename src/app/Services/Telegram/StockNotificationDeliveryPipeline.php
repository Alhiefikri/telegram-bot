<?php

namespace App\Services\Telegram;

use App\Enums\InventoryMovementType;
use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Jobs\SendProductStockNotifications;
use App\Models\InventoryMovement;
use App\Models\StockNotificationEvent;
use App\Models\StockNotificationRecipient;
use App\Models\TelegramOutboundEffect;
use App\Services\Pricing\ProductPriceTierQuote;
use App\Services\Admin\ProductImageOptimizer;
use App\Support\DogfoodTiming;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class StockNotificationDeliveryPipeline
{
    public const MAX_ATTEMPTS = TelegramOutboundEffectPolicy::STOCK_NOTIFICATION_MAX_ATTEMPTS;

    public const QUEUE = TelegramOutboundEffectService::STOCK_NOTIFICATION_QUEUE;

    public const JOB_BATCH_SIZE = 1;

    private const RECIPIENT_MATERIALIZATION_BATCH_SIZE = 100;

    public function __construct(
        private TelegramProductNotificationService $notifications,
        private TelegramBotClient $bot,
        private ProductImageOptimizer $images,
        private ?TelegramOutboundEffectService $outboundEffects = null,
        private ?TelegramOutboundEffectLedgerProtocol $ledger = null,
        private ?TelegramOutboundResponseValidator $responseValidator = null,
    ) {}

    public function register(int $movementId): ?StockNotificationEvent
    {
        return DB::transaction(function () use ($movementId): ?StockNotificationEvent {
            $movement = InventoryMovement::query()
                ->with('product')
                ->whereKey($movementId)
                ->lockForUpdate()
                ->first();
            if (! $movement || ! $this->isNotifiable($movement)) {
                return null;
            }

            return StockNotificationEvent::query()->firstOrCreate(
                ['inventory_movement_id' => $movement->id],
                ['kind' => $movement->type->value],
            );
        }, 5);
    }

    public function materialize(int $movementId): ?StockNotificationEvent
    {
        do {
            $event = $this->snapshotEvent($movementId);
            if (! $event) {
                return null;
            }
        } while ($event->recipients_snapshotted_at === null);

        while ($this->materializeRecipientEffects($movementId, (int) $event->id, 200) > 0) {
            // Continue until compatibility callers have the complete ledger snapshot.
        }

        return $event->fresh() ?? $event;
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

        if ($event->recipients_snapshotted_at === null) {
            DogfoodTiming::log('dogfood.telegram.broadcast_finished', [
                'sent' => 0,
                'failed' => 0,
                'skipped' => false,
                'materializing' => true,
                'recipient_count' => 0,
                'telegram_total_duration_ms' => 0.0,
                'per_recipient_telegram' => [],
                'duration_ms' => DogfoodTiming::elapsedMs($startedAt),
            ]);

            return ['processed' => 0, 'transient_failures' => 0, 'has_more' => true];
        }

        $this->materializeRecipientEffects($movementId, (int) $event->id, max(1, $batchSize));
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
        $this->syncStockNotificationProjection($movementId);

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
        $limit = max(1, $chunk);
        $ledgerRecipientIds = $this->ledger()->readyToDispatchQuery()
            ->where('source_type', StockNotificationRecipient::class)
            ->where('effect_type', TelegramOutboundEffectType::StockNotificationRecipient->value)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('source_id');
        $recipientMovementIds = StockNotificationRecipient::query()
            ->whereIn('id', $ledgerRecipientIds)
            ->with('event:id,inventory_movement_id')
            ->get()
            ->pluck('event.inventory_movement_id')->filter()->unique()->values();
        $unmaterializedMovementIds = StockNotificationRecipient::query()
            ->whereNull('sent_at')
            ->whereNull('terminal_failed_at')
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->whereNotExists(function ($effects): void {
                $effects
                    ->selectRaw('1')
                    ->from('telegram_outbound_effects')
                    ->where('telegram_outbound_effects.source_type', StockNotificationRecipient::class)
                    ->where('telegram_outbound_effects.effect_type', TelegramOutboundEffectType::StockNotificationRecipient->value)
                    ->whereRaw('telegram_outbound_effects.source_id = CAST(stock_notification_recipients.id AS TEXT)');
            })
            ->with('event:id,inventory_movement_id')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->pluck('event.inventory_movement_id')->filter()->unique()->values();
        $unmaterializedEventMovementIds = StockNotificationEvent::query()
            ->whereNull('recipients_snapshotted_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('inventory_movement_id');

        $movementIds = $recipientMovementIds
            ->merge($unmaterializedMovementIds)
            ->merge($unmaterializedEventMovementIds)
            ->unique()
            ->values();

        foreach ($movementIds as $movementId) {
            SendProductStockNotifications::dispatch($movementId)->onQueue(self::QUEUE);
        }

        return $movementIds->count();
    }

    public function refreshAggregateProjection(int|string $movementId): void
    {
        $this->syncStockNotificationProjection($movementId);
    }

    /**
     * Synchronize a lease-recovered unknown effect without redispatching it.
     */
    public function synchronizeRecoveredUnknown(TelegramOutboundEffect $effect): void
    {
        if ($effect->source_type !== StockNotificationRecipient::class
            || $effect->effect_type !== TelegramOutboundEffectType::StockNotificationRecipient->value) {
            return;
        }

        DB::transaction(function () use ($effect): void {
            $this->synchronizeUnknownProjection($effect);
            $movementId = StockNotificationRecipient::query()
                ->whereKey($effect->source_id)
                ->join('stock_notification_events', 'stock_notification_events.id', '=', 'stock_notification_recipients.stock_notification_event_id')
                ->value('stock_notification_events.inventory_movement_id');
            if ($movementId !== null) {
                $this->syncStockNotificationProjection((int) $movementId);
            }
        }, 5);
    }

    private function snapshotEvent(int $movementId): ?StockNotificationEvent
    {
        return DB::transaction(function () use ($movementId): ?StockNotificationEvent {
            $movement = InventoryMovement::query()->with('product')->whereKey($movementId)->lockForUpdate()->first();
            if (! $movement || ! $this->isNotifiable($movement)) {
                return null;
            }

            $event = StockNotificationEvent::query()->firstOrCreate(
                ['inventory_movement_id' => $movement->id],
                ['kind' => $movement->type->value],
            );
            if ($event->recipients_snapshotted_at === null) {
                if ($movement->type === InventoryMovementType::Adjustment) {
                    $promotionSnapshotResolved = $event->tier_promotion_snapshotted_at !== null;
                    $promotedTier = $promotionSnapshotResolved
                        ? $this->promotedTierFromSnapshot($event)
                        : $this->notifications->tierPromotionForStock(
                            $movement->product,
                            $movement->available_before,
                            (int) $movement->available_after,
                        );
                    if (! $promotionSnapshotResolved) {
                        $event->forceFill([
                            'tier_promotion_snapshotted_at' => now(),
                            'promoted_tier_minimum' => $promotedTier?->minimumQuantity,
                            'promoted_tier_price_amount' => $promotedTier?->priceAmount,
                            'promoted_tier_usdt_price_amount' => $promotedTier?->usdtPriceAmount,
                        ])->save();
                    }

                    $batch = $this->notifications->stockAddedDeliveryBatch(
                        $movement->product,
                        $movement->quantity,
                        $movement->available_before,
                        $movement->available_after,
                        (int) ($event->materialization_cursor ?? 0),
                        self::RECIPIENT_MATERIALIZATION_BATCH_SIZE,
                        $promotedTier,
                        true,
                    );
                    $deliveries = $batch['deliveries'];
                    $event->forceFill([
                        'materialization_cursor' => $batch['next_cursor'],
                        'recipients_snapshotted_at' => $batch['has_more'] ? null : now(),
                    ])->save();
                } else {
                    $deliveries = $this->notifications->productSoldOutDeliveries($movement->product);
                    $event->forceFill(['recipients_snapshotted_at' => now()])->save();
                }

                $now = now();
                $rows = array_map(fn (array $delivery): array => [
                    'stock_notification_event_id' => $event->id,
                    'customer_id' => $delivery['customer_id'],
                    'recipient_type' => $delivery['recipient_type'],
                    'telegram_id' => $delivery['telegram_id'],
                    'locale' => $delivery['locale'],
                    'text' => $delivery['text'],
                    'reply_markup' => $delivery['reply_markup'] === [] ? null : json_encode($delivery['reply_markup'], JSON_THROW_ON_ERROR),
                    'photo_path' => $delivery['photo_path'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $deliveries);
                if ($rows !== []) {
                    StockNotificationRecipient::query()->insertOrIgnore($rows);
                }
            }

            return $event->fresh() ?? $event;
        }, 5);
    }

    private function promotedTierFromSnapshot(StockNotificationEvent $event): ?ProductPriceTierQuote
    {
        if ($event->promoted_tier_minimum === null || $event->promoted_tier_price_amount === null) {
            return null;
        }

        return new ProductPriceTierQuote(
            (int) $event->promoted_tier_minimum,
            (int) $event->promoted_tier_price_amount,
            $event->promoted_tier_usdt_price_amount === null
                ? null
                : (int) $event->promoted_tier_usdt_price_amount,
        );
    }

    private function materializeRecipientEffects(int $movementId, int $eventId, int $limit): int
    {
        return DB::transaction(function () use ($movementId, $eventId, $limit): int {
            $movement = InventoryMovement::query()->whereKey($movementId)->first();
            if ($movement === null) {
                return 0;
            }

            $recipients = StockNotificationRecipient::query()
                ->where('stock_notification_event_id', $eventId)
                ->whereNotExists(function ($effects): void {
                    $effects
                        ->selectRaw('1')
                        ->from('telegram_outbound_effects')
                        ->where('telegram_outbound_effects.source_type', StockNotificationRecipient::class)
                        ->where('telegram_outbound_effects.effect_type', TelegramOutboundEffectType::StockNotificationRecipient->value)
                        ->whereRaw('telegram_outbound_effects.source_id = CAST(stock_notification_recipients.id AS TEXT)');
                })
                ->orderBy('id')
                ->limit(max(1, $limit))
                ->get();

            foreach ($recipients as $recipient) {
                $this->materializeRecipientEffect($movement, $recipient);
            }

            return $recipients->count();
        }, 5);
    }

    private function dueEffects(int $eventId, int $batchSize)
    {
        return $this->ledger()->readyToDispatchQuery()
            ->where('source_type', StockNotificationRecipient::class)
            ->where('effect_type', TelegramOutboundEffectType::StockNotificationRecipient->value)
            ->whereIn('source_id', StockNotificationRecipient::query()
                ->where('stock_notification_event_id', $eventId)
                ->selectRaw('CAST(id AS TEXT)'))
            ->orderByRaw('CAST(source_id AS BIGINT)')
            ->limit(max(1, $batchSize))
            ->get();
    }

    private function hasPendingEffects(int $eventId): bool
    {
        $ready = $this->ledger()->readyToDispatchQuery()
            ->where('source_type', StockNotificationRecipient::class)
            ->where('effect_type', TelegramOutboundEffectType::StockNotificationRecipient->value)
            ->whereIn('source_id', StockNotificationRecipient::query()
                ->where('stock_notification_event_id', $eventId)
                ->selectRaw('CAST(id AS TEXT)'))
            ->exists();
        if ($ready) {
            return true;
        }

        return StockNotificationRecipient::query()
            ->where('stock_notification_event_id', $eventId)
            ->whereNotExists(function ($effects): void {
                $effects
                    ->selectRaw('1')
                    ->from('telegram_outbound_effects')
                    ->where('telegram_outbound_effects.source_type', StockNotificationRecipient::class)
                    ->where('telegram_outbound_effects.effect_type', TelegramOutboundEffectType::StockNotificationRecipient->value)
                    ->whereRaw('telegram_outbound_effects.source_id = CAST(stock_notification_recipients.id AS TEXT)');
            })
            ->exists();
    }

    private function materializeRecipientEffect(
        InventoryMovement $movement,
        StockNotificationRecipient $recipient,
    ): ?TelegramOutboundEffect {
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
        $claimed = $this->ledger()->claim((string) $effect->getKey());
        if ($claimed === null) {
            return 'skipped';
        }

        try {
            $recipient = StockNotificationRecipient::query()->find($claimed->source_id);
            if ($recipient === null) {
                throw new \RuntimeException('stock_notification_recipient_missing');
            }

            $photo = mb_strlen((string) $recipient->text, 'UTF-8') <= 1024
                ? $this->images->telegramPhoto($recipient->photo_path)
                : null;
            $response = $photo !== null
                ? $this->bot->sendPhoto(
                    $claimed->telegram_chat_id,
                    $photo['contents'],
                    $photo['filename'],
                    (string) $recipient->text,
                    $recipient->reply_markup ?? [],
                )
                : $this->bot->sendMessage(
                    $claimed->telegram_chat_id,
                    (string) $recipient->text,
                    $recipient->reply_markup ?? [],
                );
            $messageId = $this->responseValidator()->messageIdFromResponse($response, 'Telegram stock notification');
            $finalized = $this->ledger()->finalizeSent(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                (string) $messageId,
            );
            if ($finalized === null) {
                return 'skipped';
            }

            $this->synchronizeSentProjection($finalized);

            return 'sent';
        } catch (Throwable $exception) {
            if ($this->ledger()->isAmbiguous($exception)) {
                $finalized = $this->ledger()->finalizeUnknown(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    $exception,
                );
                if ($finalized !== null) {
                    $this->synchronizeUnknownProjection($finalized);
                }
                $this->logEffectFailure($claimed, $exception, 'unknown');

                return $finalized ? 'unknown' : 'skipped';
            }

            $finalized = $this->ledger()->finalizeFailed(
                (string) $claimed->getKey(),
                (string) $claimed->claim_token,
                $exception,
            );
            if (! $finalized) {
                return 'skipped';
            }

            $this->synchronizeFailedProjection($finalized, $exception);

            $fresh = TelegramOutboundEffect::query()->find($claimed->getKey());
            $terminal = $fresh !== null && $this->ledger()->isTerminalFailure($fresh);
            $this->logEffectFailure($claimed, $exception, $terminal ? 'terminal_failure' : 'transient_failure');

            return $terminal ? 'terminal_failure' : 'transient_failure';
        }
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

    private function synchronizeSentProjection(TelegramOutboundEffect $effect): void
    {
        StockNotificationRecipient::query()
            ->whereKey($effect->source_id)
            ->whereNull('sent_at')
            ->update([
                'sent_at' => $effect->sent_at ?? now(),
                'failed_at' => null,
                'terminal_failed_at' => null,
                'last_error' => null,
                'attempts' => (int) $effect->attempts,
                'updated_at' => now(),
            ]);
    }

    private function synchronizeUnknownProjection(TelegramOutboundEffect $effect): void
    {
        StockNotificationRecipient::query()
            ->whereKey($effect->source_id)
            ->whereNull('sent_at')
            ->update([
                'failed_at' => null,
                'terminal_failed_at' => null,
                'attempts' => (int) $effect->attempts,
                'last_error' => mb_substr($effect->last_error ?: 'Stock notification delivery outcome is unknown and requires review.', 0, 2000),
                'updated_at' => $effect->unknown_at ?? now(),
            ]);
    }

    private function synchronizeFailedProjection(TelegramOutboundEffect $effect, Throwable $exception): void
    {
        $terminal = $this->ledger()->isTerminalFailure($effect);
        StockNotificationRecipient::query()
            ->whereKey($effect->source_id)
            ->whereNull('sent_at')
            ->update([
                'failed_at' => $effect->failed_at ?? now(),
                'terminal_failed_at' => $terminal ? ($effect->failed_at ?? now()) : null,
                'last_error' => $this->projectionError($exception),
                'attempts' => (int) $effect->attempts,
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

    private function ledger(): TelegramOutboundEffectLedgerProtocol
    {
        return $this->ledger ??= app(TelegramOutboundEffectLedgerProtocol::class);
    }

    private function syncStockNotificationProjection(int|string $movementId): void
    {
        if (StockNotificationEvent::query()
            ->where('inventory_movement_id', $movementId)
            ->whereNull('recipients_snapshotted_at')
            ->exists()) {
            return;
        }
        $recipientIds = StockNotificationRecipient::query()
            ->whereHas('event', fn (Builder $event) => $event->where('inventory_movement_id', $movementId))
            ->selectRaw('CAST(id AS TEXT)');
        $recipientCount = StockNotificationRecipient::query()
            ->whereHas('event', fn (Builder $event) => $event->where('inventory_movement_id', $movementId))
            ->count();
        $effects = TelegramOutboundEffect::query()
            ->where('source_type', StockNotificationRecipient::class)
            ->where('effect_type', TelegramOutboundEffectType::StockNotificationRecipient->value)
            ->whereIn('source_id', $recipientIds);
        $effectCount = (clone $effects)->count();
        $resolvedCount = (clone $effects)
            ->where(function (Builder $resolved): void {
                $resolved
                    ->where('state', TelegramOutboundEffectState::Sent->value)
                    ->orWhere(function (Builder $terminal): void {
                        $terminal
                            ->where('state', TelegramOutboundEffectState::Failed->value)
                            ->where(function (Builder $attempts): void {
                                $attempts
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

    private function responseValidator(): TelegramOutboundResponseValidator
    {
        return $this->responseValidator ??= app(TelegramOutboundResponseValidator::class);
    }

    private function isNotifiable(InventoryMovement $movement): bool
    {
        return $movement->type === InventoryMovementType::Adjustment
            ? $movement->quantity > 0 && $movement->product !== null
            : $movement->type === InventoryMovementType::Sale && $movement->quantity < 0 && $movement->became_unavailable && $movement->product !== null;
    }
}
