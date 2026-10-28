<?php

namespace App\Services\Suppliers;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierHealthEvent;
use App\Services\Pricing\ProductQuantityPricing;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SupplierPriceChangeNotificationService
{
    private const MESSAGE_BYTES = 3800;

    private const HEADER = '<b>Perubahan harga supplier</b>'."\n\n";

    public function __construct(
        private readonly SupplierPricing $pricing,
        private readonly ProductQuantityPricing $retail,
        private readonly TelegramOutboundEffectService $effects,
        private readonly TelegramOutboundEffectDispatcher $dispatcher,
    ) {}

    /** Caller owns sync locks; products have already passed through refreshLocked. */
    public function record(Supplier $supplier, Collection $products, Collection $sources, Collection $before, Collection $after, array $previousSupplierStock): void
    {
        $target = SupplierHealthService::adminTarget();
        if ($target === null || $before->isEmpty()) {
            return;
        }
        $current = $after->keyBy('id');
        $changes = [];
        foreach ($before as $old) {
            $new = $current->get($old->id);
            if ($new === null || $old->synced_at === null || $old->unit_price_micros === null || $new->unit_price_micros === null) {
                continue;
            }
            $baseChanged = $old->unit_price_micros !== $new->unit_price_micros;
            $scheduleChanged = $old->price_tiers !== $new->price_tiers || $old->bulk_discounts !== $new->bulk_discounts;
            if (! $baseChanged && ! $scheduleChanged) {
                continue;
            }
            $example = $scheduleChanged ? $this->changedQuantity($old, $new) : null;
            if ($baseChanged || $example !== null) {
                $changes[$old->id] = [$old, $new, $example];
            }
        }
        if ($changes === []) {
            return;
        }
        $productMap = $products->keyBy('id');
        $rows = [];
        foreach ($sources as $source) {
            $change = $changes[$source->supplier_catalog_item_id] ?? null;
            $product = $productMap->get($source->product_id);
            if ($change === null || ! $source->is_active || $product === null || ! $product->is_active || $product->trashed()) {
                continue;
            }
            [$old, $new, $example] = $change;
            $rows[] = $this->row($supplier, $product, $old, $new, $example, $previousSupplierStock[$product->id] ?? 0);
        }
        if ($rows === []) {
            return;
        }
        array_unshift($rows, 'Supplier #'.$supplier->id.': '.$supplier->name);
        $rows[] = 'Kebijakan harga tidak diubah oleh notifikasi ini. Kebijakan otomatis yang sudah aktif tetap diterapkan saat sinkronisasi. Profit qty 1 bukan jaminan kelayakan semua jumlah, tier retail, atau harga reseller.';
        foreach ($this->messages($rows) as $message) {
            $event = SupplierHealthEvent::create(['supplier_id' => $supplier->id, 'message' => $message, 'created_at' => now()]);
            $effect = $this->effects->recordSupplierHealth($event, $target);
            DB::afterCommit(fn () => $this->dispatcher->dispatch($effect));
        }
    }

    private function row(Supplier $supplier, Product $product, SupplierCatalogItem $old, SupplierCatalogItem $new, ?array $example, int $previousSupplierStock): string
    {
        $currency = $supplier->currency;
        $lines = ['Produk #'.$product->id.': '.$product->name, 'SKU #'.$new->id.': '.$new->external_id.' ('.$new->name.')'];
        if ($old->unit_price_micros !== $new->unit_price_micros) {
            $direction = $new->unit_price_micros > $old->unit_price_micros ? 'naik' : 'turun';
            $lines[] = 'Harga dasar '.$direction.': '.SupplierMoney::decimal($old->unit_price_micros).' -> '.SupplierMoney::decimal($new->unit_price_micros).' '.$currency.'/unit';
        }
        if ($example !== null) {
            [$quantity, $oldCost, $newCost] = $example;
            $direction = $newCost > $oldCost ? 'naik' : 'turun';
            $lines[] = 'Tier/diskon efektif berubah; contoh biaya total qty '.$quantity.' '.$direction.': '.SupplierMoney::decimal($oldCost).' -> '.SupplierMoney::decimal($newCost).' '.$currency;
        }
        try {
            $costMicros = $this->pricing->costMicros($new, 1);
            $oldCostMicros = $this->pricing->costMicros($old, 1);
            if ($costMicros === null || $oldCostMicros === null) {
                throw new InvalidArgumentException('Invalid quantity-one cost.');
            }
            $costIdr = SupplierMoney::costIdr($costMicros, $supplier->exchange_rate_idr);
            $oldCostIdr = SupplierMoney::costIdr($oldCostMicros, $supplier->exchange_rate_idr);
            $quote = $this->retail->quote($product, 1);
            $profit = $quote->effectiveIdrUnitAmount - $costIdr;
            $lines[] = 'Biaya supplier qty 1: '.SupplierMoney::decimal($oldCostMicros).' -> '.SupplierMoney::decimal($costMicros).' '.$currency.' ('.Money::rupiah($oldCostIdr).' -> '.Money::rupiah($costIdr).')';
            $lines[] = 'Retail normal qty 1 saat ini: '.Money::rupiah($quote->effectiveIdrUnitAmount);
            $lines[] = 'Profit qty 1 (retail normal - biaya source ini): '.Money::rupiah($profit);
            $policy = $product->supplierPricePolicy;
            $lines[] = $policy === null
                ? 'Minimum profit/unit: belum disetel; kebijakan harga belum disetel.'
                : 'Minimum profit/unit: '.Money::rupiah($policy->minimum_profit_idr).'; profit qty 1 '.($profit >= $policy->minimum_profit_idr ? 'memenuhi minimum' : 'di bawah minimum').'; mode harga: '.$policy->mode;
        } catch (InvalidArgumentException) {
            $lines[] = 'Biaya/profit qty 1: tidak dapat dihitung aman dengan harga/kurs saat ini; periksa konfigurasi.';
        }
        $local = $product->localAvailableStock();
        $total = $product->availableStock();
        $lines[] = 'Ketersediaan total (lokal + semua source supplier): '.($local + $previousSupplierStock).' -> '.$total.' unit; '.($total > 0 ? 'masih tersedia' : 'tidak tersedia').'. Lokal: '.$local.'; supplier layak saat ini: '.$product->supplierAvailableStock().'.';

        return implode("\n", $lines);
    }

    /** Find a real effective change within the checkout-supported quantity range. */
    private function changedQuantity(SupplierCatalogItem $old, SupplierCatalogItem $new): ?array
    {
        $boundaries = [1, 10001];
        foreach ([$old, $new] as $item) {
            foreach ($item->price_tiers ?? [] as $tier) {
                $boundaries[] = (int) $tier['min'];
                if ($tier['max'] !== null) {
                    $boundaries[] = (int) $tier['max'] + 1;
                }
            }
            foreach ($item->bulk_discounts ?? [] as $discount) {
                $boundaries[] = (int) $discount['min'];
            }
        }
        $boundaries = array_values(array_unique(array_filter($boundaries, fn (int $q): bool => $q >= 1 && $q <= 10001)));
        sort($boundaries, SORT_NUMERIC);
        for ($i = 0; $i < count($boundaries) - 1; $i++) {
            $start = $boundaries[$i];
            $end = $boundaries[$i + 1] - 1;
            if ($this->effectiveRate($old, $start) === $this->effectiveRate($new, $start)) {
                continue;
            }
            for ($quantity = $start; $quantity <= $end; $quantity++) {
                try {
                    $oldCost = $this->pricing->costMicros($old, $quantity);
                    $newCost = $this->pricing->costMicros($new, $quantity);
                    if ($oldCost !== null && $newCost !== null && $oldCost !== $newCost) {
                        return [$quantity, $oldCost, $newCost];
                    }
                } catch (InvalidArgumentException) {
                    break;
                }
            }
        }

        return null;
    }

    /** Canonical exact rate before total-cost ceiling, without overflowing a product. */
    private function effectiveRate(SupplierCatalogItem $item, int $quantity): array
    {
        $unit = $item->unit_price_micros;
        foreach ($item->price_tiers ?? [] as $tier) {
            if ($quantity >= (int) $tier['min'] && ($tier['max'] === null || $quantity <= (int) $tier['max'])) {
                $unit = $tier['unit_price_micros'];
            }
        }
        $discount = 0;
        foreach ($item->bulk_discounts ?? [] as $row) {
            if ($quantity >= (int) $row['min']) {
                $discount = max($discount, (int) $row['percent_basis_points']);
            }
        }
        $remaining = 10000 - $discount;
        if ($unit === 0 || $remaining === 0) {
            return [0, 0];
        }
        $fraction = ($unit % 10000) * $remaining;

        return [intdiv($unit, 10000) * $remaining + intdiv($fraction, 10000), $fraction % 10000];
    }

    /** Split escaped text, never entities/tags; byte bound is stricter than Telegram's UTF-16 bound. */
    private function messages(array $rows): array
    {
        $messages = [];
        $message = self::HEADER;
        foreach ($rows as $row) {
            $escaped = htmlspecialchars($row, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."\n\n";
            if (strlen($message) + strlen($escaped) <= self::MESSAGE_BYTES) {
                $message .= $escaped;

                continue;
            }
            if ($message !== self::HEADER) {
                $messages[] = $message;
                $message = self::HEADER;
            }
            if (strlen(self::HEADER) + strlen($escaped) <= self::MESSAGE_BYTES) {
                $message .= $escaped;

                continue;
            }
            foreach (mb_str_split($row."\n\n") as $character) {
                $escapedCharacter = htmlspecialchars($character, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                if (strlen($message) + strlen($escapedCharacter) > self::MESSAGE_BYTES) {
                    $messages[] = $message;
                    $message = self::HEADER;
                }
                $message .= $escapedCharacter;
            }
        }
        if ($message !== self::HEADER) {
            $messages[] = $message;
        }

        return $messages;
    }
}
