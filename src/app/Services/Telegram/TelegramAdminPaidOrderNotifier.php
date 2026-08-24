<?php

namespace App\Services\Telegram;

use App\Enums\PaymentStatus;
use App\Exceptions\TelegramOutboundPreSendException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\PaymentReceiptReport;
use App\Support\DogfoodTiming;

class TelegramAdminPaidOrderNotifier
{
    public function __construct(
        private readonly TelegramBotClient $bot,
        private readonly TelegramAdminAccess $adminAccess,
        private readonly PaymentReceiptReport $receiptReport,
    ) {}

    /**
     * Ledger-backed compatibility entry point for direct callers.
     */
    public function send(Order $order): void
    {
        $effects = app(TelegramOutboundEffectService::class);
        $effect = $effects->recordAdminOrderPaidNotification($order);

        if ($effect !== null) {
            app(AdminOrderNotificationDeliveryPipeline::class)->deliver($effect);
        }
    }

    /**
     * Send a paid notification for a claimed Telegram Outbound Effect.
     * Claiming and finalization remain owned by TelegramOutboundEffectLedgerProtocol.
     *
     * @return array<string, mixed>
     */
    public function sendEffect(Order $order, string $chatId): array
    {
        if (blank(config('services.telegram.token'))
            || preg_match('/^[1-9][0-9]*$/', $chatId) !== 1) {
            throw new TelegramOutboundPreSendException('Paid admin Telegram notification is not configured.');
        }

        $claimed = $order->fresh(['customer', 'orderItems', 'payments']) ?? $order;

        return DogfoodTiming::time('dogfood.telegram.checkout_step', [
            'step' => 'admin_paid_order_notification',
            'payment_mode' => $this->paymentMode($claimed),
            'order_correlation_id' => DogfoodTiming::correlation($claimed->id),
            'customer_correlation_id' => DogfoodTiming::correlation($claimed->customer?->telegram_id ?: $claimed->customer_id),
        ], fn () => $this->bot->sendMessage($chatId, $this->truncateMessage($this->paidMessage($claimed)), $this->dashboardReplyMarkup($claimed)));
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
        $net = $payment === null ? (int) $amount : $this->receiptReport->netAmount($payment);
        $feeReducesEarning = $payment !== null
            && (int) ($payment->provider_fee ?? 0) > 0
            && $net < (int) $amount;

        if ($payment?->currency === 'USDT') {
            $label = $this->usdtLabel($net);

            return $feeReducesEarning ? $label.' (setelah fee)' : $label;
        }

        if ($feeReducesEarning) {
            return $this->rupiah($net).' (setelah fee)';
        }

        return $this->rupiah($net);
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
            'USDT_BEP20' => 'USDT BEP20 (BSC)',
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

    private function usdtLabel(int $microAmount): string
    {
        $whole = intdiv($microAmount, 1_000_000);
        $fraction = $microAmount % 1_000_000;

        if ($fraction === 0) {
            return $whole.' USDT';
        }

        $fractionLabel = rtrim(str_pad((string) $fraction, 6, '0', STR_PAD_LEFT), '0');

        return $whole.'.'.$fractionLabel.' USDT';
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
