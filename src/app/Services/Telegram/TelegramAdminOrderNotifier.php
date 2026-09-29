<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\TelegramOutboundPreSendException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TelegramOutboundEffect;
use App\Support\DogfoodTiming;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramAdminOrderNotifier
{
    public function __construct(
        private readonly TelegramBotClient $bot,
        private readonly TelegramAdminAccess $adminAccess,
        private readonly TelegramUiIcons $uiIcons,
    ) {}

    /**
     * Ledger-backed compatibility entry point for callers that still invoke
     * the notifier directly instead of dispatching the admin notification job.
     */
    public function sendPending(Order $order): void
    {
        $effects = app(TelegramOutboundEffectService::class);
        $effect = $effects->recordAdminOrderPendingNotification($order);

        if ($effect !== null) {
            app(AdminOrderNotificationDeliveryPipeline::class)->deliver($effect);
        }
    }

    /**
     * Pending-message deletion is a cleanup projection, not a new outbound
     * effect. Keep this compatibility entry point ledger-aware.
     */
    public function sendPaid(Order $order): void
    {
        $this->deletePending($order);
    }

    /**
     * Ledger-backed compatibility entry point for direct expired notifications.
     */
    public function sendExpired(Order $order): void
    {
        $effects = app(TelegramOutboundEffectService::class);
        $effect = $effects->recordAdminOrderExpiredNotification($order);

        if ($effect !== null) {
            app(AdminOrderNotificationDeliveryPipeline::class)->deliver($effect);
        }

        $this->deletePending($order);
    }

    /**
     * Send an admin notification for a claimed Telegram Outbound Effect.
     * Claiming and finalization remain owned by TelegramOutboundEffectLedgerProtocol.
     *
     * @return array<string, mixed>
     */
    public function sendPendingEffect(Order $order, string $chatId): array
    {
        return $this->sendEffectMessage(
            order: $order,
            chatId: $chatId,
            step: 'admin_pending_order_notification',
            message: fn (Order $claimed): string => $this->pendingMessage($claimed),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function sendExpiredEffect(Order $order, string $chatId): array
    {
        return $this->sendEffectMessage(
            order: $order,
            chatId: $chatId,
            step: 'admin_expired_order_notification',
            message: fn (Order $claimed): string => $this->expiredMessage($claimed),
        );
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
                'admin_pending_order_notification_deleted_at',
                'admin_pending_order_notification_chat_id',
                'admin_pending_order_notification_message_id',
            ]);

        if (! $target || ! $this->shouldDeletePendingNotification($target)) {
            return;
        }

        $effect = TelegramOutboundEffect::query()
            ->where('source_type', Order::class)
            ->where('source_id', (string) $target->id)
            ->where('effect_type', TelegramOutboundEffectService::TYPE_ADMIN_PENDING)
            ->where('state', 'sent')
            ->whereNotNull('telegram_message_id')
            ->latest('sent_at')
            ->first(['telegram_chat_id', 'telegram_message_id']);
        $chatId = $effect?->telegram_chat_id ?: $target->admin_pending_order_notification_chat_id ?: $this->privateAdminChatId();
        $messageId = $effect?->telegram_message_id ?: $target->admin_pending_order_notification_message_id;
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

    /**
     * @return array<string, mixed>
     */
    private function sendEffectMessage(Order $order, string $chatId, string $step, callable $message): array
    {
        if (blank(config('services.telegram.token'))
            || preg_match('/^[1-9][0-9]*$/', $chatId) !== 1) {
            throw new TelegramOutboundPreSendException('Admin Telegram notification is not configured.');
        }

        $claimed = $order->fresh(['customer', 'orderItems', 'payments']) ?? $order;

        return DogfoodTiming::time('dogfood.telegram.checkout_step', [
            'step' => $step,
            'payment_mode' => $this->paymentMode($claimed),
            'order_correlation_id' => DogfoodTiming::correlation($claimed->id),
            'customer_correlation_id' => DogfoodTiming::correlation($claimed->customer?->telegram_id ?: $claimed->customer_id),
        ], fn () => $this->bot->sendMessage($chatId, $this->truncateMessage($message($claimed)), $this->dashboardReplyMarkup($claimed)));
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
            $this->uiIcons->renderLegacyIcons('🧾 Checkout belum bayar'),
            '━━━━━━━━━━━━━━',
            'Invoice: '.$order->invoice_number,
            'Pembeli: '.$this->buyerLabel($order),
            'Sumber: '.$this->sourceLabel($order),
            'Status: Menunggu pembayaran',
            'Metode: '.$this->paymentLabel($payment),
            'Total: '.$this->paymentTotal($order, $payment),
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
            $this->uiIcons->renderLegacyIcons('⌛ Checkout expired belum bayar'),
            '━━━━━━━━━━━━━━',
            'Invoice: '.$order->invoice_number,
            'Pembeli: '.$this->buyerLabel($order),
            'Sumber: '.$this->sourceLabel($order),
            'Status: Expired / belum bayar',
            'Metode: '.$this->paymentLabel($payment),
            'Total: '.$this->paymentTotal($order, $payment),
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
            'BINANCE_PAY_ID' => 'Binance Pay',
            'USDT_BEP20' => 'USDT BEP20 (BSC)',
            default => $label,
        };
    }

    private function paymentTotal(Order $order, ?Payment $payment): string
    {
        if ($payment?->currency === 'USDT') {
            return rtrim(rtrim(number_format($payment->amount / 1_000_000, 6, '.', ''), '0'), '.').' USDT';
        }

        return $this->rupiah($order->total_amount);
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
