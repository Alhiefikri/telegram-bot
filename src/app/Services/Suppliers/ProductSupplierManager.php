<?php

namespace App\Services\Suppliers;

use App\Models\Product;
use App\Models\ProductSupplierSource;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierFulfillment;
use App\Models\SupplierPricePolicy;
use App\Models\SupplierPurchase;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Pricing\ProductQuantityPricing;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

final class ProductSupplierManager
{
    public function __construct(private readonly AuditLogger $audit, private readonly SupplierAvailability $availability, private readonly SupplierPricing $pricing) {}

    public function import(SupplierCatalogItem $item, array $input, User $actor, ?string $ip): Product
    {
        abort_unless($actor->role?->canManageStoreOperations() === true, 403);

        return DB::transaction(function () use ($item, $input, $actor, $ip): Product {
            $productIds = ProductSupplierSource::query()->where('supplier_catalog_item_id', $item->id)->orderBy('product_id')->pluck('product_id');
            Product::query()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get();
            $supplier = Supplier::query()->whereKey($item->supplier_id)->lockForUpdate()->firstOrFail();
            $catalog = SupplierCatalogItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $existing = ProductSupplierSource::query()->where('supplier_catalog_item_id', $catalog->id)->orderBy('product_id')->first();
            if ($existing) {
                return Product::query()->findOrFail($existing->product_id);
            }
            $this->version((int) $supplier->version, (int) $input['supplier_version']);
            // Supplier text remains private. The operator supplies store-owned draft copy.
            $product = new Product(['name' => $input['name'], 'description' => $input['description'] ?? '', 'price_amount' => (int) $input['price_amount'], 'currency' => 'IDR', 'is_active' => false]);
            $product->stock = 0;
            $product->reserved_stock = 0;
            $product->save();
            $source = new ProductSupplierSource;
            $source->forceFill(['product_id' => $product->id, 'supplier_catalog_item_id' => $catalog->id, 'priority' => 100, 'is_active' => false, 'version' => 1])->save();
            $policy = new SupplierPricePolicy;
            $policy->forceFill(['product_id' => $product->id, 'mode' => 'manual', 'markup_basis_points' => 0, 'profit_idr' => 0, 'minimum_profit_idr' => 0, 'rounding_idr' => 1, 'version' => 1])->save();
            $supplier->version = (int) $supplier->version + 1;
            $supplier->save();
            $this->audit->log('supplier.product_imported', $product, null, ['catalog_item_id' => $catalog->id, 'source_id' => $source->id], $actor, $ip);

            return $product;
        }, 5);
    }

    public function map(Product $product, SupplierCatalogItem $item, array $input, User $actor, ?string $ip): ProductSupplierSource
    {
        abort_unless($actor->role?->canManageStoreOperations() === true, 403);
        $source = DB::transaction(function () use ($product, $item, $input, $actor, $ip): ProductSupplierSource {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $supplier = Supplier::query()->whereKey($item->supplier_id)->lockForUpdate()->firstOrFail();
            $catalog = SupplierCatalogItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $this->version((int) $supplier->version, (int) $input['supplier_version']);
            if (! ($input['equivalent_confirmed'] ?? false)) {
                throw ValidationException::withMessages(['equivalent_confirmed' => 'Konfirmasi produk ekuivalen wajib.']);
            }
            if (($input['is_active'] ?? false) && ($catalog->requires_input || ! $catalog->orderable)) {
                throw ValidationException::withMessages(['is_active' => 'SKU ini tidak mendukung pengiriman instan.']);
            }
            $source = ProductSupplierSource::query()->where('product_id', $locked->id)->where('supplier_catalog_item_id', $catalog->id)->first() ?? new ProductSupplierSource;
            $this->version($source->exists ? (int) $source->version : 0, (int) $input['version']);
            $created = ! $source->exists;
            $source->forceFill(['product_id' => $locked->id, 'supplier_catalog_item_id' => $catalog->id, 'priority' => (int) $input['priority'], 'is_active' => (bool) $input['is_active']]);
            if (! $created && ! $source->isDirty()) {
                return $source;
            }
            $source->version = $created ? 1 : (int) $source->version + 1;
            $source->save();
            if (! SupplierPricePolicy::query()->where('product_id', $locked->id)->exists()) {
                $policy = new SupplierPricePolicy;
                $policy->forceFill(['product_id' => $locked->id, 'mode' => 'manual', 'markup_basis_points' => 0, 'profit_idr' => 0, 'minimum_profit_idr' => 0, 'rounding_idr' => 1, 'version' => 1])->save();
            }
            $supplier->version = (int) $supplier->version + 1;
            $supplier->save();
            $locked->supplier_available_until = null;
            $locked->save();
            $this->audit->log('supplier.source_saved', $source, null, ['product_id' => $locked->id, 'catalog_item_id' => $catalog->id, 'priority' => $source->priority, 'is_active' => $source->is_active, 'version' => $source->version], $actor, $ip);

            return $source;
        }, 5);
        $this->availability->refreshProducts([(int) $product->id]);

        return $source;
    }

