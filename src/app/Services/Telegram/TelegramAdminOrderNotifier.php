<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Support\DogfoodTiming;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramAdminOrderNotifier
{
    public function __construct(
        private readonly TelegramBotClient $bot,
        private readonly TelegramAdminAccess $adminAccess,
    ) {}

    public function sendPending(Order $order): void
    {
        $this->sendOnce(
            order: $order,
            startedAtColumn: 'admin_pending_order_notification_started_at',
            sentAtColumn: 'admin_pending_order_notified_at',
            step: 'admin_pending_order_notification',
            canSend: fn (Order $locked): bool => $locked->order_status === OrderStatus::PendingPayment
                && $locked->payment_status === PaymentStatus::Pending,
            message: fn (Order $claimed): string => $this->pendingMessage($claimed),
            sentUpdates: fn (array $response, Order $claimed, string $chatId): array => $this->pendingMessageTrackingUpdates($response, $chatId),
        );

        $this->deletePending($order);
    }

    public function sendPaid(Order $order): void
    {
        $this->deletePending($order);
    }

    public function sendExpired(Order $order): void
    {
        $this->sendOnce(
            order: $order,
            startedAtColumn: 'admin_expired_order_notification_started_at',
            sentAtColumn: 'admin_expired_order_notified_at',
            step: 'admin_expired_order_notification',
            canSend: fn (Order $locked): bool => $locked->order_status === OrderStatus::Expired
                && $locked->payment_status === PaymentStatus::Expired,
            message: fn (Order $claimed): string => $this->expiredMessage($claimed),
        );

        $this->deletePending($order);
    }

    public function deletePending(Order $order): void
    {
        if (blank(config('services.telegram.token'))) {
            return;
        }

        $target = Order::query()
            ->whereKey($order->id)
            ->first([
                'id',
                'order_status',
                'payment_status',
                'admin_pending_order_notification_chat_id',
                'admin_pending_order_notification_message_id',
                'admin_pending_order_notification_deleted_at',
            ]);

        if (! $target || ! $this->shouldDeletePendingNotification($target)) {
            return;
        }

        $chatId = $target->admin_pending_order_notification_chat_id ?: $this->privateAdminChatId();
        $messageId = $target->admin_pending_order_notification_message_id;
        if (! $chatId || ! $messageId || $target->admin_pending_order_notification_deleted_at !== null) {
            return;
        }

        try {
            $this->bot->deleteMessage($chatId, $messageId);
        } catch (Throwable $e) {
            Log::warning('telegram.admin_pending_order_notification_delete_failed', [
                'order_id' => $target->id,
                'chat_id' => DogfoodTiming::correlation($chatId),
                'message_id' => $messageId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        Order::query()
            ->whereKey($target->id)
            ->where('admin_pending_order_notification_message_id', $messageId)
            ->whereNull('admin_pending_order_notification_deleted_at')
            ->update(['admin_pending_order_notification_deleted_at' => now()]);
    }

    private function sendOnce(Order $order, string $startedAtColumn, string $sentAtColumn, string $step, callable $canSend, callable $message, ?callable $sentUpdates = null): void
    {
        if (blank(config('services.telegram.token'))) {
            return;
        }

        $chatId = $this->privateAdminChatId();
        if (! $chatId) {
            return;
        }

        $claimed = DB::transaction(function () use ($order, $startedAtColumn, $sentAtColumn, $canSend): ?Order {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->{$sentAtColumn} !== null || $locked->{$startedAtColumn} !== null || ! $canSend($locked)) {
                return null;
            }

            $locked->forceFill([$startedAtColumn => now()])->save();

            return $locked->fresh();
        }, 5);

        if (! $claimed) {
            return;
        }

        try {
            $claimed->loadMissing(['customer', 'orderItems', 'payments']);
            $response = DogfoodTiming::time('dogfood.telegram.checkout_step', [
                'step' => $step,
                'payment_mode' => $this->paymentMode($claimed),
                'order_correlation_id' => DogfoodTiming::correlation($claimed->id),
                'customer_correlation_id' => DogfoodTiming::correlation($claimed->customer?->telegram_id ?: $claimed->customer_id),
            ], fn () => $this->bot->sendMessage($chatId, $this->truncateMessage($message($claimed)), $this->dashboardReplyMarkup($claimed)));
        } catch (Throwable $e) {
            Order::query()
                ->whereKey($claimed->id)
                ->whereNull($sentAtColumn)
                ->update([$startedAtColumn => null]);

            throw $e;
        }

        $updates = [$sentAtColumn => now()];
        if ($sentUpdates !== null) {
            $updates += $sentUpdates(is_array($response) ? $response : [], $claimed, $chatId);
        }

        Order::query()
            ->whereKey($claimed->id)
            ->whereNotNull($startedAtColumn)
            ->whereNull($sentAtColumn)
            ->update($updates);
    }

    private function pendingMessageTrackingUpdates(array $response, string $chatId): array
    {
        $messageId = data_get($response, 'result.message_id');

        return [
            'admin_pending_order_notification_chat_id' => (string) $chatId,
            'admin_pending_order_notification_message_id' => is_numeric($messageId) ? (int) $messageId : null,
            'admin_pending_order_notification_deleted_at' => null,
        ];
    }

    private function shouldDeletePendingNotification(Order $order): bool
    {
        return $order->order_status !== OrderStatus::PendingPayment
            || $order->payment_status !== PaymentStatus::Pending;
    }

    private function pendingMessage(Order $order): string
    {
        $payment = $this->relevantPayment($order);
        $lines = [
            '🧾 Checkout belum bayar',
            '━━━━━━━━━━━━━━',
            'Invoice: '.$order->invoice_number,
            'Pembeli: '.$this->buyerLabel($order),
            'Sumber: '.$this->sourceLabel($order),
            'Status: Menunggu pembayaran',
            'Metode: '.$this->paymentLabel($payment),
            'Total: '.$this->rupiah($order->total_amount),
            '',
            'Produk:',
            ...$this->itemLines($order),
        ];

        if ($order->expires_at) {
            $lines[] = '';
            $lines[] = 'Batas bayar: '.$this->timeLabel($order->expires_at);
        }

        $lines[] = '';
        $lines[] = 'Cek dashboard untuk follow up pembeli ini.';

        return implode("\n", $lines);
    }

    private function expiredMessage(Order $order): string
    {
        $payment = $this->relevantPayment($order);
        $expiredAt = $payment?->expired_at ?? $order->expires_at ?? $order->updated_at;
        $lines = [
            '⌛ Checkout expired belum bayar',
            '━━━━━━━━━━━━━━',
            'Invoice: '.$order->invoice_number,
            'Pembeli: '.$this->buyerLabel($order),
            'Sumber: '.$this->sourceLabel($order),
            'Status: Expired / belum bayar',
            'Metode: '.$this->paymentLabel($payment),
            'Total: '.$this->rupiah($order->total_amount),
            '',
            'Produk:',
            ...$this->itemLines($order),
        ];

        if ($expiredAt) {
            $lines[] = '';
            $lines[] = 'Expired at: '.$this->timeLabel($expiredAt);
        }

        $lines[] = '';
        $lines[] = 'Stok sudah dilepas kembali.';
        $lines[] = 'Cek dashboard untuk follow up pembeli ini.';

        return implode("\n", $lines);
    }

    private function relevantPayment(Order $order): ?Payment
    {
        return $order->payments->first(fn (Payment $payment): bool => $payment->status === PaymentStatus::Paid)
            ?? $order->payments->first(fn (Payment $payment): bool => $payment->status === PaymentStatus::Pending)
            ?? $order->payments->first(fn (Payment $payment): bool => $payment->status === PaymentStatus::Expired)
            ?? $order->payments->first();
    }

    private function itemLines(Order $order): array
    {
        $items = $order->orderItems->values();
        $lines = $items
            ->take(6)
            ->map(fn ($item): string => '- '.$item->product_name.' x '.$item->quantity)
            ->all();

        $remaining = $items->count() - count($lines);
        if ($remaining > 0) {
            $lines[] = '+ '.$remaining.' produk lainnya';
        }

        return $lines === [] ? ['- Produk digital x 1'] : $lines;
    }

    private function buyerLabel(Order $order): string
    {
        $customer = $order->customer;
        $name = trim((string) ($customer?->username ?: $customer?->first_name ?: 'Pembeli'));
        if ($customer?->username) {
            $name = '@'.ltrim($name, '@');
        }

        if ($customer?->telegram_id) {
            return $name.' ('.$customer->telegram_id.')';
        }

        return $name;
    }

    private function sourceLabel(Order $order): string
    {
        return $order->customer?->telegram_id ? 'Telegram Bot' : 'Web Shop';
    }

    private function paymentLabel(?Payment $payment): string
    {
        $label = trim((string) ($payment?->payment_method_label ?: $payment?->method ?: $payment?->provider ?: 'Pembayaran'));

        return match ($label) {
            'QR_MANUAL' => 'QR Manual',
            'api_qris', 'hosted' => 'QRIS',
            default => $label,
        };
    }

    private function paymentMode(Order $order): string
    {
        return strtolower((string) ($this->relevantPayment($order)?->provider ?: 'unknown'));
    }

    private function rupiah(int $amount): string
    {
        return 'Rp'.number_format($amount, 0, ',', '.');
    }

    private function timeLabel($date): string
    {
        return $date->copy()->timezone((string) config('app.timezone'))->format('d M Y, H:i');
    }

    private function dashboardReplyMarkup(Order $order): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => 'Buka Dashboard', 'url' => route('admin.orders.show', $order)],
            ]],
        ];
    }

    private function truncateMessage(string $message): string
    {
        return mb_strlen($message) <= 3900
            ? $message
            : mb_substr($message, 0, 3890).'...';
    }

    private function privateAdminChatId(): ?string
    {
        $chatId = $this->adminAccess->adminChatId();

        return $chatId !== null && preg_match('/^[1-9][0-9]*$/', $chatId) === 1
            ? $chatId
            : null;
    }
}
