<?php

namespace App\Services\Suppliers;

use App\Models\Product;
use App\Models\ProductSupplierSource;
use App\Models\Supplier;
use App\Models\SupplierAccountUnit;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierCurrencyRate;
use App\Models\SupplierDeposit;
use App\Models\SupplierFulfillment;
use App\Models\SupplierPurchase;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class SupplierManager
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SupplierAvailability $availability,
        private readonly SupplierCurrencyRateManager $currencyRates,
    ) {}

    public function save(?Supplier $supplier, array $attributes, User $actor, ?string $ip): Supplier
    {
        abort_unless($actor->role?->canManageStoreOperations() === true, 403);
        [$saved, $ids] = DB::transaction(function () use ($supplier, $attributes, $actor, $ip): array {
            $ids = $supplier ? $this->productIds((int) $supplier->id) : [];
            // The global currency row is the source of truth and is locked before
            // Product rows so a concurrent rate update cannot leave a stale cache.
            $currencyRate = $this->currencyRates->lockedRateFor((string) $attributes['currency']);
            $products = Product::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $connection = $supplier ? Supplier::query()->whereKey($supplier->id)->lockForUpdate()->firstOrFail() : new Supplier;
            if ($supplier && ((int) $connection->version !== (int) $attributes['version'] || array_diff($this->productIds((int) $connection->id), $ids))) {
                throw ValidationException::withMessages(['version' => 'Konfigurasi berubah. Muat ulang sebelum menyimpan.']);
            }
            $fields = ['name', 'provider', 'currency', 'currency_confirmed', 'is_active', 'low_balance_threshold_micros'];
            $changed = [];
            foreach ($fields as $field) {
                $value = $attributes[$field];
                if ($connection->getAttribute($field) != $value) {
                    $connection->setAttribute($field, $value);
                    $changed[] = $field;
                }
            }
            $key = $attributes['api_key'] ?? null;
            if (is_string($key) && $key !== '' && $key !== $connection->api_key) {
                $connection->api_key = $key;
                $changed[] = 'credential';
            }
            if (! SupplierExchangeRate::equals($connection->getAttribute('exchange_rate_idr'), $currencyRate->exchange_rate_idr)) {
                $connection->exchange_rate_idr = $currencyRate->exchange_rate_idr;
                $changed[] = 'exchange_rate_idr';
            }
            $created = ! $connection->exists;
            if (! $created && array_intersect($changed, ['provider', 'currency'])
                && SupplierAccountUnit::query()->where('supplier_id', $connection->id)->exists()) {
                throw ValidationException::withMessages(['connection' => 'Riwayat akun terikat pada koneksi ini. Buat koneksi baru untuk akun atau provider lain.']);
            }
            $protectedChanges = ['credential', 'provider', 'currency', 'currency_confirmed'];
            $this->guardOpenCommitments($connection, $changed, $protectedChanges, $created);
            if (! $created && $changed === []) {
                return [$connection, []];
            }
            // Observations expire when the facts behind them change. A rate-only
            // correction leaves the provider's catalog rows, wallet and stock
            // untouched, so its still valid observation is kept and the
            // after-commit refresh recomputes capacity at the new rate.
            $rateOnly = ! $created && $changed === ['exchange_rate_idr'];
            if (! $rateOnly) {
                $connection->synced_at = null;
                $connection->sync_expires_at = null;
            }
            $connection->sync_token = null;
            $connection->last_error = null;
            $connection->version = $created ? 1 : (int) $connection->version + 1;
            $connection->save();
            if (! $rateOnly) {
                foreach ($products as $product) {
                    $product->supplier_available_until = null;
                    $product->save();
                }
            }
            $this->audit->log($created ? 'supplier.created' : 'supplier.updated', $connection, null, ['supplier_id' => $connection->id, 'changed_fields' => $changed, 'version' => $connection->version], $actor, $ip);

            return [$connection, $ids];
        }, 5);
        if ($ids !== []) {
            $this->availability->refreshProducts($ids);
        }

        return $saved;
    }

    public function delete(Supplier $supplier, int $version, User $actor, ?string $ip): void
    {
        abort_unless($actor->role?->canManageStoreOperations() === true, 403);
        DB::transaction(function () use ($supplier, $version, $actor, $ip): void {
            $connection = Supplier::query()->whereKey($supplier->id)->lockForUpdate()->firstOrFail();
            if ($connection->version !== $version) {
                throw ValidationException::withMessages(['version' => 'Konfigurasi supplier berubah. Muat ulang sebelum menghapus.']);
            }
            if ($connection->sync_token !== null && $connection->sync_lease_expires_at?->isFuture()) {
                throw ValidationException::withMessages(['connection' => 'Sinkron supplier masih berjalan. Tunggu sampai selesai sebelum menghapus.']);
            }
            $catalog = SupplierCatalogItem::query()->where('supplier_id', $connection->id)->orderBy('id')->lockForUpdate()->get(['id', 'reserved_quantity']);
            $catalogIds = $catalog->pluck('id')->all();
            if ($connection->reserved_balance_micros > 0 || $catalog->contains(fn ($item) => $item->reserved_quantity > 0)) {
                throw ValidationException::withMessages(['connection' => 'Supplier masih memiliki saldo atau stok tertahan. Selesaikan reservasi terlebih dahulu.']);
            }
            if (ProductSupplierSource::query()->whereIn('supplier_catalog_item_id', $catalogIds)->exists()) {
                throw ValidationException::withMessages(['connection' => 'Supplier masih dipetakan ke produk. Lepas pemetaan melalui workspace produk sebelum menghapus.']);
            }
            $historyMessage = 'Supplier memiliki riwayat transaksi atau pemenuhan dan tidak dapat dihapus. Nonaktifkan koneksi untuk menghentikan pembelian baru.';
            if (SupplierPurchase::query()->where('supplier_id', $connection->id)->orWhereIn('supplier_catalog_item_id', $catalogIds)->exists()
                || SupplierFulfillment::query()->where('reserved_supplier_id', $connection->id)->orWhereIn('reserved_catalog_item_id', $catalogIds)->exists()
                || SupplierAccountUnit::query()->where('supplier_id', $connection->id)->exists()
                || SupplierDeposit::query()->where('supplier_id', $connection->id)->exists()
                || DB::table('supplier_webhook_events')->where('supplier_id', $connection->id)->exists()) {
                throw ValidationException::withMessages(['connection' => $historyMessage]);
            }
            // Fallback sources are encrypted history, not foreign keys; keep those connections too.
            if ($catalogIds !== []) {
                foreach (SupplierFulfillment::query()->select(['id', 'sources_snapshot'])->lazyById(100) as $fulfillment) {
                    try {
                        $snapshot = $fulfillment->sources_snapshot;
                    } catch (DecryptException|JsonException) {
                        throw ValidationException::withMessages(['connection' => 'Riwayat pemenuhan tidak dapat dibaca. Pulihkan riwayat sebelum menghapus supplier.']);
                    }
                    if (! is_array($snapshot) || ! array_is_list($snapshot)) {
                        throw ValidationException::withMessages(['connection' => 'Riwayat pemenuhan tidak dapat dibaca. Pulihkan riwayat sebelum menghapus supplier.']);
                    }
                    foreach ($snapshot as $candidate) {
                        if (! is_array($candidate) || ! isset($candidate['catalog_item_id'])
                            || ! (is_int($candidate['catalog_item_id']) || (is_string($candidate['catalog_item_id']) && ctype_digit($candidate['catalog_item_id'])))) {
                            throw ValidationException::withMessages(['connection' => 'Riwayat pemenuhan tidak dapat dibaca. Pulihkan riwayat sebelum menghapus supplier.']);
                        }
                        if (in_array((int) $candidate['catalog_item_id'], $catalogIds, true)) {
                            throw ValidationException::withMessages(['connection' => $historyMessage]);
                        }
                    }
                }
            }
            $this->audit->log('supplier.deleted', $connection, ['supplier_id' => $connection->id, 'provider' => $connection->provider, 'version' => $connection->version], null, $actor, $ip);
            SupplierCatalogItem::query()->where('supplier_id', $connection->id)->delete();
            $connection->delete();
        }, 5);
    }

    /**
     * Operator correction used by `suppliers:rate-audit --fix`. The correction
     * applies to the connection currency's global rate so every connection of
     * that currency shares the same source. Historical purchase snapshots are
     * never rewritten.
     *
     * @return bool true when the global rate actually changed
     *
     * @throws InvalidArgumentException when the replacement is outside the currency band
     * @throws RuntimeException when the rate changed concurrently
     */
    public function correctRate(Supplier $supplier, string $rate, ?User $actor, ?string $ip): bool
    {
        $rateKey = SupplierExchangeRate::rateKey($supplier->currency);
        if ($rateKey === null || ! SupplierExchangeRate::isStorable($rateKey, $rate)) {
            throw new InvalidArgumentException('Kurs pengganti berada di luar rentang wajar untuk mata uang koneksi ini.');
        }
        $current = SupplierCurrencyRate::query()->find($rateKey);
        try {
            return $this->currencyRates->save([
                $rateKey => ['exchange_rate_idr' => $rate, 'version' => $current?->version ?? 0],
            ], $actor, $ip, 'suppliers:rate-audit') > 0;
        } catch (ValidationException $exception) {
            throw new RuntimeException((string) $exception->validator->errors()->first(), previous: $exception);
        }
    }

    /**
     * Changing credentials, currency or provider is refused while money is still moving.
     *
     * @throws ValidationException
     */
    private function guardOpenCommitments(Supplier $connection, array $changed, array $protectedChanges, bool $created): void
    {
        if ($created || array_intersect($changed, $protectedChanges) === []) {
            return;
        }
        if (SupplierDeposit::query()->where('supplier_id', $connection->id)->whereIn('state', ['requested', 'sending', 'unknown', 'pending', 'cancel_requested', 'review'])->exists()) {
            throw ValidationException::withMessages(['connection' => 'Deposit supplier masih belum selesai. Pulihkan terlebih dahulu.']);
        }
        if ((int) $connection->reserved_balance_micros > 0
            || SupplierFulfillment::query()->where('reserved_supplier_id', $connection->id)->where('reserved_quantity', '>', 0)->exists()
            || SupplierPurchase::query()->where('supplier_id', $connection->id)->whereIn('state', ['reserved', 'sending', 'ambiguous', 'awaiting_delivery', 'review'])->exists()) {
            throw ValidationException::withMessages(['connection' => 'Komitmen supplier masih aktif (pembelian atau reservasi pesanan berjalan). Selesaikan pembelian, atau tunggu pesanan kedaluwarsa/dibatalkan, sebelum mengubah kredensial atau mata uang.']);
        }
    }

    private function productIds(int $supplierId): array
    {
        return ProductSupplierSource::query()->whereIn('supplier_catalog_item_id', SupplierCatalogItem::query()->where('supplier_id', $supplierId)->select('id'))->distinct()->orderBy('product_id')->pluck('product_id')->map(fn ($id) => (int) $id)->all();
    }
}
