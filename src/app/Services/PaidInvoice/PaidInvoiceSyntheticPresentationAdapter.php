<?php

namespace App\Services\PaidInvoice;

use App\Models\Product;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Builds an in-memory public-safe invoice for Fake Sales Proof only.
 *
 * It reads an already-selected available Product and never creates or loads an
 * Order, Payment, OrderItem, inventory record, or durable effect.
 */
final class PaidInvoiceSyntheticPresentationAdapter
{
    private const TIMEZONE = 'Asia/Jakarta';

    public function present(Product $product, ?CarbonImmutable $paidAt = null): PaidInvoicePresentation
    {
        $paidAt ??= CarbonImmutable::now(self::TIMEZONE);
        $paidAt = $paidAt->setTimezone(self::TIMEZONE);
        $currency = strtoupper(trim((string) ($product->currency ?: 'IDR'))) ?: 'IDR';
        $amount = $currency === 'USDT'
            ? (int) ($product->usdt_price_amount ?? $product->price_amount)
            : (int) $product->price_amount;
        $amount = max(0, $amount);

        return new PaidInvoicePresentation(
            invoiceId: $this->syntheticInvoiceIdentifier($product, $paidAt),
            buyerIdentifier: 'FAKE***',
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
        );
    }

    private function syntheticInvoiceIdentifier(Product $product, CarbonImmutable $paidAt): string
    {
        try {
            $suffix = strtoupper(bin2hex(random_bytes(3)));
        } catch (\Throwable $exception) {
            throw new RuntimeException('Synthetic paid invoice identifier could not be generated.', previous: $exception);
        }

        $timestamp = strtoupper(base_convert((string) $paidAt->getTimestamp(), 10, 36));
        $productId = trim((string) $product->getKey());
        $productId = preg_replace('/[^A-Za-z0-9_-]+/', '-', $productId) ?: 'PRODUCT';
        $productId = trim($productId, '-_') ?: 'PRODUCT';

        return 'FAKE-'.$timestamp.'-'.mb_substr($productId, 0, 12).'-'.$suffix;
    }
}