    /** Move a mapped source one rank up or down; priorities are normalized to 10, 20, 30, ... */
    public function move(Product $product, SupplierCatalogItem $item, array $input, User $actor, ?string $ip): void
    {
        abort_unless($actor->role?->canManageStoreOperations() === true, 403);
        DB::transaction(function () use ($product, $item, $input, $actor, $ip): void {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $supplier = Supplier::query()->whereKey($item->supplier_id)->lockForUpdate()->firstOrFail();
            $catalog = SupplierCatalogItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $this->version((int) $supplier->version, (int) $input['supplier_version']);
            $sources = ProductSupplierSource::query()->where('product_id', $locked->id)->orderBy('priority')->orderBy('id')->lockForUpdate()->get()->values();
            $index = $sources->search(fn (ProductSupplierSource $source): bool => (int) $source->supplier_catalog_item_id === (int) $catalog->id);
            if ($index === false) {
                throw ValidationException::withMessages(['connection' => 'Pemetaan supplier tidak ditemukan untuk produk ini.']);
            }
            $this->version((int) $sources[$index]->version, (int) $input['version']);
            $neighbor = $input['direction'] === 'up' ? $index - 1 : $index + 1;
            if ($neighbor < 0 || $neighbor >= $sources->count()) {
                return;
            }
            $ordered = $sources->all();
            [$ordered[$index], $ordered[$neighbor]] = [$ordered[$neighbor], $ordered[$index]];
            foreach ($ordered as $position => $row) {
                $priority = ($position + 1) * 10;
                if ((int) $row->priority !== $priority) {
                    $row->forceFill(['priority' => $priority, 'version' => (int) $row->version + 1])->save();
                }
            }
            $supplier->version = (int) $supplier->version + 1;
            $supplier->save();
            $locked->supplier_available_until = null;
            $locked->save();
            $this->audit->log('supplier.source_moved', $sources[$index], null, ['product_id' => $locked->id, 'catalog_item_id' => $catalog->id, 'direction' => $input['direction'], 'position' => $neighbor + 1], $actor, $ip);
        }, 5);
        $this->availability->refreshProducts([(int) $product->id]);
    }

