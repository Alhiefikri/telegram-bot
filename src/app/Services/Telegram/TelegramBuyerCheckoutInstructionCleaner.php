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
        $this->deleteTarget($order, 'manual_checkout_customer_notification', false);
    }

    public function deleteTrackedMessages(Order $order): void
    {
        $current = $order->fresh(['payments']);
        if (! $current
            || ! in_array($current->order_status, [OrderStatus::Completed, OrderStatus::Cancelled], true)
            || ($current->order_status === OrderStatus::Completed && $current->telegram_receipt_sent_at === null)) {
            return;
        }

        $this->deleteOrderDetailMessage($current);
        $this->deleteTarget($current, 'manual_checkout_customer_notification', false);
    }

    private function deleteOrderDetailMessage(Order $order): void
    {
        $token = (string) Str::uuid();
        $target = DB::transaction(function () use ($order, $token): ?Order {
            $target = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if (! $target
                || ! $target->buyer_order_detail_message_chat_id
                || ! $target->buyer_order_detail_message_id
                || $target->buyer_order_detail_message_deleted_at !== null
                || $this->hasActiveDeletionClaim($target->buyer_order_detail_message_deletion_started_at)) {
                return null;
            }

            $target->forceFill([
                'buyer_order_detail_message_deletion_started_at' => now(),
                'buyer_order_detail_message_deletion_token' => $token,
            ])->save();

            return $target;
        }, 5);
        if ($target === null) {
            return;
        }

        try {
            $this->bot->deleteMessage($target->buyer_order_detail_message_chat_id, $target->buyer_order_detail_message_id);
        } catch (RequestException $e) {
            if (! $this->isAlreadyDeletedTelegramMessage($e)) {
                Order::query()
                    ->whereKey($target->id)
                    ->where('buyer_order_detail_message_id', $target->buyer_order_detail_message_id)
                    ->where('buyer_order_detail_message_deletion_token', $token)
                    ->update([
                        'buyer_order_detail_message_deletion_started_at' => null,
                        'buyer_order_detail_message_deletion_token' => null,
                    ]);

                throw $e;
            }
        }

        Order::query()
            ->whereKey($target->id)
            ->where('buyer_order_detail_message_id', $target->buyer_order_detail_message_id)
            ->where('buyer_order_detail_message_deletion_token', $token)
            ->update([
                'buyer_order_detail_message_deleted_at' => now(),
                'buyer_order_detail_message_deletion_started_at' => null,
                'buyer_order_detail_message_deletion_token' => null,
            ]);
    }

    private function deleteTarget(Order $order, string $prefix, bool $requireReceipt): void
    {
        $token = (string) Str::uuid();
        $target = $this->claimDeletion($order, $prefix, $token, $requireReceipt);
        if ($target === null) {
            return;
        }

        try {
            $this->bot->deleteMessage(
                $target->{$prefix.'_chat_id'},
                $target->{$prefix.'_message_id'},
            );
        } catch (RequestException $e) {
            if (! $this->isAlreadyDeletedTelegramMessage($e)) {
                $this->releaseDeletionClaim($target, $prefix, $token);

                throw $e;
            }
        }

        $this->markDeleted($target, $prefix, $token);
    }

    private function claimDeletion(Order $order, string $prefix, string $token, bool $requireReceipt): ?Order
    {
        return DB::transaction(function () use ($order, $prefix, $token, $requireReceipt): ?Order {
            $chatColumn = $prefix.'_chat_id';
            $messageColumn = $prefix.'_message_id';
            $deletedColumn = $prefix.'_deleted_at';
            $startedColumn = $prefix.'_deletion_started_at';
            $tokenColumn = $prefix.'_deletion_token';
            $target = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->first([
                    'id',
                    'order_status',
                    'telegram_receipt_sent_at',
                    $chatColumn,
                    $messageColumn,
                    $deletedColumn,
                    $startedColumn,
                ]);

            if (! $target
                || ! in_array($target->order_status, [OrderStatus::Completed, OrderStatus::Cancelled], true)
                || ($requireReceipt && $target->order_status === OrderStatus::Completed && $target->telegram_receipt_sent_at === null)
                || ! $target->{$chatColumn}
                || ! $target->{$messageColumn}
                || $target->{$deletedColumn} !== null
                || $this->hasActiveDeletionClaim($target->{$startedColumn})
                || ($prefix === 'manual_checkout_customer_notification' && ! Payment::query()
                    ->where('order_id', $target->id)
                    ->where(fn ($query) => $query
                        ->where(fn ($binance) => $binance->where('provider', 'manual')->where('method', 'BINANCE_PAY_ID'))
                        ->orWhere('provider', 'klikqris'))
                    ->exists())) {
                return null;
            }

            $target->forceFill([
                $startedColumn => now(),
                $tokenColumn => $token,
            ])->save();

            return $target;
        }, 5);
    }

    private function markDeleted(Order $target, string $prefix, string $token): void
    {
        $messageColumn = $prefix.'_message_id';
        $deletedColumn = $prefix.'_deleted_at';
        $startedColumn = $prefix.'_deletion_started_at';
        $tokenColumn = $prefix.'_deletion_token';
        Order::query()
            ->whereKey($target->id)
            ->where($messageColumn, $target->{$messageColumn})
            ->where($tokenColumn, $token)
            ->whereNull($deletedColumn)
            ->update([
                $deletedColumn => now(),
                $startedColumn => null,
                $tokenColumn => null,
            ]);
    }

    private function releaseDeletionClaim(Order $target, string $prefix, string $token): void
    {
        $messageColumn = $prefix.'_message_id';
        $deletedColumn = $prefix.'_deleted_at';
        $startedColumn = $prefix.'_deletion_started_at';
        $tokenColumn = $prefix.'_deletion_token';
        Order::query()
            ->whereKey($target->id)
            ->where($messageColumn, $target->{$messageColumn})
            ->where($tokenColumn, $token)
            ->whereNull($deletedColumn)
            ->update([
                $startedColumn => null,
                $tokenColumn => null,
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

        $description = Str::lower((string) data_get($e->response->json(), 'description'));

        return str_contains($description, 'message to delete not found')
            || str_contains($description, "message can't be deleted");
    }
}
