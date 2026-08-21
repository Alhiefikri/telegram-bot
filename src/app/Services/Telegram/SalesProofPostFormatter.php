<?php

namespace App\Services\Telegram;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SalesProofPost;
use App\Services\PaymentReceiptReport;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class SalesProofPostFormatter
{
    public function __construct(
        private readonly SalesProofPostNumbering $numbering,
        private readonly PaymentReceiptReport $receiptReport,
    ) {}

    /**
     * Format the public Store Channel caption for a photo-capable Sales Proof.
     *
     * @param  array<string, mixed>|null  $invoiceMetadata
     */
    public function format(Order $order, ?int $successfulPostNumber = null, ?array $invoiceMetadata = null): string
    {
        $order->loadMissing(['customer', 'orderItems', 'payments']);
        $successfulPostNumber ??= $this->nextSuccessfulPostNumber($order);
        $metadata = $invoiceMetadata ?? $this->fallbackMetadata($order);
        $items = $this->captionItems($order, $metadata);
        $payment = $this->canonicalPaidPayment($order);
        $quantity = array_sum(array_map(
            static fn (array $item): int => max(0, (int) ($item['quantity'] ?? 0)),
            $items,
        ));
        $subtotal = array_key_exists('subtotal', $metadata)
            ? max(0, (int) $metadata['subtotal'])
            : array_sum(array_map(
                static fn (array $item): int => max(0, (int) ($item['subtotal'] ?? 0)),
                $items,
            ));
        $currency = $this->metadataString($metadata, 'currency', $this->currency($payment, $order));
        $totalPaid = array_key_exists('total_paid', $metadata)
            ? max(0, (int) $metadata['total_paid'])
            : max(0, (int) ($payment?->provider_total_payment ?? $order->total_amount));
        $orderId = $this->metadataString(
            $metadata,
            'order_id',
            trim((string) ($order->invoice_number ?: $order->getKey())),
        );
        $buyerId = $this->metadataString(
            $metadata,
            'buyer_telegram_id',
            $this->maskBuyerTelegramId($order),
        );
        $paidVia = $this->metadataString($metadata, 'payment_method', $this->paymentMethod($payment));
        $itemLines = $this->itemSummaryLines($items);

        $lines = [
            'ORDER PAYMENT CONFIRMED',
            '',
            'Buyer ID: '.$buyerId,
            'Order ID: '.$orderId,
            ...$itemLines,
            'Paid via: '.$paidVia,
            'Completed: '.$this->englishPaidAt($payment),
            'Quantity: '.$quantity,
            'Subtotal: '.$this->money($subtotal, $currency),
            'Amount paid: '.$this->money($totalPaid, $currency),
            'Successful orders: #'.$successfulPostNumber,
            '',
            '━━━━━━━━━━━━━━',
            'Thank you for choosing Lhie Store.',
        ];

        return implode("\n", $lines);
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

    public function formatFake(string $buyerMask, string $productName, int $quantity, int $successfulPostNumber): string
    {
        return $this->formatLegacyMessage($buyerMask, $productName, $quantity, $successfulPostNumber);
    }

    /**
     * @return list<array{product_name: string, quantity: int, unit_price?: int, subtotal?: int}>
     */
    private function captionItems(Order $order, array $metadata): array
    {
        $items = $metadata['items'] ?? null;
        if (! is_array($items)) {
            $items = $order->orderItems
                ->map(static fn ($item): array => [
                    'product_name' => (string) $item->product_name,
                    'quantity' => max(0, (int) $item->quantity),
                    'subtotal' => max(0, (int) $item->subtotal),
                ])
                ->all();
        }

        return array_values(array_filter($items, static fn ($item): bool => is_array($item)));
    }

    /**
     * @param  list<array{product_name: string, quantity: int, unit_price?: int, subtotal?: int}>  $items
     * @return list<string>
     */
    private function itemSummaryLines(array $items): array
    {
        if ($items === []) {
            return ['Item: Digital product'];
        }

        if (count($items) === 1) {
            $item = $items[0];

            return ['Item: '.$this->itemLabel($item)];
        }

        $lines = ['Items:'];
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

    /** @return array<string, mixed> */
    private function fallbackMetadata(Order $order): array
    {
        $payment = $this->canonicalPaidPayment($order);
        $items = $this->captionItems($order, []);

        return [
            'order_id' => trim((string) ($order->invoice_number ?: $order->getKey())),
            'buyer_telegram_id' => $this->maskBuyerTelegramId($order),
            'payment_method' => $this->paymentMethod($payment),
            'currency' => $this->currency($payment, $order),
            'items' => $items,
            'subtotal' => array_sum(array_map(
                static fn (array $item): int => max(0, (int) ($item['subtotal'] ?? 0)),
                $items,
            )),
            'total_paid' => max(0, (int) ($payment?->provider_total_payment ?? $order->total_amount)),
        ];
    }

    private function nextSuccessfulPostNumber(Order $order): int
    {
        return $order->salesProofPost?->status === SalesProofPost::STATUS_SENT
            ? $this->numbering->sentCount()
            : $this->numbering->nextNumber();
    }

    private function canonicalPaidPayment(Order $order): ?Payment
    {
        $payments = $order->payments;
        if (! $payments instanceof Collection) {
            return null;
        }

        return $this->receiptReport->canonicalFromPayments(
            $payments->filter(fn ($payment): bool => $payment instanceof Payment
                && (string) $payment->order_id === (string) $order->getKey()
                && $payment->status === PaymentStatus::Paid
                && $payment->paid_at !== null),
        )->first();
    }

    private function maskLegacyBuyer(Order $order): string
    {
        $name = (string) ($order->customer?->username ?: $order->customer?->first_name ?: 'Buyer');
        $name = ltrim(trim($name), '@');

        return mb_substr($name, 0, 2).'***';
    }

    private function maskBuyerTelegramId(Order $order): string
    {
        $raw = $order->customer?->getRawOriginal('telegram_id');
        $identifier = is_scalar($raw)
            ? trim((string) $raw)
            : trim((string) ($order->customer?->telegram_id ?? ''));

        if (! preg_match('/^\d{6,}$/', $identifier)) {
            return 'ID pembeli';
        }

        return substr($identifier, 0, 3).'*****'.substr($identifier, -2);
    }

    private function paymentMethod(?Payment $payment): string
    {
        $method = strtolower(trim((string) $payment?->method));
        $label = trim((string) $payment?->payment_method_label);
        $label = match ($method) {
            'api_qris', 'qris' => 'QRIS',
            'binance_pay_id' => 'Binance Pay',
            'hosted' => 'Pakasir',
            default => $label !== '' ? $label : $method,
        };

        return $this->cleanText($label !== '' ? $label : 'Unknown');
    }

    private function currency(?Payment $payment, Order $order): string
    {
        $currency = trim((string) ($payment?->currency ?: $order->currency ?: 'IDR'));

        return strtoupper($currency !== '' ? $currency : 'IDR');
    }

    private function englishPaidAt(?Payment $payment): string
    {
        $value = $payment?->paid_at;
        if ($value === null) {
            return 'Unknown';
        }

        try {
            $paidAt = ($value instanceof CarbonInterface
                ? CarbonImmutable::instance($value)
                : CarbonImmutable::parse($value)
            )->setTimezone('Asia/Jakarta');

            return $paidAt->format('d F Y, H:i').' WIB';
        } catch (\Throwable) {
            return 'Unknown';
        }
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

    private function metadataString(array $metadata, string $key, string $fallback): string
    {
        $value = $metadata[$key] ?? null;
        if (! is_scalar($value)) {
            return $fallback;
        }

        $value = $this->cleanText((string) $value);

        return $value !== '' ? $value : $fallback;
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
