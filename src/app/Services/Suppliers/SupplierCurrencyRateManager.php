<?php

namespace App\Services\Suppliers;

use App\Models\Product;
use App\Models\ProductSupplierSource;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierCurrencyRate;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One authoritative IDR rate for USD/USDT and one for VND.
 *
 * `suppliers.exchange_rate_idr` remains the connection-level cache used by
 * pricing, checkout snapshots and historical purchase rows because changing
 * those read paths would add risk without changing the economics. This service
 * is the only production writer of that cache: a USD change reaches every USD
 * and USDT connection, a VND change reaches every VND connection, and both use
 * the Product -> Supplier lock order.
 *
 * Rate changes are allowed while orders are in flight: checkout and purchase
 * rows already store their own immutable rate snapshot, so an in-flight
 * obligation cannot be repriced by a later global change.
 */
final class SupplierCurrencyRateManager
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SupplierAvailability $availability,
    ) {}

    /**
     * @param  array<string, array{exchange_rate_idr: string|int|float, version: int}>  $rates
     * @return int number of rate rows whose global value or connection cache was repaired
     */
    public function save(array $rates, ?User $actor, ?string $ip, string $source = 'admin'): int
    {
        if (array_diff(array_keys($rates), SupplierExchangeRate::RATE_KEYS) !== []) {
            throw ValidationException::withMessages(['rates' => 'Mata uang kurs tidak didukung.']);
        }
        $rateKeys = array_values(array_intersect(SupplierExchangeRate::RATE_KEYS, array_keys($rates)));
        sort($rateKeys, SORT_STRING);
        if ($rateKeys === []) {
            throw ValidationException::withMessages(['rates' => 'Pilih mata uang yang akan disimpan.']);
        }
        foreach ($rateKeys as $rateKey) {
            $rate = $rates[$rateKey]['exchange_rate_idr'] ?? null;
            $valid = is_int($rate) || is_float($rate) || is_string($rate);
            if (! $valid || ! SupplierExchangeRate::isStorable($rateKey, $rate)) {
                throw ValidationException::withMessages([
                    'rates.'.$rateKey.'.exchange_rate_idr' => SupplierExchangeRate::requirement($rateKey, $rateKey)
                        ?? 'Kurs mata uang tidak wajar.',
                ]);
            }
        }

        $productIds = [];
        $touched = 0;
        DB::transaction(function () use ($rateKeys, $rates, $actor, $ip, $source, &$productIds, &$touched): void {
            $rows = SupplierCurrencyRate::query()->whereIn('currency', $rateKeys)
                ->orderBy('currency')->lockForUpdate()->get()->keyBy('currency');

            foreach ($rateKeys as $rateKey) {
                $stored = $rows->get($rateKey);
                $expected = (int) ($rates[$rateKey]['version'] ?? 0);
                if (($stored === null ? 0 : (int) $stored->version) !== $expected) {
                    throw ValidationException::withMessages(['rates' => 'Kurs berubah di tempat lain. Muat ulang sebelum menyimpan.']);
                }
            }

            $targetCurrencies = collect($rateKeys)
                ->flatMap(fn (string $key): array => SupplierExchangeRate::currenciesForRateKey($key))
                ->unique()->values()->all();
            // A pre-read keeps the documented Product -> Supplier lock order. The
            // locked re-read below still decides every write; this only selects the
            // slice that needs product locking.
            $preRead = Supplier::query()->whereIn('currency', $targetCurrencies)
                ->orderBy('id')->get(['id', 'currency', 'exchange_rate_idr']);
            $affected = [];
            foreach ($rateKeys as $rateKey) {
                $stored = $rows->get($rateKey);
                $target = (string) $rates[$rateKey]['exchange_rate_idr'];
                $globalChange = $stored === null || ! SupplierExchangeRate::equals($stored->exchange_rate_idr, $target);
                $drift = $preRead->whereIn('currency', SupplierExchangeRate::currenciesForRateKey($rateKey))
                    ->contains(fn (Supplier $supplier): bool => ! SupplierExchangeRate::equals($supplier->exchange_rate_idr, $target));
                if ($globalChange || $drift) {
                    $affected[] = $rateKey;
                }
            }
            if ($affected === []) {
                return;
            }

            $affectedCurrencies = collect($affected)
                ->flatMap(fn (string $key): array => SupplierExchangeRate::currenciesForRateKey($key))
                ->unique()->values()->all();
            $supplierIds = $preRead->whereIn('currency', $affectedCurrencies)->pluck('id')->all();
            $productIds = $this->productIds($supplierIds);
            if ($productIds !== []) {
                Product::withTrashed()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get();
            }
            $suppliers = Supplier::query()->whereIn('id', $supplierIds)->orderBy('id')->lockForUpdate()->get();

            foreach ($affected as $rateKey) {
                $stored = $rows->get($rateKey);
                $target = (string) $rates[$rateKey]['exchange_rate_idr'];
                $globalChange = $stored === null || ! SupplierExchangeRate::equals($stored->exchange_rate_idr, $target);
                $rateCurrencies = SupplierExchangeRate::currenciesForRateKey($rateKey);
                $rateSuppliers = $suppliers->whereIn('currency', $rateCurrencies);
                $drifted = $rateSuppliers->filter(
                    fn (Supplier $supplier): bool => ! SupplierExchangeRate::equals($supplier->getAttribute('exchange_rate_idr'), $target),
                );
                if (! $globalChange && $drifted->isEmpty()) {
                    continue;
                }

                $previous = $globalChange && $stored !== null
                    ? (string) $stored->exchange_rate_idr
                    : ($drifted->first()?->getOriginal('exchange_rate_idr') !== null
                        ? (string) $drifted->first()->getOriginal('exchange_rate_idr')
                        : null);

                if ($globalChange) {
                    $rate = $stored ?? new SupplierCurrencyRate(['currency' => $rateKey, 'version' => 0]);
                    $rate->exchange_rate_idr = $target;
                    $rate->version = (int) $rate->version + 1;
                    $rate->save();
                } else {
                    $rate = $stored;
                }

                foreach ($rateSuppliers as $supplier) {
                    if (SupplierExchangeRate::equals($supplier->getAttribute('exchange_rate_idr'), $target)) {
                        continue;
                    }
                    $supplier->exchange_rate_idr = $target;
                    $supplier->sync_token = null;
                    $supplier->last_error = null;
                    $supplier->version = (int) $supplier->version + 1;
                    $supplier->save();
                }
                $this->audit->log('supplier.rate_corrected', $rate, ['exchange_rate_idr' => $previous], [
                    'exchange_rate_idr' => $target,
                    'version' => (int) $rate->version,
                    'command' => $source,
                ], $actor, $ip);
                $touched++;
            }
        }, 5);

        if ($productIds !== []) {
            $this->availability->refreshProducts($productIds);
        }

        return $touched;
    }

    /**
     * Resolve the rate a new/changed connection must use. USD and USDT both
     * resolve to the single USD row. The caller holds the rate row lock before
     * any Product lock, so a concurrent global update cannot insert a stale
     * cache after propagation.
     */
    public function lockedRateFor(string $currency): SupplierCurrencyRate
    {
        $rateKey = SupplierExchangeRate::rateKey($currency);
        $rate = $rateKey === null
            ? null
            : SupplierCurrencyRate::query()->whereKey($rateKey)->lockForUpdate()->first();
        if ($rate === null) {
            throw ValidationException::withMessages([
                'currency' => 'Atur kurs global '.SupplierExchangeRate::rateLabel($rateKey ?? $currency).' terlebih dahulu di kartu Kurs mata uang supplier.',
            ]);
        }

        return $rate;
    }

    /** @param list<int> $supplierIds @return list<int> */
    private function productIds(array $supplierIds): array
    {
        if ($supplierIds === []) {
            return [];
        }

        return ProductSupplierSource::query()
            ->whereIn('supplier_catalog_item_id', SupplierCatalogItem::query()
                ->whereIn('supplier_id', $supplierIds)->select('id'))
            ->distinct()->orderBy('product_id')->pluck('product_id')
            ->map(static fn ($id): int => (int) $id)->all();
    }
}