    public function detach(Product $product, SupplierCatalogItem $item, array $input, User $actor, ?string $ip): void
    {
        abort_unless($actor->role?->canManageStoreOperations() === true, 403);
        DB::transaction(function () use ($product, $item, $input, $actor, $ip): void {
            // Checkout holds this same Product lock before retaining its source snapshot.
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $supplier = Supplier::query()->whereKey($item->supplier_id)->lockForUpdate()->firstOrFail();
            $catalog = SupplierCatalogItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $sources = ProductSupplierSource::query()->where('product_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            $source = $sources->first(fn (ProductSupplierSource $row): bool => (int) $row->id === (int) $input['source_id']
                && (int) $row->supplier_catalog_item_id === (int) $catalog->id);
            if ($source === null || (int) $catalog->supplier_id !== (int) $supplier->id) {
                throw ValidationException::withMessages(['source_id' => 'Pemetaan supplier tidak ditemukan untuk produk ini.']);
            }
            $this->version((int) $source->version, (int) $input['version']);
            $this->version((int) $supplier->version, (int) $input['supplier_version']);
            $this->guardDetachHistory($locked, $catalog);

            $ordered = $sources->sortBy(fn (ProductSupplierSource $row): array => [(int) $row->priority, (int) $row->id])->values();
            $this->audit->log('supplier.source_detached', $source, [
                'source_id' => $source->id, 'product_id' => $locked->id,
                'supplier_id' => $supplier->id, 'catalog_item_id' => $catalog->id,
                'priority' => $source->priority, 'rank' => $ordered->search(fn ($row): bool => $row->id === $source->id) + 1,
                'version' => $source->version, 'supplier_version' => $supplier->version,
            ], null, $actor, $ip);
            $source->delete();
            foreach ($ordered->reject(fn ($row): bool => $row->id === $source->id)->values() as $position => $row) {
                $priority = ($position + 1) * 10;
                if ((int) $row->priority !== $priority) {
                    $row->forceFill(['priority' => $priority, 'version' => (int) $row->version + 1])->save();
                }
            }
            $supplier->forceFill(['version' => (int) $supplier->version + 1])->save();
            $locked->forceFill(['supplier_available_until' => null])->save();
            DB::afterCommit(fn () => $this->availability->refreshProducts([(int) $locked->id]));
        }, 5);
    }

    private function guardDetachHistory(Product $product, SupplierCatalogItem $catalog): void
    {
        $message = 'Mapping sudah digunakan pada pemenuhan/pembelian. Nonaktifkan saja agar riwayat dan pemulihan tetap aman.';
        $fulfillments = SupplierFulfillment::query()->whereHas('orderItem', fn ($query) => $query->where('product_id', $product->id));
        abort_if((clone $fulfillments)->where('reserved_catalog_item_id', $catalog->id)->exists(), 409, $message);
        abort_if(SupplierPurchase::query()->where('supplier_catalog_item_id', $catalog->id)
            ->whereHas('fulfillment.orderItem', fn ($query) => $query->where('product_id', $product->id))->exists(), 409, $message);

        $unreadable = 'Riwayat pemenuhan tidak dapat dibaca. Jangan lepas mapping; pulihkan APP_KEY/backup atau hubungi operator.';
        // Encrypted retained snapshots cannot be searched as SQL JSON. Only decrypt this Product's bounded batches.
        foreach ($fulfillments->select(['id', 'sources_snapshot'])->lazyById(100) as $fulfillment) {
            try {
                $snapshot = $fulfillment->sources_snapshot;
            } catch (DecryptException|JsonException) {
                abort(409, $unreadable);
            }
            abort_unless(is_array($snapshot) && array_is_list($snapshot), 409, $unreadable);
            foreach ($snapshot as $candidate) {
                abort_unless(is_array($candidate), 409, $unreadable);
                $catalogId = $candidate['catalog_item_id'] ?? null;
                abort_unless((is_int($catalogId) || (is_string($catalogId) && ctype_digit($catalogId))) && (int) $catalogId > 0, 409, $unreadable);
                abort_if((int) $catalogId === (int) $catalog->id, 409, $message);
            }
        }
    }

