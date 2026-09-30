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
            $this->channelLegacyIcons('🎉 ORDER PAYMENT CONFIRMED'),
            '',
            $this->channelTextIcon('message.users').' Buyer: '.$presentation->buyerName,
            $this->channelTextIcon('message.ticket').' Order ID: '.$presentation->invoiceId,
            ...$this->itemSummaryLines($items),
            ...$this->lowStockLines($remainingStock),
            $this->channelLegacyIcons('💳 Paid via: ').$presentation->paymentMethod,
            $this->channelTextIcon('message.deadline').' Completed: '.$presentation->paidAtWib(),
            $this->channelTextIcon('message.quantity').' Quantity: '.$quantity,
            $this->channelLegacyIcons('💵 Subtotal: ').$this->money($presentation->subtotal(), $presentation->currency),
            ...($presentation->providerFee > 0
                ? [$this->channelLegacyIcons('💸 Fee: ').$this->money($presentation->providerFee, $presentation->currency)]
                : []),
            $this->channelTextIcon('message.cash').' Amount paid: '.$this->money($presentation->totalPaid, $presentation->currency),
            $this->channelLegacyIcons('🏆 Successful orders: #').$successfulPostNumber,
        ];

        $bulkLines = $this->bulkPricingLines($presentation);
        $lines = $bulkLines === []
            ? [...$lines, '', '━━━━━━━━━━━━━━', $this->channelLegacyIcons('✨ Thank you for choosing Lhie Store.')]
            : [...$lines, ...$bulkLines, '━━━━━━━━━━━━━━', $this->channelLegacyIcons('✨ Thank you for choosing Lhie Store.')];

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
            $this->channelLegacyIcons('🎉 BALANCE TOP-UP CONFIRMED'),
            '',
            $this->channelTextIcon('message.users').' Buyer: '.$presentation->buyerName,
            $this->channelTextIcon('message.ticket').' Top-up ID: '.$presentation->invoiceId,
            $this->channelLegacyIcons('💳 Paid via: ').$presentation->paymentMethod,
            $this->channelTextIcon('message.deadline').' Completed: '.$presentation->paidAtWib(),
            $this->channelTextIcon('message.cash').' Amount paid: '.$this->money($presentation->totalPaid, $presentation->currency),
            '',
            '━━━━━━━━━━━━━━',
            $this->channelLegacyIcons('✨ Thank you for topping up at Lhie Store.'),
        ];

        return $this->boundedCaption($lines);
    }

    public function shoppingButtonLabel(): string
    {
        return $this->channelLegacyIcons('🛒 Shop Now');
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
            $primary = $this->channelProductName(
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
            return [$this->channelTextIcon('message.details').' Item: Digital product'];
        }
        if (count($items) === 1) {
            $item = $items[0];

            return [$this->channelTextIcon('message.details').' Item: '.$this->itemLabel($item)];
        }

        $lines = [$this->channelTextIcon('message.details').' Items:'];
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
            return [$this->channelLegacyIcons('🏷 Bulk pricing applied · ').$minimums[0].'+ units'];
        }

        return [$this->channelLegacyIcons('🏷 Bulk pricing applied')];
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
            ? $this->channelProductName(
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
            : [$this->channelLegacyIcons('🔥 Only ').$remainingStock.' left in stock'];
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
        return $this->channelLegacyIcons("🎉 SUCCESSFUL PURCHASE 🎉\n\n")
            .$this->channelTextIcon('message.users').' Buyer: '.$buyerMask."\n"
            .$this->channelTextIcon('message.details').' Product: '.$productName."\n"
            .$this->channelTextIcon('message.quantity').' Quantity: '.$quantity."x\n"
            .$this->channelLegacyIcons('🏆 Successful orders: #').$successfulPostNumber."\n\n"
            .$this->channelLegacyIcons("✨ Thank you for shopping with us!\n")
            .'Want to order too? Tap the button below '.$this->channelTextIcon('message.next');
    }

    private function channelLegacyIcons(string $text): string
    {
        return $this->icons->renderLegacyIcons($text, CustomEmojiCapability::SCOPE_CHANNEL);
    }

    private function channelTextIcon(string $key): string
    {
        return $this->icons->renderTextIcon($key, CustomEmojiCapability::SCOPE_CHANNEL);
    }

    private function channelProductName(string $name, ?string $icon, ?string $customEmojiId): string
    {
        return $this->productNames->render($name, $icon, $customEmojiId, CustomEmojiCapability::SCOPE_CHANNEL);
    }
}
