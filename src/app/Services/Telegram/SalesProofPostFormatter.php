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
        private readonly TelegramUiIcons $icons,
        private readonly TelegramHtmlCaption $captions,
        private readonly TelegramProductNameRenderer $productNames,
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
            $this->icons->renderLegacyIcons('🎉 ORDER PAYMENT CONFIRMED'),
            '',
            $this->icons->renderTextIcon('message.users').' Buyer: '.$presentation->buyerName,
            $this->icons->renderTextIcon('message.ticket').' Order ID: '.$presentation->invoiceId,
            ...$this->itemSummaryLines($items),
            ...$this->lowStockLines($remainingStock),
            $this->icons->renderLegacyIcons('💳 Paid via: ').$presentation->paymentMethod,
            $this->icons->renderTextIcon('message.deadline').' Completed: '.$presentation->paidAtWib(),
            $this->icons->renderTextIcon('message.quantity').' Quantity: '.$quantity,
            $this->icons->renderLegacyIcons('💵 Subtotal: ').$this->money($presentation->subtotal(), $presentation->currency),
            ...($presentation->providerFee > 0
                ? [$this->icons->renderLegacyIcons('💸 Fee: ').$this->money($presentation->providerFee, $presentation->currency)]
                : []),
            $this->icons->renderTextIcon('message.cash').' Amount paid: '.$this->money($presentation->totalPaid, $presentation->currency),
            $this->icons->renderLegacyIcons('🏆 Successful orders: #').$successfulPostNumber,
        ];

        $bulkLines = $this->bulkPricingLines($presentation);
        $lines = $bulkLines === []
            ? [...$lines, '', '━━━━━━━━━━━━━━', $this->icons->renderLegacyIcons('✨ Thank you for choosing Lhie Store.')]
            : [...$lines, ...$bulkLines, '━━━━━━━━━━━━━━', $this->icons->renderLegacyIcons('✨ Thank you for choosing Lhie Store.')];

        return $this->boundedCaption($lines);
    }

    /**
     * Format the public English Store Channel caption for a settled Store
     * Balance top-up. The input is already a public-safe immutable
     * presentation, and the caption deliberately omits the order counter so a
     * top-up never inflates the published successful-order number.
     */
    public function formatTopUp(PaidInvoicePresentation $presentation): string
    {
        $lines = [
            $this->icons->renderLegacyIcons('🎉 BALANCE TOP-UP CONFIRMED'),
            '',
            $this->icons->renderTextIcon('message.users').' Buyer: '.$presentation->buyerName,
            $this->icons->renderTextIcon('message.ticket').' Top-up ID: '.$presentation->invoiceId,
            $this->icons->renderLegacyIcons('💳 Paid via: ').$presentation->paymentMethod,
            $this->icons->renderTextIcon('message.deadline').' Completed: '.$presentation->paidAtWib(),
            $this->icons->renderTextIcon('message.cash').' Amount paid: '.$this->money($presentation->totalPaid, $presentation->currency),
            '',
            '━━━━━━━━━━━━━━',
            $this->icons->renderLegacyIcons('✨ Thank you for topping up at Lhie Store.'),
        ];

        return $this->boundedCaption($lines);
    }

    public function shoppingButtonLabel(): string
    {
        return $this->icons->renderLegacyIcons('🛒 Shop Now');
    }

    /**
     * Dispatch the previously materialized text-only template unchanged.
     */
    public function formatLegacy(Order $order, ?int $successfulPostNumber = null): string
    {
        $order->loadMissing(['customer', 'orderItems']);
        $successfulPostNumber ??= $this->nextSuccessfulPostNumber($order);
        $primaryItem = $order->orderItems->first();
        $primary = $primaryItem?->product_name ?? 'Digital product';
        if ($primaryItem !== null) {
            $primary = $this->productNames->render(
                (string) $primary,
                $primaryItem->product_icon,
                $primaryItem->product_custom_emoji_id,
            );
        }
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
     * @param  list<array{product_name: string, quantity: int, unit_price?: int, subtotal?: int, quantity_price_tier_minimum?: ?int, product_icon?: ?string, product_custom_emoji_id?: ?string}>  $items
     * @return list<string>
     */
    private function itemSummaryLines(array $items): array
    {
        if ($items === []) {
            return [$this->icons->renderTextIcon('message.details').' Item: Digital product'];
        }
        if (count($items) === 1) {
            $item = $items[0];

            return [$this->icons->renderTextIcon('message.details').' Item: '.$this->itemLabel($item)];
        }

        $lines = [$this->icons->renderTextIcon('message.details').' Items:'];
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

    /** @return list<string> */
    private function bulkPricingLines(PaidInvoicePresentation $presentation): array
    {
        $minimums = $presentation->appliedQuantityPriceTierMinimums();
        if ($minimums === []) {
            return [];
        }

        if (count($presentation->items) === 1 && count($minimums) === 1) {
            return [$this->icons->renderLegacyIcons('🏷 Bulk pricing applied · ').$minimums[0].'+ units'];
        }

        return [$this->icons->renderLegacyIcons('🏷 Bulk pricing applied')];
    }

    /** @param array{product_name: string, quantity: int, unit_price?: int, subtotal?: int, quantity_price_tier_minimum?: ?int, product_icon?: ?string, product_custom_emoji_id?: ?string} $item */
    private function itemLabel(array $item): string
    {
        $name = $this->cleanText((string) ($item['product_name'] ?? 'Digital product'));
        $name = $name !== '' ? $name : 'Digital product';
        if (mb_strlen($name) > 72) {
            $name = mb_substr($name, 0, 69).'…';
        }

        $renderedName = array_key_exists('product_icon', $item) || array_key_exists('product_custom_emoji_id', $item)
            ? $this->productNames->render(
                $name,
                isset($item['product_icon']) ? (string) $item['product_icon'] : null,
                isset($item['product_custom_emoji_id']) ? (string) $item['product_custom_emoji_id'] : null,
            )
            : $name;

        return $renderedName.' × '.max(0, (int) ($item['quantity'] ?? 0));
    }

    /** @return list<string> */
    private function lowStockLines(?int $remainingStock): array
    {
        return $remainingStock === null || $remainingStock < 1
            ? []
            : [$this->icons->renderLegacyIcons('🔥 Only ').$remainingStock.' left in stock'];
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
        if ($this->captions->parsedLength($caption, ['parse_mode' => 'HTML']) <= self::MAX_CAPTION_LENGTH) {
            return $caption;
        }

        $tail = implode("\n", array_slice($lines, -4));
        $head = implode("\n", array_slice($lines, 0, -4));
        $tailLength = $this->captions->parsedLength($tail, ['parse_mode' => 'HTML']);
        $budget = max(1, self::MAX_CAPTION_LENGTH - $tailLength - 2);
        $head = $this->captions->truncateTextPreservingCustomEmoji($head, max(1, $budget - 1), '');
        $head = rtrim($head).'…';

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
        return $this->icons->renderLegacyIcons("🎉 SUCCESSFUL PURCHASE 🎉\n\n")
            .$this->icons->renderTextIcon('message.users').' Buyer: '.$buyerMask."\n"
            .$this->icons->renderTextIcon('message.details').' Product: '.$productName."\n"
            .$this->icons->renderTextIcon('message.quantity').' Quantity: '.$quantity."x\n"
            .$this->icons->renderLegacyIcons('🏆 Successful orders: #').$successfulPostNumber."\n\n"
            .$this->icons->renderLegacyIcons("✨ Thank you for shopping with us!\n")
            .'Want to order too? Tap the button below '.$this->icons->renderTextIcon('message.next');
    }
}
