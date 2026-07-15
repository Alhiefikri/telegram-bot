<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Support\DogfoodTiming;
use Illuminate\Support\Facades\DB;

class TelegramAdminPaidOrderNotifier
{
    public function __construct(
        private readonly TelegramBotClient $bot,
        private readonly TelegramAdminAccess $adminAccess,
    ) {}

    public function send(Order $order): void
    {
        $this->sendOnce(
            order: $order,
            startedAtColumn: 'admin_paid_order_notification_started_at',
            sentAtColumn: 'admin_paid_order_notified_at',
            step: 'admin_paid_order_notification',
            canSend: fn (Order $locked): bool => in_array($locked->order_status, [OrderStatus::Processing, OrderStatus::Completed], true)
                && $locked->payment_status === PaymentStatus::Paid,
            message: fn (Order $claimed): string => $this->paidMessage($claimed),
        );
    }

    private function sendOnce(Order $order, string $startedAtColumn, string $sentAtColumn, string $step, callable $canSend, callable $message): void
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

        $claimed->loadMissing(['customer', 'orderItems', 'payments']);
        $response = DogfoodTiming::time('dogfood.telegram.checkout_step', [
            'step' => $step,
            'payment_mode' => $this->paymentMode($claimed),
            'order_correlation_id' => DogfoodTiming::correlation($claimed->id),
            'customer_correlation_id' => DogfoodTiming::correlation($claimed->customer?->telegram_id ?: $claimed->customer_id),
        ], fn () => $this->bot->sendMessage($chatId, $this->truncateMessage($message($claimed)), $this->dashboardReplyMarkup($claimed)));

        Order::query()
            ->whereKey($claimed->id)
            ->whereNotNull($startedAtColumn)
            ->whereNull($sentAtColumn)
            ->update([$sentAtColumn => now()]);
    }

    private function paidMessage(Order $order): string
    {
        $payment = $this->relevantPayment($order);

        $lines = [
            '🔔 Produk terjual!',
            '━━━━━━━━━━━━━━',
            'Invoice: '.$order->invoice_number,
            'Pembeli: '.$this->buyerLabel($order),
            'Sumber: '.$this->sourceLabel($order),
            'Status: Pembayaran selesai',
            'Metode: '.$this->paymentLabel($payment),
            'Earning: '.$this->earningLabel($payment, $order),
            '',
            'Produk:',
            ...$this->itemLines($order),
            '',
            '📦 Sisa Stok:',
            ...$this->stockLines($order),
            '',
            'Paid at: '.$this->timeLabel($payment?->paid_at ?? $order->updated_at),
            '',
            'Cek dashboard untuk proses order ini.',
        ];

        return implode("\n", $lines);
    }

    private function earningLabel(?Payment $payment, Order $order): string
    {
        $amount = $payment?->amount ?? $order->total_amount;
        $fee = $payment?->provider_fee ?? 0;

        if ($payment?->currency === 'USDT') {
            $net = $amount - $fee;
            $label = rtrim(rtrim(number_format($net / 1_000_000, 6, '.', ''), '0'), '.').' USDT';

            return $fee > 0 ? $label.' (setelah fee)' : $label;
        }

        if ($fee > 0) {
            return $this->rupiah($amount - $fee).' (setelah fee)';
        }

        return $this->rupiah($amount);
    }

    private function stockLines(Order $order): array
    {
        $items = $order->orderItems->values();
        $lines = [];

        // Load all products with trashed to prevent N+1 and get the actual stock
        $productIds = $items->pluck('product_id')->unique()->filter();
        $products = Product::withTrashed()->whereIn('id', $productIds)->get()->keyBy('id');

        foreach ($items as $item) {
            $product = $products->get($item->product_id);
            if (! $product) {
                $lines[] = '- '.$item->product_name.': (Produk dihapus)';

                continue;
            }

            $lines[] = '- '.$item->product_name.': '.$product->availableStock();
        }

        if ($lines === []) {
            $lines[] = '- Produk digital: ∞';
        }

        return $lines;
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

    private function relevantPayment(Order $order): ?Payment
    {
        return $order->payments->first(fn (Payment $payment): bool => $payment->status === PaymentStatus::Paid)
            ?? $order->payments->first();
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
