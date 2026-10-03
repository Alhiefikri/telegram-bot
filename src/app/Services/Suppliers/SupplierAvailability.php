<?php

namespace App\Services\Suppliers;

use App\Enums\InventoryMovementType;
use App\Jobs\SendProductStockNotifications;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductSupplierSource;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Services\Pricing\ProductQuantityPricing;
use App\Services\ProductWaitlistNotificationService;
use App\Services\Telegram\StockNotificationDeliveryPipeline;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SupplierAvailability
{
    public function __construct(
        private readonly SupplierPricing $pricing,
        private readonly ProductQuantityPricing $retail,
        private readonly StockNotificationDeliveryPipeline $notifications,
        private readonly ProductWaitlistNotificationService $waitlist,
    ) {}

    public function refreshProducts(array $productIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        sort($ids, SORT_NUMERIC);
        foreach (array_chunk($ids, 200) as $batch) {
            DB::transaction(function () use ($batch): void {
                $products = Product::withTrashed()->whereIn('id', $batch)->orderBy('id')->lockForUpdate()->get();
                $sources = ProductSupplierSource::query()->whereIn('product_id', $batch)->orderBy('priority')->orderBy('id')->get();
                $itemIds = $sources->pluck('supplier_catalog_item_id')->unique();
                $supplierIds = SupplierCatalogItem::query()->whereIn('id', $itemIds)->pluck('supplier_id')->unique();
                $suppliers = Supplier::query()->whereIn('id', $supplierIds)->orderBy('id')->lockForUpdate()->get();
                $items = SupplierCatalogItem::query()->whereIn('id', $itemIds)->orderBy('id')->lockForUpdate()->get();
                $this->refreshLocked($products, $suppliers, $items, $sources);
            }, 5);
        }
    }

    /** Caller owns ascending Product -> Supplier -> CatalogItem locks and complete mapped collections. */
    public function refreshLocked(Collection $products, Collection $suppliers, Collection $catalogItems, Collection $sources): void
    {
        $products->loadMissing(['supplierPricePolicy', 'quantityPriceTiers']);
        $products->loadExists(['stockItems as local_stock_items_exists' => fn ($query) => $query->whereNull('supplier_purchase_id')]);
        $products->loadCount(['stockItems as local_available_stock_items_count' => fn ($query) => $query->whereNull('supplier_purchase_id')->whereNull('order_item_id')->whereNull('retired_at')]);
        $supplierMap = $suppliers->keyBy('id');
        $itemMap = $catalogItems->keyBy('id');
        $byProduct = $sources->sortBy([['priority', 'asc'], ['id', 'asc']])->groupBy('product_id');
        foreach ($products as $product) {
            // Retain the last observed combined state through transient read failure/expiry.
            // A later identical snapshot is not a restock. Checkout still uses the strict expiry gate.
            $before = $product->localAvailableStock() + max(0, (int) $product->supplier_available_stock);
            $capacity = 0;
            $until = null;
            $policy = $product->supplierPricePolicy;
            $priceResolved = $policy === null || $policy->mode === 'manual';
            $hasStaleSource = false;
            foreach ($byProduct->get($product->id, collect()) as $source) {
                $item = $itemMap->get($source->supplier_catalog_item_id);
                $supplier = $item === null ? null : $supplierMap->get($item->supplier_id);
                if ($policy === null || ! $source->is_active || $supplier === null || ! $supplier->is_active
                    || $supplier->purchase_paused_until?->isFuture()
                    || ! $supplier->currency_confirmed
                    || ($item->currency !== null && $item->currency !== $supplier->currency) || ! $item->orderable || $item->requires_input) {
                    continue;
                }
                if ($supplier->sync_expires_at === null || ! $supplier->sync_expires_at->isFuture()) {
                    $hasStaleSource = true;

                    continue;
                }
                if (! $priceResolved) {
                    try {
                        $micros = $this->pricing->costMicros($item, 1);
                        if ($micros === null) {
                            continue;
                        }
                        $price = $this->pricing->retailPrice($policy, SupplierMoney::costIdr($micros, $supplier->exchange_rate_idr));
                        $this->retail->validateSchedule($price, $product->usdt_price_amount, $product->quantityPriceTiers->map(fn ($tier): array => [
                            'minimum_quantity' => $tier->minimum_quantity,
                            'price_amount' => $tier->price_amount,
                            'usdt_price_amount' => $tier->usdt_price_amount,
                        ])->all());
                        $product->price_amount = $price;
                        $priceResolved = true;
                    } catch (InvalidArgumentException) {
                        // Invalid auto price cannot leave a stale retail amount authorizing supplier checkout.
                        break;
                    }
                }
                $candidate = $this->pricing->capacity($product, $supplier, $item, $policy);
                if ($candidate > 0) {
                    // One source can fill the advertised range; do not double-count one wallet/SKU.
                    $capacity = max($capacity, $candidate);
                    // Conservative cutoff: all included sources remain fresh through this instant.
                    $until = $until === null || $supplier->sync_expires_at->lessThan($until) ? $supplier->sync_expires_at : $until;
                }
            }
            if ($capacity === 0 && $hasStaleSource) {
                // Expiry is a safety gate, not an authoritative stock-zero observation.
                $capacity = max(0, (int) $product->supplier_available_stock);
            }
            $product->forceFill(['supplier_available_stock' => $capacity, 'supplier_available_until' => $until]);
            if ($product->isDirty(['supplier_available_stock', 'supplier_available_until', 'price_amount'])) {
                $product->save();
            }
            $after = $product->localAvailableStock() + $capacity;
            if (($before === 0 && $after > 0) || ($before > 0 && $after === 0)) {
                $movement = InventoryMovement::query()->create([
                    'product_id' => $product->id,
                    'type' => InventoryMovementType::Availability,
                    'quantity' => $after - $before,
                    'stock_before' => $product->stock,
                    'available_before' => $before,
                    'available_after' => $after,
                    'became_unavailable' => $after === 0,
                    'notes' => 'External availability projection; no local inventory mutation.',
                ]);
                $this->notifications->register($movement->id);
                $this->waitlist->scheduleForMovement($movement->id);
                DB::afterCommit(fn () => SendProductStockNotifications::dispatch($movement->id)->onQueue(StockNotificationDeliveryPipeline::QUEUE));
            }
        }
    }
}
