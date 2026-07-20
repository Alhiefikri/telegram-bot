<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TelegramBuyerCheckoutInstructionCleaner
{
    public function __construct(private readonly TelegramBotClient $bot) {}

    public function deleteBinanceCheckoutInstruction(Order $order): void
    {
        $token = (string) Str::uuid();
        $target = $this->claimDeletion($order, $token);
        if ($target === null) {
            return;
        }

        try {
            $this->bot->deleteMessage(
                $target->manual_checkout_customer_notification_chat_id,
                $target->manual_checkout_customer_notification_message_id,
            );
        } catch (RequestException $e) {
            if (! $this->isAlreadyDeletedTelegramMessage($e)) {
                $this->releaseDeletionClaim($target, $token);

                throw $e;
            }
        }

        $this->markDeleted($target, $token);
    }

    private function claimDeletion(Order $order, string $token): ?Order
    {
        return DB::transaction(function () use ($order, $token): ?Order {
            $target = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->first([
                    'id',
                    'order_status',
                    'manual_checkout_customer_notification_chat_id',
                    'manual_checkout_customer_notification_message_id',
                    'manual_checkout_customer_notification_deleted_at',
                    'manual_checkout_customer_notification_deletion_started_at',
                ]);

            if (! $target
                || ! in_array($target->order_status, [OrderStatus::Completed, OrderStatus::Cancelled], true)
                || ! $target->manual_checkout_customer_notification_chat_id
                || ! $target->manual_checkout_customer_notification_message_id
                || $target->manual_checkout_customer_notification_deleted_at !== null
                || $this->hasActiveDeletionClaim($target->manual_checkout_customer_notification_deletion_started_at)
                || ! Payment::query()->where('order_id', $target->id)->where('method', 'BINANCE_PAY_ID')->exists()) {
                return null;
            }

            $target->forceFill([
                'manual_checkout_customer_notification_deletion_started_at' => now(),
                'manual_checkout_customer_notification_deletion_token' => $token,
            ])->save();

            return $target;
        }, 5);
    }

    private function markDeleted(Order $target, string $token): void
    {
        Order::query()
            ->whereKey($target->id)
            ->where('manual_checkout_customer_notification_message_id', $target->manual_checkout_customer_notification_message_id)
            ->where('manual_checkout_customer_notification_deletion_token', $token)
            ->whereNull('manual_checkout_customer_notification_deleted_at')
            ->update([
                'manual_checkout_customer_notification_deleted_at' => now(),
                'manual_checkout_customer_notification_deletion_started_at' => null,
                'manual_checkout_customer_notification_deletion_token' => null,
            ]);
    }

    private function releaseDeletionClaim(Order $target, string $token): void
    {
        Order::query()
            ->whereKey($target->id)
            ->where('manual_checkout_customer_notification_message_id', $target->manual_checkout_customer_notification_message_id)
            ->where('manual_checkout_customer_notification_deletion_token', $token)
            ->whereNull('manual_checkout_customer_notification_deleted_at')
            ->update([
                'manual_checkout_customer_notification_deletion_started_at' => null,
                'manual_checkout_customer_notification_deletion_token' => null,
            ]);
    }

    private function hasActiveDeletionClaim($startedAt): bool
    {
        return $startedAt !== null
            && $startedAt->gt(now()->subSeconds(max(60, (int) config('services.telegram.claim_lease_seconds', 600))));
    }

    private function isAlreadyDeletedTelegramMessage(RequestException $e): bool
    {
        if ($e->response?->status() !== 400) {
            return false;
        }

        return str_contains(
            Str::lower((string) data_get($e->response->json(), 'description')),
            'message to delete not found',
        );
    }
}
