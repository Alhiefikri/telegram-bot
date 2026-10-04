<?php

namespace App\Services\Suppliers;

use App\Models\Product;
use App\Models\ProductSupplierSource;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierPurchase;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SupplierCatalogSync
{
    public const FRESHNESS_SECONDS = 300;

    public function __construct(private readonly SupplierClient $client, private readonly SupplierAvailability $availability) {}

    public function sync(Supplier $supplier): bool
    {
        $connection = Supplier::query()->find($supplier->id);
        if ($connection === null || ! $connection->is_active) {
            return false;
        }
        $token = (string) Str::uuid();
        $version = $connection->version;
        // A durable lease suppresses concurrent scheduler/manual reads without
        // holding a financial transaction across provider HTTP.
        if (Supplier::query()->whereKey($connection->id)->where('version', $version)
            ->where(fn ($query) => $query->whereNull('sync_token')->orWhere('sync_lease_expires_at', '<=', now()))
            ->update(['sync_token' => $token, 'sync_attempted_at' => now(), 'sync_lease_expires_at' => now()->addMinute()]) !== 1) {
            return false;
        }
        try {
            $snapshot = $this->client->snapshot($connection);
            if ($snapshot->currency !== $connection->currency) {
                $this->fail($connection->id, $version, $token, 'currency_mismatch');

                return false;
            }
        } catch (SupplierUnavailable $exception) {
            $this->fail($connection->id, $version, $token, $exception->errorCode);

            return false;
        }
        $productIds = $this->affectedProducts($connection->id);
        try {
            $applied = DB::transaction(function () use ($connection, $token, $version, $snapshot, $productIds): bool {
                $products = Product::withTrashed()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get();
                $sources = ProductSupplierSource::query()->whereIn('product_id', $productIds)->orderBy('priority')->orderBy('id')->get();
                $mappedItems = $sources->pluck('supplier_catalog_item_id')->unique();
                $supplierIds = SupplierCatalogItem::query()->whereIn('id', $mappedItems)->pluck('supplier_id')->push($connection->id)->unique();
                $suppliers = Supplier::query()->whereIn('id', $supplierIds)->orderBy('id')->lockForUpdate()->get();
                $locked = $suppliers->firstWhere('id', $connection->id);
                if ($locked === null || ! $locked->is_active || $locked->version !== $version || $locked->sync_token !== $token
                    || $this->affectedProducts($connection->id) !== $productIds) {
                    return false;
                }
                if ($snapshot->accountMetadata !== []) {
                    $accountId = $snapshot->accountMetadata['account_id'];
                    if ($locked->external_account_id !== null && $locked->external_account_id !== $accountId) {
                        $locked->forceFill(['sync_expires_at' => null, 'sync_token' => null, 'sync_lease_expires_at' => null, 'last_error' => 'account_identity_mismatch', 'version' => $locked->version + 1])->save();
                        foreach ($products as $product) {
                            $product->forceFill(['supplier_available_until' => null])->save();
                        }

                        return false;
                    }
                    if (Supplier::query()->where('provider', $locked->provider)->where('external_account_id', $accountId)->whereKeyNot($locked->id)->exists()) {
                        $locked->forceFill(['sync_expires_at' => null, 'sync_token' => null, 'sync_lease_expires_at' => null, 'last_error' => 'duplicate_account', 'version' => $locked->version + 1])->save();

                        return false;
                    }
                }
                // Any provider wallet may already include an unsettled purchase's
                // debit. Keep the pre-debit wallet until local finalization, but
                // refresh catalog observations and freshness independently.
                $balanceBlocked = SupplierPurchase::query()->where('supplier_id', $locked->id)
                    ->whereNull('committed_at')->whereIn('state', ['sending', 'ambiguous', 'awaiting_delivery', 'review'])->exists();
                SupplierCatalogItem::query()->where(fn ($query) => $query->where('supplier_id', $locked->id)->orWhereIn('id', $mappedItems))
                    ->select('id')->orderBy('id')->lockForUpdate()->get();
                $now = now();
                $rows = [];
                foreach ($snapshot->products as $item) {
                    $rows[] = [
                        'supplier_id' => $locked->id,
                        'external_id' => $item->externalId,
                        'name' => $item->name,
                        'description' => $item->description,
                        'currency' => $snapshot->currency,
                        'unit_price_micros' => $item->unitPriceMicros,
                        'stock' => $item->stock,
                        'orderable' => $item->orderable,
                        'requires_input' => $item->requiresInput,
                        'price_tiers' => json_encode($item->priceTiers, JSON_THROW_ON_ERROR),
                        'bulk_discounts' => json_encode($item->bulkDiscounts, JSON_THROW_ON_ERROR),
                        'synced_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                foreach (array_chunk($rows, 200) as $chunk) {
                    // Never replace reserved_quantity: held commitments are owned by checkout/purchase.
                    SupplierCatalogItem::query()->upsert($chunk, ['supplier_id', 'external_id'], [
                        'name', 'description', 'currency', 'unit_price_micros', 'stock', 'orderable', 'requires_input', 'price_tiers', 'bulk_discounts', 'synced_at', 'updated_at',
                    ]);
                }
                // Observed missing SKUs are authoritatively unavailable, but their holds survive.
                SupplierCatalogItem::query()->where('supplier_id', $locked->id)
                    ->whereNotIn('external_id', array_column($rows, 'external_id'))
                    ->update(['stock' => 0, 'orderable' => false, 'synced_at' => $now, 'updated_at' => $now]);
                $balance = $balanceBlocked ? $locked->balance_micros : $snapshot->balanceMicros;
                $locked->forceFill([
                    'balance_micros' => $balance,
                    'balance_observed_at' => $balanceBlocked ? $locked->balance_observed_at : $now,
                    'synced_at' => $now,
                    'sync_expires_at' => $now->copy()->addSeconds(self::FRESHNESS_SECONDS),
                    'sync_token' => null,
                    'sync_lease_expires_at' => null,
                    'last_error' => null,
                    'version' => $locked->version + 1,
                ])->save();
                app(SupplierHealthService::class)->observeRecovery($locked, ! $balanceBlocked);
                if ($snapshot->accountMetadata !== []) {
                    $locked->forceFill(['external_account_id' => $snapshot->accountMetadata['account_id'], 'account_metadata' => $snapshot->accountMetadata])->save();
                }
                $items = SupplierCatalogItem::query()->whereIn('id', $mappedItems)->orderBy('id')->get();
                $this->availability->refreshLocked($products, $suppliers, $items, $sources);

                return true;
            }, 5);
        } catch (UniqueConstraintViolationException) {
            $this->fail($connection->id, $version, $token, 'duplicate_account');

            return false;
        }
        if (! $applied) {
            Supplier::query()->whereKey($connection->id)->where('version', $version)->where('sync_token', $token)
                ->update(['sync_token' => null, 'sync_lease_expires_at' => null]);
        }

        return $applied;
    }

    private function affectedProducts(int $supplierId): array
    {
        return ProductSupplierSource::query()->whereHas('catalogItem', fn ($query) => $query->where('supplier_id', $supplierId))
            ->orderBy('product_id')->pluck('product_id')->unique()->map(fn ($id): int => (int) $id)->values()->all();
    }

    private function fail(int $supplierId, int $version, string $token, string $code): void
    {
        if ($code === 'currency_mismatch') {
            $ids = $this->affectedProducts($supplierId);
            $applied = DB::transaction(function () use ($supplierId, $version, $token, $code, $ids): bool {
                $products = Product::withTrashed()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
                $supplier = Supplier::query()->whereKey($supplierId)->lockForUpdate()->first();
                if ($supplier === null || $supplier->version !== $version || $supplier->sync_token !== $token
                    || $this->affectedProducts($supplierId) !== $ids) {
                    return false;
                }
                $supplier->forceFill([
                    'currency_confirmed' => false,
                    'sync_expires_at' => null,
                    'sync_token' => null,
                    'sync_lease_expires_at' => null,
                    'last_error' => $code,
                    'version' => $version + 1,
                ])->save();
                foreach ($products as $product) {
                    $product->forceFill(['supplier_available_until' => null])->save();
                }

                return true;
            }, 5);
            if ($applied) {
                $this->availability->refreshProducts($ids);
            }

            return;
        }
        // Preserve observed data/freshness; a failed GET is not evidence of sold-out inventory.
        Supplier::query()->whereKey($supplierId)->where('version', $version)->where('sync_token', $token)
            ->update(['sync_token' => null, 'sync_lease_expires_at' => null, 'last_error' => $code]);
    }
}
