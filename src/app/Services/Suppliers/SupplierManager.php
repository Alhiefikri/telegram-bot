<?php

namespace App\Services\Suppliers;

use App\Models\Product;
use App\Models\ProductSupplierSource;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierFulfillment;
use App\Models\SupplierPurchase;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SupplierManager
{
    public function __construct(private readonly AuditLogger $audit, private readonly SupplierAvailability $availability) {}

    public function save(?Supplier $supplier, array $attributes, User $actor, ?string $ip): Supplier
    {
        abort_unless($actor->role?->canManageStoreOperations() === true, 403);
        [$saved, $ids] = DB::transaction(function () use ($supplier, $attributes, $actor, $ip): array {
            $ids = $supplier ? $this->productIds((int) $supplier->id) : [];
            $products = Product::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $connection = $supplier ? Supplier::query()->whereKey($supplier->id)->lockForUpdate()->firstOrFail() : new Supplier;
            if ($supplier && ((int) $connection->version !== (int) $attributes['version'] || array_diff($this->productIds((int) $connection->id), $ids))) {
                throw ValidationException::withMessages(['version' => 'Konfigurasi berubah. Muat ulang sebelum menyimpan.']);
            }
            $fields = ['name', 'provider', 'currency', 'currency_confirmed', 'exchange_rate_idr', 'is_active', 'low_balance_threshold_micros'];
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
            $created = ! $connection->exists;
            if (! $created && array_intersect($changed, ['credential', 'provider', 'currency', 'currency_confirmed', 'exchange_rate_idr'])
                && ((int) $connection->reserved_balance_micros > 0
                    || SupplierFulfillment::query()->where('reserved_supplier_id', $connection->id)->where('reserved_quantity', '>', 0)->exists()
                    || SupplierPurchase::query()->where('supplier_id', $connection->id)->whereIn('state', ['reserved', 'sending', 'ambiguous', 'awaiting_delivery', 'review'])->exists())) {
                throw ValidationException::withMessages(['connection' => 'Komitmen supplier masih aktif. Selesaikan pembelian sebelum mengubah kredensial atau mata uang.']);
            }
            if (! $created && $changed === []) {
                return [$connection, []];
            }
            // Observations expire on every connection change; financial holds remain intact.
            $connection->synced_at = null;
            $connection->sync_expires_at = null;
            $connection->sync_token = null;
            $connection->last_error = null;
            $connection->version = $created ? 1 : (int) $connection->version + 1;
            $connection->save();
            foreach ($products as $product) {
                $product->supplier_available_until = null;
                $product->save();
            }
            $this->audit->log($created ? 'supplier.created' : 'supplier.updated', $connection, null, ['supplier_id' => $connection->id, 'changed_fields' => $changed, 'version' => $connection->version], $actor, $ip);

            return [$connection, $ids];
        }, 5);
        if ($ids !== []) {
            $this->availability->refreshProducts($ids);
        }

        return $saved;
    }

    private function productIds(int $supplierId): array
    {
        return ProductSupplierSource::query()->whereIn('supplier_catalog_item_id', SupplierCatalogItem::query()->where('supplier_id', $supplierId)->select('id'))->distinct()->orderBy('product_id')->pluck('product_id')->map(fn ($id) => (int) $id)->all();
    }
}
