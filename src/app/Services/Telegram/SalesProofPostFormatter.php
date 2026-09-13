<?php

namespace App\Services\Telegram;

use App\Models\Order;
use App\Models\SalesProofPost;
use App\Services\PaidInvoice\PaidInvoicePresentation;

class SalesProofPostFormatter
{
    public const MAX_CAPTION_LENGTH = 1024;

    public function __construct(
        private readonly SalesProofPostNumbering $numbering,
    ) {}

    /**
     * Format the public English Store Channel caption for a photo-capable
     * Sales Proof. The input is already a public-safe immutable presentation,
     * so fake publication never needs a persisted Order.
     *
     * The optional remaining stock is a decision already made by
     * SalesProofStockSignal; the formatter renders the scarcity line it is
     * handed and never reads mutable Product state itself.
     */
    public function format(PaidInvoicePresentation $presentation, ?int $successfulPostNumber = null, ?int $remainingStock = null): string
    {
        $successfulPostNumber ??= $this->numbering->nextNumber();
        $items = $presentation->items;
        $quantity = array_sum(array_map(
            static fn (array $item): int => max(0, (int) ($item['quantity'] ?? 0)),
            $items,
        ));

        $lines = [
            '🎉 ORDER PAYMENT CONFIRMED',
            '',
            '👤 Buyer: '.$presentation->buyerName,
            '🧾 Order ID: '.$presentation->invoiceId,
            ...$this->itemSummaryLines($items),
            ...$this->lowStockLines($remainingStock),
            '💳 Paid via: '.$presentation->paymentMethod,
            '🕒 Completed: '.$presentation->paidAtWib(),
            '📦 Quantity: '.$quantity,
            '💵 Subtotal: '.$this->money($presentation->subtotal(), $presentation->currency),
            '💰 Amount paid: '.$this->money($presentation->totalPaid, $presentation->currency),
            '🏆 Successful orders: #'.$successfulPostNumber,
            '',
            '━━━━━━━━━━━━━━',
            '✨ Thank you for choosing Lhie Store.',
        ];

        return $this->boundedCaption($lines);
    }

    /**
     * Dispatch the previously materialized text-only template unchanged.
     */
    public function formatLegacy(Order $order, ?int $successfulPostNumber = null): string
    {
        $order->loadMissing(['customer', 'orderItems']);
        $successfulPostNumber ??= $this->nextSuccessfulPostNumber($order);
        $primary = $order->orderItems->first()?->product_name ?? 'Digital product';
        $otherCount = max(0, $order->orderItems->count() - 1);
        $other = $otherCount > 0 ? " + {$otherCount} other products" : '';

        return $this->formatLegacyMessage(
            $this->maskLegacyBuyer($order),
            $primary.$other,
            (int) $order->orderItems->sum('quantity'),
            $successfulPostNumber,
        );
    }

    /**
     * @param  list<array{product_name: string, quantity: int, unit_price?: int, subtotal?: int}>  $items
     * @return list<string>
     */
    private function itemSummaryLines(array $items): array
    {
        if ($items === []) {
            return ['🛍️ Item: Digital product'];
        }

        if (count($items) === 1) {
            $item = $items[0];

            return ['🛍️ Item: '.$this->itemLabel($item)];
        }

        $lines = ['🛍️ Items:'];
        $visibleItems = array_slice($items, 0, 6);
        foreach ($visibleItems as $item) {
            $lines[] = '• '.$this->itemLabel($item);
        }

        $remaining = count($items) - count($visibleItems);
        if ($remaining > 0) {
            $lines[] = '• +'.$remaining.' other items';
        }

        return $lines;
    }

    /** @param array{product_name: string, quantity: int, unit_price?: int, subtotal?: int} $item */
    private function itemLabel(array $item): string
    {
        $name = $this->cleanText((string) ($item['product_name'] ?? 'Digital product'));
        $name = $name !== '' ? $name : 'Digital product';
        if (mb_strlen($name) > 72) {
            $name = mb_substr($name, 0, 69).'…';
        }

        return $name.' × '.max(0, (int) ($item['quantity'] ?? 0));
    }

    /** @return list<string> */
    private function lowStockLines(?int $remainingStock): array
    {
        return $remainingStock === null || $remainingStock < 1
            ? []
            : ['🔥 Only '.$remainingStock.' left in stock'];
    }

    private function nextSuccessfulPostNumber(Order $order): int
    {
        return $order->salesProofPost?->status === SalesProofPost::STATUS_SENT
            ? $this->numbering->sentCount()
            : $this->numbering->nextNumber();
    }

    private function maskLegacyBuyer(Order $order): string
    {
        $name = (string) ($order->customer?->username ?: $order->customer?->first_name ?: 'Buyer');
        $name = ltrim(trim($name), '@');

        return mb_substr($name, 0, 2).'***';
    }

    /** @param list<string> $lines */
    private function boundedCaption(array $lines): string
    {
        $caption = implode("\n", $lines);
        if (mb_strlen($caption, 'UTF-8') <= self::MAX_CAPTION_LENGTH) {
            return $caption;
        }

        $tail = implode("\n", array_slice($lines, -4));
        $head = implode("\n", array_slice($lines, 0, -4));
        $budget = max(1, self::MAX_CAPTION_LENGTH - mb_strlen($tail, 'UTF-8') - 2);
        $head = rtrim(mb_substr($head, 0, max(1, $budget - 1), 'UTF-8')).'…';

        return $head."\n\n".$tail;
    }

    private function money(int $amount, string $currency): string
    {
        if ($currency === 'USDT') {
            $value = rtrim(rtrim(number_format($amount / 1_000_000, 6, '.', ''), '0'), '.');

            return ($value !== '' ? $value : '0').' USDT';
        }

        $formatted = number_format($amount, 0, ',', '.');

        return $currency === 'IDR' ? 'Rp '.$formatted : $currency.' '.$formatted;
    }

    private function cleanText(string $value): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);

        return trim(is_string($clean) ? $clean : $value);
    }

    private function formatLegacyMessage(string $buyerMask, string $productName, int $quantity, int $successfulPostNumber): string
    {
        return "🎉 SUCCESSFUL PURCHASE 🎉\n\n"
            .'👤 Buyer: '.$buyerMask."\n"
            .'🛍️ Product: '.$productName."\n"
            .'📦 Quantity: '.$quantity."x\n"
            .'🏆 Successful orders: #'.$successfulPostNumber."\n\n"
            ."✨ Thank you for shopping with us!\n"
            .'Want to order too? Tap the button below 👇';
    }
}
