<?php

namespace App\Services\PaidInvoice;

use App\Models\Product;
use App\Services\Pricing\ProductQuantityPricing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Builds an in-memory public-safe invoice for Fake Sales Proof only.
 *
 * It reads a selected Product and never creates or loads an
 * Order, Payment, OrderItem, inventory record, or durable effect.
 */
final class PaidInvoiceSyntheticPresentationAdapter
{
    private const TIMEZONE = 'Asia/Jakarta';

    public function __construct(
        private readonly ProductQuantityPricing $pricing,
    ) {}

    /** @var list<string> */
    private const BUYER_NAMES = [
        'Andi Saputra',
        'Budi Santoso',
        'Citra Lestari',
        'Dimas Pratama',
        'Eka Putri',
        'Fajar Ramadhan',
        'Gilang Maulana',
        'Hana Salsabila',
        'Intan Permata',
        'Joko Susilo',
        'Kevin Wijaya',
        'Larasati Putri',
        'Maya Anggraini',
        'Nadia Aulia',
        'Rizky Kurniawan',
        'Siti Rahma',
    ];

    public function present(Product $product, ?CarbonImmutable $paidAt = null, int $quantity = 1): PaidInvoicePresentation
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Synthetic invoice quantity must be positive.');
        }

        $paidAt ??= CarbonImmutable::now(self::TIMEZONE);
        $paidAt = $paidAt->setTimezone(self::TIMEZONE);
        $currency = strtoupper(trim((string) ($product->currency ?: 'IDR'))) ?: 'IDR';
        $quote = $this->pricing->quote($product, $quantity);
        $amount = $currency === 'USDT'
            ? (int) ($quote->effectiveUsdtUnitAmount ?? $quote->effectiveIdrUnitAmount)
            : $quote->effectiveIdrUnitAmount;
        $subtotal = $currency === 'USDT'
            ? (int) ($quote->usdtSubtotal ?? $quote->idrSubtotal)
            : $quote->idrSubtotal;
        $fee = $this->feeFor($subtotal);
        $invoiceId = $this->invoiceIdentifier($paidAt);

        return new PaidInvoicePresentation(
            invoiceId: $invoiceId,
            buyerIdentifier: $this->buyerIdentifier($product, $paidAt),
            paidAt: $paidAt,
            paymentMethod: 'QRIS',
            currency: $currency,
            items: [[
                'product_name' => ((string) $product->name) !== '' ? (string) $product->name : 'Digital product',
                'quantity' => $quantity,
                'quantity_price_tier_minimum' => $quote->appliedTierMinimum,
                'unit_price' => max(0, $amount),
                'subtotal' => max(0, $subtotal),
            ]],
            providerFee: $fee,
            totalPaid: $subtotal + $fee,
            buyerName: $this->buyerName($product, $invoiceId),
        );
    }

    private function feeFor(int $subtotal): int
    {
        $subtotal = max(0, $subtotal);

        return $subtotal === 0 ? 0 : intdiv($subtotal, 100) + ($subtotal % 100 === 0 ? 0 : 1);
    }

    private function invoiceIdentifier(CarbonImmutable $paidAt): string
    {
        return 'INV-'.$paidAt->format('Ymd').'-'.(string) Str::ulid();
    }

    private function buyerIdentifier(Product $product, CarbonImmutable $paidAt): string
    {
        $seed = implode('|', [
            'sales-proof-buyer',
            (string) $product->getKey(),
            $paidAt->toIso8601String(),
        ]);
        $number = (string) (hexdec(substr(hash('sha256', $seed), 0, 12)) % 9_000_000_000 + 1_000_000_000);

        return substr($number, 0, 3).'*****'.substr($number, -2);
    }

    private function buyerName(Product $product, string $invoiceId): string
    {
        $seed = hash('sha256', implode('|', [
            'sales-proof-buyer-name',
            (string) $product->getKey(),
            $invoiceId,
        ]));
        $name = self::BUYER_NAMES[hexdec(substr($seed, 0, 8)) % count(self::BUYER_NAMES)];

        return mb_substr($name, 0, 3, 'UTF-8').'***';
    }
}
