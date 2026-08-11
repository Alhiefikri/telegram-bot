<?php

namespace App\Services\Telegram;

use App\Enums\InventoryMovementType;
use App\Jobs\SendProductStockNotifications;
use App\Models\InventoryMovement;
use App\Models\StockNotificationEvent;
use App\Models\StockNotificationRecipient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class StockNotificationDeliveryPipeline
{
    private const CLAIM_TTL_MINUTES = 15;

    private const MAX_ATTEMPTS = 5;

    public function __construct(private TelegramProductNotificationService $notifications, private TelegramBotClient $bot) {}

    /** @return array{processed:int, transient_failures:int, has_more:bool} */
    public function deliver(int $movementId, int $batchSize = 100): array
    {
        $event = $this->snapshotEvent($movementId);
        if (! $event) {
            return ['processed' => 0, 'transient_failures' => 0, 'has_more' => false];
        }

        $recipients = StockNotificationRecipient::query()
            ->where('stock_notification_event_id', $event->id)
            ->whereNull('sent_at')->whereNull('terminal_failed_at')
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where(fn ($query) => $query->whereNull('claim_expires_at')->orWhere('claim_expires_at', '<=', now()))
            ->orderBy('id')->limit(max(1, $batchSize))->get();

        $processed = 0;
        $transientFailures = 0;
        foreach ($recipients as $recipient) {
            $outcome = $this->deliverRecipient($recipient);
            $processed += $outcome === 'skipped' ? 0 : 1;
            $transientFailures += $outcome === 'transient_failure' ? 1 : 0;
        }

        $hasMore = StockNotificationRecipient::query()->where('stock_notification_event_id', $event->id)
            ->whereNull('sent_at')->whereNull('terminal_failed_at')->where('attempts', '<', self::MAX_ATTEMPTS)->exists();
        if (! $hasMore) {
            InventoryMovement::query()->whereKey($movementId)->update(['stock_notification_sent_at' => now()]);
        }

        return [
            'processed' => $processed,
            'transient_failures' => $transientFailures,
            'has_more' => $hasMore,
        ];
    }

    public function reconcile(int $chunk = 100): int
    {
        $recipientMovementIds = StockNotificationRecipient::query()
            ->whereNull('sent_at')->whereNull('terminal_failed_at')->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where(fn ($query) => $query->whereNull('claim_expires_at')->orWhere('claim_expires_at', '<=', now()))
            ->with('event:id,inventory_movement_id')->orderBy('id')->limit(max(1, $chunk))->get()
            ->pluck('event.inventory_movement_id')->filter()->unique()->values();

        // The old one-shot worker recorded a permanent started marker before it
        // fanned out. Treat those in-flight rows as unmaterialized events so a
        // deploy cannot leave them stranded forever.
        $legacyMovementIds = InventoryMovement::query()
            ->whereNotNull('stock_notification_started_at')->whereNull('stock_notification_sent_at')
            ->orderBy('id')->limit(max(1, $chunk))->pluck('id');
        $movementIds = $recipientMovementIds->merge($legacyMovementIds)->unique()->values();

        foreach ($movementIds as $movementId) {
            SendProductStockNotifications::dispatch($movementId)->onQueue('notifications');
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
            if ($event->recipients_snapshotted_at !== null) {
                return $event;
            }

            $deliveries = $movement->type === InventoryMovementType::Adjustment
                ? $this->notifications->stockAddedDeliveries($movement->product, $movement->quantity, $movement->available_before)
                : $this->notifications->productSoldOutDeliveries($movement->product);
            $now = now();
            $rows = array_map(fn (array $delivery): array => [
                'stock_notification_event_id' => $event->id,
                'customer_id' => $delivery['customer_id'],
                'recipient_type' => $delivery['recipient_type'],
                'telegram_id' => $delivery['telegram_id'],
                'text' => $delivery['text'],
                'reply_markup' => $delivery['reply_markup'] === [] ? null : json_encode($delivery['reply_markup'], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ], $deliveries);
            if ($rows !== []) {
                StockNotificationRecipient::query()->insertOrIgnore($rows);
            }
            $event->forceFill(['recipients_snapshotted_at' => $now])->save();

            return $event;
        }, 5);
    }

    private function deliverRecipient(StockNotificationRecipient $recipient): string
    {
        $token = (string) Str::uuid();
        $claimed = DB::transaction(function () use ($recipient, $token): ?StockNotificationRecipient {
            $locked = StockNotificationRecipient::query()->whereKey($recipient->id)->lockForUpdate()->first();
            if (! $locked || $locked->sent_at || $locked->terminal_failed_at || $locked->attempts >= self::MAX_ATTEMPTS || ($locked->claim_expires_at && $locked->claim_expires_at->isFuture())) {
                return null;
            }
            $locked->forceFill(['claim_token' => $token, 'claim_expires_at' => now()->addMinutes(self::CLAIM_TTL_MINUTES), 'attempts' => $locked->attempts + 1])->save();

            return $locked->fresh();
        }, 5);
        if (! $claimed) {
            return 'skipped';
        }

        try {
            $this->bot->sendMessage($claimed->telegram_id, $claimed->text, $claimed->reply_markup ?? []);
        } catch (Throwable $e) {
            $terminal = $this->isPermanent($e) || $claimed->attempts >= self::MAX_ATTEMPTS;
            StockNotificationRecipient::query()->whereKey($claimed->id)->where('claim_token', $token)->whereNull('sent_at')->update([
                'claim_token' => null, 'claim_expires_at' => null, 'failed_at' => now(), 'terminal_failed_at' => $terminal ? now() : null,
                'last_error' => substr(TelegramBotApiRedactor::redactText($e::class.': '.$e->getMessage()), 0, 2000),
            ]);
            Log::warning('telegram.stock_notification_recipient_failed', ['recipient_id' => $claimed->id, 'customer_id' => $claimed->customer_id, 'terminal' => $terminal, 'attempts' => $claimed->attempts, 'exception' => $e::class]);

            return $terminal ? 'terminal_failure' : 'transient_failure';
        }

        StockNotificationRecipient::query()->whereKey($claimed->id)->where('claim_token', $token)->whereNull('sent_at')->update([
            'sent_at' => now(), 'claim_token' => null, 'claim_expires_at' => null, 'failed_at' => null, 'last_error' => null,
        ]);

        return 'sent';
    }

    private function isNotifiable(InventoryMovement $movement): bool
    {
        return $movement->type === InventoryMovementType::Adjustment
            ? $movement->quantity > 0 && $movement->available_before === 0 && $movement->product !== null
            : $movement->type === InventoryMovementType::Sale && $movement->quantity < 0 && $movement->became_unavailable && $movement->product !== null;
    }

    private function isPermanent(Throwable $e): bool
    {
        return $e instanceof RequestException && in_array($e->response?->status(), [400, 403], true);
    }
}
