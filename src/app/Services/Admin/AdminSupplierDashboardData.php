<?php

namespace App\Services\Admin;

use App\Models\Product;
use App\Models\ProductSupplierSource;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use Illuminate\Support\Collection;

final class AdminSupplierDashboardData
{
    public function __construct(private readonly SupplierEconomics $economics) {}

    public function forTab(): array
    {
        $connections = Supplier::query()->orderBy('id')->paginate(15, ['*'], 'suppliers_page');
        $catalogCounts = SupplierCatalogItem::query()
            ->whereIn('supplier_id', $connections->getCollection()->pluck('id'))
            ->selectRaw('supplier_id, count(*) as aggregate')
            ->groupBy('supplier_id')
            ->pluck('aggregate', 'supplier_id');
        $soldCounts = $this->soldProductCounts($connections->getCollection()->pluck('id')->all());

        $connections->setCollection($connections->getCollection()->map(function (Supplier $supplier) use ($catalogCounts, $soldCounts): array {
            $available = $supplier->balance_micros === null ? null : max(0, (int) $supplier->balance_micros - (int) $supplier->reserved_balance_micros);
            $rate = (int) $supplier->exchange_rate_idr;
            $availableIdr = $this->economics->convertMicrosToIdr($available, $supplier);

            return [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'provider' => $supplier->provider,
                'currency' => $supplier->currency,
                'currency_confirmed' => (bool) $supplier->currency_confirmed,
                'exchange_rate_idr' => $rate,
                'exchange_rate_formatted' => 'Rp'.number_format($rate, 0, ',', '.'),
                'is_active' => (bool) $supplier->is_active,
                'version' => (int) $supplier->version,
                'low_balance_threshold_micros' => (int) $supplier->low_balance_threshold_micros,
                'balance' => $this->economics->formatCurrency($supplier->balance_micros, $supplier->currency) ?? 'Belum diketahui',
                'held_balance' => $this->economics->formatCurrency((int) $supplier->reserved_balance_micros, $supplier->currency),
                'available_balance' => $this->economics->formatCurrency($available, $supplier->currency) ?? 'Belum diketahui',
                'available_balance_idr' => $this->economics->formatIdr($availableIdr),
                'low_balance' => $available !== null && $available <= (int) $supplier->low_balance_threshold_micros,
                'synced_at' => $supplier->synced_at?->toIso8601String(),
                'synced_human' => $this->economics->humanAge($supplier->synced_at),
                'fresh' => $supplier->sync_expires_at?->isFuture() ?? false,
                'expires_at' => $supplier->sync_expires_at?->toIso8601String(),
                'catalog_items_count' => (int) ($catalogCounts[$supplier->id] ?? 0),
                'sold_products_count' => (int) ($soldCounts[$supplier->id] ?? 0),
            ];
        }));

        $products = Product::query()
            ->whereHas('supplierSources')
            ->with(['supplierSources.catalogItem.supplier'])
            ->orderBy('id')
            ->paginate(25, ['id', 'name', 'price_amount', 'is_active', 'supplier_available_stock', 'supplier_available_until'], 'supplier_products_page')
            ->appends(['tab' => 'suppliers']);

        $products->setCollection($products->getCollection()->map(fn (Product $product): array => $this->productRow($product)));

        return [
            'connections' => $connections->appends(['tab' => 'suppliers']),
            'products' => $products,
        ];
    }

    /**
     * Presentation-only "sale status" derived from existing state; it never
     * changes availability, pricing, or fulfillment behaviour.
     *
     * @return array{key: string, label: string, tone: string}
     */
    private function saleStatus(Product $product, ?ProductSupplierSource $source, ?Supplier $supplier): array
    {
        if (! $product->is_active) {
            return ['key' => 'nonaktif', 'label' => 'Nonaktif di toko', 'tone' => 'slate'];
        }
        if (! $supplier instanceof Supplier || ! $supplier->is_active) {
            return ['key' => 'koneksi-nonaktif', 'label' => 'Koneksi nonaktif', 'tone' => 'slate'];
        }
        if (! $supplier->currency_confirmed) {
            return ['key' => 'perlu-konfirmasi', 'label' => 'Perlu konfirmasi mata uang', 'tone' => 'amber'];
        }
        if (! ($supplier->sync_expires_at?->isFuture() ?? false)) {
            return ['key' => 'perlu-sinkron', 'label' => 'Perlu sinkron ulang', 'tone' => 'amber'];
        }
        if (! $source?->is_active) {
            return ['key' => 'sumber-dimatikan', 'label' => 'Sumber dimatikan', 'tone' => 'amber'];
        }

        return ['key' => 'dijual', 'label' => 'Dijual', 'tone' => 'emerald'];
    }

    /**
     * @return array<string, mixed>
     */
    private function productRow(Product $product): array
    {
        $source = $product->supplierSources
            ->sortBy([['is_active', 'desc'], ['priority', 'asc'], ['id', 'asc']])
            ->first();
        $item = $source?->catalogItem;
        $supplier = $item?->supplier;
        $cost = $this->economics->costIdr($item, $supplier);
        $retail = (int) $product->price_amount;
        $margin = $this->economics->margin($cost, $retail);

        return [
            'id' => $product->id,
            'name' => $product->name,
            'price_formatted' => $this->economics->formatIdr($retail),
            'cost_formatted' => $this->economics->formatIdr($cost),
            'profit_formatted' => $this->economics->formatIdr($margin['profit_idr'] ?? null),
            'profit_percent_formatted' => $margin === null ? null : $this->economics->formatPercent($margin['profit_basis_points']),
            'status' => $this->saleStatus($product, $source, $supplier),
            'supplier_name' => $supplier?->name,
            'sku' => $item?->external_id,
            'source_count' => $product->supplierSources->count(),
        ];
    }

    /**
     * @param  list<int>  $supplierIds
     * @return Collection<int|string, int>
     */
    private function soldProductCounts(array $supplierIds): Collection
    {
        if ($supplierIds === []) {
            return collect();
        }

        return ProductSupplierSource::query()
            ->join('supplier_catalog_items', 'supplier_catalog_items.id', '=', 'product_supplier_sources.supplier_catalog_item_id')
            ->join('products', 'products.id', '=', 'product_supplier_sources.product_id')
            ->whereIn('supplier_catalog_items.supplier_id', $supplierIds)
            ->where('product_supplier_sources.is_active', true)
            ->where('products.is_active', true)
            ->whereNull('products.deleted_at')
            ->selectRaw('supplier_catalog_items.supplier_id as supplier_id, count(distinct product_supplier_sources.product_id) as aggregate')
            ->groupBy('supplier_catalog_items.supplier_id')
            ->pluck('aggregate', 'supplier_id');
    }
}
