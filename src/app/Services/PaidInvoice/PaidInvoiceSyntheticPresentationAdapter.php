<?php

namespace App\Services\PaidInvoice;

use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Builds an in-memory public-safe invoice for Fake Sales Proof only.
 *
 * It reads an already-selected available Product and never creates or loads an
 * Order, Payment, OrderItem, inventory record, or durable effect.
 */
final class PaidInvoiceSyntheticPresentationAdapter
{
    private const TIMEZONE = 'Asia/Jakarta';

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

    public function present(Product $product, ?CarbonImmutable $paidAt = null): PaidInvoicePresentation
    {
        $paidAt ??= CarbonImmutable::now(self::TIMEZONE);
        $paidAt = $paidAt->setTimezone(self::TIMEZONE);
        $currency = strtoupper(trim((string) ($product->currency ?: 'IDR'))) ?: 'IDR';
        $amount = $currency === 'USDT'
            ? (int) ($product->usdt_price_amount ?? $product->price_amount)
            : (int) $product->price_amount;
        $amount = max(0, $amount);
        $invoiceId = $this->invoiceIdentifier($paidAt);

        return new PaidInvoicePresentation(
            invoiceId: $invoiceId,
            buyerIdentifier: $this->buyerIdentifier($product, $paidAt),
            paidAt: $paidAt,
            paymentMethod: 'QRIS',
            currency: $currency,
            items: [[
                'product_name' => ((string) $product->name) !== '' ? (string) $product->name : 'Digital product',
                'quantity' => 1,
                'unit_price' => $amount,
                'subtotal' => $amount,
            ]],
            providerFee: 0,
            totalPaid: $amount,
            buyerName: $this->buyerName($product, $invoiceId),
        );
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
