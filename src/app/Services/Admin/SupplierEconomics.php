<?php

namespace App\Services\Admin;

use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Services\Suppliers\SupplierMoney;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Read-only presentation math for the private supplier surface: safe IDR cost
 * conversion, margin, and human-friendly formatting. It never writes state and
 * never changes any pricing/fulfillment rule.
 */
final class SupplierEconomics
{
    public function convertMicrosToIdr(?int $micros, ?Supplier $supplier): ?int
    {
        if ($micros === null || ! $supplier instanceof Supplier || (int) $supplier->exchange_rate_idr <= 0) {
            return null;
        }

        try {
            return SupplierMoney::costIdr($micros, (int) $supplier->exchange_rate_idr);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function costIdr(?SupplierCatalogItem $item, ?Supplier $supplier): ?int
    {
        if (! $item instanceof SupplierCatalogItem || ! $supplier instanceof Supplier) {
            return null;
        }
        if ($item->unit_price_micros === null) {
            return null;
        }
        if ($item->currency !== null && $item->currency !== $supplier->currency) {
            return null;
        }

        return $this->convertMicrosToIdr((int) $item->unit_price_micros, $supplier);
    }

    /**
     * @return array{profit_idr: int, profit_basis_points: int|null}|null
     */
    public function margin(?int $costIdr, int $retailIdr): ?array
    {
        if ($costIdr === null) {
            return null;
        }

        $profit = $retailIdr - $costIdr;

        return [
            'profit_idr' => $profit,
            'profit_basis_points' => $costIdr > 0 ? (int) round($profit * 10000 / $costIdr) : null,
        ];
    }

    public function formatIdr(?int $amount): ?string
    {
        return $amount === null ? null : 'Rp'.number_format($amount, 0, ',', '.');
    }

    public function formatCurrency(?int $micros, string $currency): ?string
    {
        if ($micros === null) {
            return null;
        }

        try {
            $decimal = SupplierMoney::decimal($micros);
        } catch (InvalidArgumentException) {
            return null;
        }

        [$whole, $fraction] = explode('.', $decimal);
        $fraction = rtrim($fraction, '0');
        $value = $fraction === '' ? $whole : $whole.','.$fraction;

        return $value.' '.$currency;
    }

    public function formatPercent(?int $basisPoints): ?string
    {
        if ($basisPoints === null) {
            return null;
        }

        $percent = $basisPoints / 100;
        $formatted = rtrim(rtrim(number_format($percent, 2, ',', '.'), '0'), ',');

        return $formatted.'%';
    }

    public function humanAge(?DateTimeInterface $moment): string
    {
        if ($moment === null) {
            return 'Belum pernah';
        }

        $seconds = time() - $moment->getTimestamp();
        if ($seconds < 60) {
            return 'Baru saja';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60).' menit lalu';
        }
        if ($seconds < 86400) {
            return intdiv($seconds, 3600).' jam lalu';
        }

        return intdiv($seconds, 86400).' hari lalu';
    }
}