    public function price(Product $product, array $input, User $actor, ?string $ip): SupplierPricePolicy
    {
        abort_unless($actor->role?->canManageStoreOperations() === true, 403);
        $policy = DB::transaction(function () use ($product, $input, $actor, $ip): SupplierPricePolicy {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $supplierIds = SupplierCatalogItem::query()->whereIn('id', ProductSupplierSource::query()->where('product_id', $locked->id)->select('supplier_catalog_item_id'))->distinct()->pluck('supplier_id');
            $suppliers = Supplier::query()->whereIn('id', $supplierIds)->orderBy('id')->lockForUpdate()->get();
            $policy = SupplierPricePolicy::query()->where('product_id', $locked->id)->first() ?? new SupplierPricePolicy;
            $this->version($policy->exists ? (int) $policy->version : 0, (int) $input['version']);
            $created = ! $policy->exists;
            $policy->forceFill(['product_id' => $locked->id, ...array_intersect_key($input, array_flip(['mode', 'markup_basis_points', 'profit_idr', 'minimum_profit_idr', 'rounding_idr']))]);
            if (! $created && ! $policy->isDirty()) {
                return $policy;
            }
            if ($policy->mode !== 'manual') {
                $sources = ProductSupplierSource::query()->where('product_id', $locked->id)->where('is_active', true)->with('catalogItem.supplier')->orderBy('priority')->orderBy('id')->get();
                foreach ($sources as $source) {
                    $item = $source->catalogItem;
                    $supplier = $item->supplier;
                    if (! $supplier->is_active || ! $supplier->currency_confirmed || ! $supplier->sync_expires_at?->isFuture() || ! $item->orderable || $item->requires_input || ($item->currency !== null && $item->currency !== $supplier->currency)) {
                        continue;
                    }
                    $micros = $this->pricing->costMicros($item, 1);
                    if ($micros === null) {
                        continue;
                    }
                    try {
                        $base = $this->pricing->retailPrice($policy, SupplierMoney::costIdr($micros, $supplier->exchange_rate_idr));
                        app(ProductQuantityPricing::class)->validateSchedule($base, $locked->usdt_price_amount, $locked->quantityPriceTiers->map(fn ($tier) => ['minimum_quantity' => $tier->minimum_quantity, 'price_amount' => $tier->price_amount, 'usdt_price_amount' => $tier->usdt_price_amount])->all());
                    } catch (\InvalidArgumentException) {
                        throw ValidationException::withMessages(['mode' => 'Harga retail baru harus mempertahankan tier kuantitas yang valid.']);
                    }
                    break;
                }
            }
            $policy->version = (int) $policy->version + 1;
            $policy->save();
            foreach ($suppliers as $supplier) {
                $supplier->version = (int) $supplier->version + 1;
                $supplier->save();
            }
            $locked->supplier_available_until = null;
            $locked->save();
            $this->audit->log('supplier.pricing_saved', $policy, null, ['product_id' => $locked->id, 'mode' => $policy->mode, 'version' => $policy->version], $actor, $ip);

            return $policy;
        }, 5);
        $this->availability->refreshProducts([(int) $product->id]);

        return $policy;
    }

    public function preview(Product $product, SupplierCatalogItem $item, array $input): array
    {
        $micros = $this->pricing->costMicros($item, 1);
        if ($micros === null) {
            return ['cost_idr' => null, 'retail_idr' => null, 'profit_idr' => null];
        }
        $supplier = Supplier::query()->findOrFail($item->supplier_id);
        $cost = SupplierMoney::costIdr($micros, $supplier->exchange_rate_idr);
        $policy = new SupplierPricePolicy;
        $policy->forceFill($input);

        $retail = $input['mode'] === 'manual' ? (int) $product->price_amount : $this->pricing->retailPrice($policy, $cost);
        $profit = $retail === null ? null : $retail - $cost;

        return [
            'cost_idr' => $cost,
            'retail_idr' => $retail,
            'profit_idr' => $profit,
        ];
    }

    private function version(int $actual, int $expected): void
    {
        if ($actual !== $expected) {
            throw ValidationException::withMessages(['version' => 'Konfigurasi berubah. Muat ulang sebelum menyimpan.']);
        }
    }
}
