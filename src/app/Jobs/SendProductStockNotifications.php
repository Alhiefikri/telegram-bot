<?php

namespace App\Jobs;

use App\Enums\InventoryMovementType;
use App\Models\InventoryMovement;
use App\Services\Telegram\TelegramProductNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

class SendProductStockNotifications implements ShouldQueue
{
    use Queueable;

    // Telegram does not provide an idempotency key for sendMessage. A retry after
    // the API accepted the request could therefore send the same broadcast again.
    public int $tries = 1;

    public function __construct(public readonly int $movementId) {}

    public function handle(TelegramProductNotificationService $notifications): void
    {
        $movement = InventoryMovement::query()->with('product')->find($this->movementId);

        if ($movement === null || $movement->type !== InventoryMovementType::Adjustment || $movement->quantity < 1) {
            return;
        }

        $token = (string) Str::uuid();

        $claimed = DB::transaction(function () use ($movement, $token): ?InventoryMovement {
            $locked = InventoryMovement::query()->whereKey($movement->id)->lockForUpdate()->first();

            if (! $locked || $locked->stock_notification_sent_at !== null) {
                return null;
            }

            // This is a permanent event claim, not a lease. Releasing it after
            // a timeout would allow Telegram to receive the same broadcast again.
            if ($locked->stock_notification_started_at !== null) {
                return null;
            }

            $locked->forceFill([
                'stock_notification_started_at' => now(),
                'stock_notification_claim_token' => $token,
            ])->save();

            return $locked->fresh(['product']);
        }, 5);

        if (! $claimed || $claimed->product === null || $claimed->available_before !== 0) {
            return;
        }

        $notifications->notifyStockAdded($claimed->product, $claimed->quantity, $claimed->available_before);

        InventoryMovement::query()
            ->whereKey($claimed->id)
            ->where('stock_notification_claim_token', $token)
            ->whereNull('stock_notification_sent_at')
            ->update(['stock_notification_sent_at' => now()]);
    }
}
