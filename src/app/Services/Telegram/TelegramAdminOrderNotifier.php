<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Support\DogfoodTiming;
use Illuminate\Support\Facades\DB;
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
        );
    }

    public function sendPaid(Order $order): void
    {
        $this->sendOnce(
            order: $order,
            startedAtColumn: 'admin_paid_order_notification_started_at',
            sentAtColumn: 'admin_paid_order_notified_at',
            step: 'admin_paid_order_notification',
            canSend: fn (Order $locked): bool => $locked->payment_status === PaymentStatus::Paid,
            message: fn (Order $claimed): string => $this->paidMessage($claimed),
        );
    }

    private function sendOnce(Order $order, string $startedAtColumn, string $sentAtColumn, string $step, callable $canSend, callable $message): void
    {
        if (blank(config('services.telegram.token'))) {
            return;
        }

        $chatId = $this->adminAccess->adminChatId();
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
            DogfoodTiming::time('dogfood.telegram.checkout_step', [
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

        Order::query()
            ->whereKey($claimed->id)
            ->whereNotNull($startedAtColumn)
            ->whereNull($sentAtColumn)
            ->update([$sentAtColumn => now()]);
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

    private function paidMessage(Order $order): string
    {
        $payment = $this->relevantPayment($order);
        $lines = [
            '🎉 Produk terjual!',
            '━━━━━━━━━━━━━━',
            'Invoice: '.$order->invoice_number,
            'Pembeli: '.$this->buyerLabel($order),
            'Sumber: '.$this->sourceLabel($order),
            'Status: Pembayaran selesai',
            'Metode: '.$this->paymentLabel($payment),
            'Earning: '.$this->rupiah($order->total_amount).' (setelah fee)',
            '',
            'Produk:',
            ...$this->itemLines($order),
        ];

        if ($payment?->paid_at) {
            $lines[] = '';
            $lines[] = 'Paid at: '.$this->timeLabel($payment->paid_at);
        }

        $lines[] = '';
        $lines[] = 'Cek dashboard untuk proses order ini.';

        return implode("\n", $lines);
    }

    private function relevantPayment(Order $order): ?Payment
    {
        return $order->payments->first(fn (Payment $payment): bool => $payment->status === PaymentStatus::Paid)
            ?? $order->payments->first(fn (Payment $payment): bool => $payment->status === PaymentStatus::Pending)
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
}
