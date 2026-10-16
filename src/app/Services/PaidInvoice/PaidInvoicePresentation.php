<?php

namespace App\Services\PaidInvoice;

use Carbon\CarbonImmutable;
use LogicException;

/**
 * Public-safe, transient invoice data shared by every invoice-image caller.
 *
 * The value contains only payment/order presentation data. It deliberately
 * excludes credentials, delivery guidance, and mutable Product relations.
 * PNG bytes are produced separately by PaidInvoiceRenderer.
 */
final readonly class PaidInvoicePresentation
{
    /**
     * @param  list<array{product_name: string, quantity: int, unit_price: ?int, subtotal: ?int, quantity_price_tier_minimum?: ?int, product_icon?: ?string, product_custom_emoji_id?: ?string}>  $items
     */
    public function __construct(
        public string $invoiceId,
        public string $buyerIdentifier,
        public CarbonImmutable $paidAt,
        public string $paymentMethod,
        public string $currency,
        public array $items,
        public int $providerFee,
        public int $totalPaid,
        public string $status = 'PAID',
        public string $buyerName = 'Buyer',
        public ?string $itemCurrency = null,
        public ?int $paymentSubtotal = null,
        public ?string $origin = null,
    ) {
        if ($this->invoiceId === '' || $this->buyerIdentifier === '' || $this->paymentMethod === '') {
            throw new \InvalidArgumentException('Paid invoice presentation identifiers and labels are required.');
        }

        if (trim($this->buyerName) === '') {
            throw new \InvalidArgumentException('Paid invoice presentation buyer name cannot be empty.');
        }

        if ($this->status !== 'PAID') {
            throw new \InvalidArgumentException('Paid invoice presentation status must be PAID.');
        }

        if ($this->paymentSubtotal !== null && $this->paymentSubtotal < 0) {
            throw new \InvalidArgumentException('Paid invoice presentation payment subtotal cannot be negative.');
        }
    }

    /**
     * @return list<array{product_name: string, quantity: int, unit_price: ?int, subtotal: ?int, quantity_price_tier_minimum?: ?int, product_icon?: ?string, product_custom_emoji_id?: ?string}>
     */
    public function displayItems(): array
    {
        return array_slice($this->items, 0, 3);
    }

    public function additionalProductCount(): int
    {
        return max(0, count($this->items) - count($this->displayItems()));
    }

    /** @return list<int> */
    public function appliedQuantityPriceTierMinimums(): array
    {
        $minimums = [];
        foreach ($this->items as $item) {
            $minimum = $item['quantity_price_tier_minimum'] ?? null;
            if ($minimum === null || (int) $minimum < 1) {
                continue;
            }

            $minimums[] = (int) $minimum;
        }

        return array_values(array_unique($minimums));
    }

    public function subtotal(): int
    {
        if ($this->paymentSubtotal !== null) {
            return max(0, $this->paymentSubtotal);
        }

        return array_sum(array_map(
            static fn (array $item): int => max(0, (int) $item['subtotal']),
            $this->items,
        ));
    }

    public function paidAtWib(): string
    {
        return $this->paidAt->setTimezone('Asia/Jakarta')->format('d F Y, H:i').' WIB';
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return [
            'order_id' => $this->invoiceId,
            'buyer_telegram_id' => $this->buyerIdentifier,
            'buyer_display' => $this->buyerName,
            'paid_at_wib' => $this->paidAtWib(),
            'payment_method' => $this->paymentMethod,
            'status' => $this->status,
            'currency' => $this->currency,
            'origin' => $this->origin,
            'item_currency' => $this->itemCurrency ?? $this->currency,
            'items' => $this->items,
            'display_items' => $this->displayItems(),
            'additional_product_count' => $this->additionalProductCount(),
            'subtotal' => $this->subtotal(),
            'provider_fee' => max(0, $this->providerFee),
            'total_paid' => max(0, $this->totalPaid),
        ];
    }

    public function __serialize(): array
    {
        throw new LogicException('Paid invoice presentation values cannot be serialized.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        unset($data);
        throw new LogicException('Paid invoice presentation values cannot be unserialized.');
    }

    /** @return array{item_count: int, currency: string, status: string} */
    public function __debugInfo(): array
    {
        return [
            'item_count' => count($this->items),
            'currency' => $this->currency,
            'status' => $this->status,
        ];
    }
}
