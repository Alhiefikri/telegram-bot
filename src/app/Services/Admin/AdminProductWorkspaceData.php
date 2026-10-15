<?php

namespace App\Services\Admin;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductSupplierSource;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierPricePolicy;
use App\Services\ProductStockIntake;
use App\Services\Suppliers\SupplierMoney;
use App\Services\Suppliers\SupplierPricing;
use App\Storefront\StorefrontProductPlaceholder;
use InvalidArgumentException;

final class AdminProductWorkspaceData
{
    public const SECTIONS = [
        'detail' => ['category_id', 'name', 'description', 'description_ms', 'description_en', 'product_type', 'access_duration', 'region', 'account_type', 'device_limit', 'included_items', 'limitations', 'fulfillment_expectation', 'warranty_policy', 'warranty_policy_ms', 'warranty_policy_en', 'support_policy', 'delivery_note', 'usage_guide_title', 'usage_guide_url'],
        'price' => ['price_amount', 'usdt_price_amount'],
        'appearance' => ['icon', 'is_active', 'featured_rank', 'image_path', 'telegram_image_path', 'telegram_channel_image_path'],
    ];

    public const CATALOG_COLUMNS = ['id', 'supplier_id', 'external_id', 'name', 'stock', 'orderable', 'requires_input', 'currency', 'unit_price_micros', 'price_tiers', 'bulk_discounts'];

    public const SUPPLIER_COLUMNS = ['id', 'name', 'provider', 'currency', 'exchange_rate_idr', 'is_active', 'version', 'synced_at', 'sync_expires_at'];

    public function __construct(private readonly ProductImageOptimizer $images, private readonly StorefrontProductPlaceholder $placeholder, private readonly SupplierPricing $pricing, private readonly AdminProductQuantityPricing $tiers, private readonly AdminDashboardProductsData $dashboard) {}

    public function version(Product $product, string $section): string
    {
        return $this->sectionVersion($product, $section, $section === 'price' ? $this->tiers->snapshot($product) : []);
    }

    private function sectionVersion(Product $product, string $section, array $priceTiers): string
    {
        $values = $product->only(self::SECTIONS[$section]);
        if ($section === 'price') {
            $values['quantity_price_tiers'] = $priceTiers;
        }

        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    public function for(Product $product, bool $includeOptions = false): array
    {
        $product = Product::query()->with('family:id,name')->withAvailability()->findOrFail($product->id);
        $fields = array_values(array_unique(array_merge(...array_values(self::SECTIONS))));
        $data = $product->only(['id', ...$fields]);
        $data['usdt_price_amount'] = $this->usdt($product->usdt_price_amount);
        $priceTiers = $this->tiers->snapshot($product);
        $data['quantity_price_tiers'] = $priceTiers;
        foreach ($data['quantity_price_tiers'] as &$tier) {
            $tier['usdt_price_amount'] = $this->usdt($tier['usdt_price_amount']);
        }
        unset($tier);
        $data += [
            'image_url' => $this->images->publicUrl($product->image_path) ?? $this->placeholder->imageUrl(),
            'telegram_image_url' => $this->images->telegramUrl($product->telegram_image_path),
            'telegram_channel_image_url' => $this->images->telegramUrl($product->telegram_channel_image_path),
            'family_id' => $product->product_family_id,
            'family_name' => $product->family?->name,
            'family_sort_order' => (int) $product->family_sort_order,
            'family_option_label' => $product->family_option_label,
        ];
        $summary = $this->dashboard->stockItemSummaries(collect([$product->id]))->get($product->id);
        $stockSummary = ['available' => (int) ($summary?->available ?? 0), 'reserved' => (int) ($summary?->reserved ?? 0), 'paid' => (int) ($summary?->paid ?? 0), 'retired' => (int) ($summary?->retired ?? 0)];
        $returnUrl = route('admin.products.workspace', $product).'?section=stock';
        $stockPayload = [
            'id' => $product->id, 'name' => $product->name, 'stock' => $product->stock,
            'stockUrl' => route('admin.products.stock.store', $product),
            'stockAccountsUrl' => route('admin.products.stock.accounts.store', $product),
            'stockAccountsReviewUrl' => route('admin.products.stock.accounts.review', $product),
            'stockReturnUrl' => $returnUrl, 'deliveryNote' => $product->delivery_note,
        ];
        $editPayload = $stockPayload + [
            'availableStockItemCount' => $product->availableStockItemCount(), 'stockSummary' => $stockSummary,
            'stockItemsTruncated' => (int) ($summary?->total ?? 0) > 0, 'stockItems' => [],
            'stockItemsUrl' => route('admin.products.stock-items.index', $product),
            'bulkDeleteUrl' => route('admin.products.stock-items.bulk-destroy', $product),
            'updateUrl' => route('admin.products.update', $product),
        ];
        $sources = ProductSupplierSource::query()->where('product_id', $product->id)
            ->with([
                'catalogItem' => fn ($query) => $query->select(self::CATALOG_COLUMNS),
                'catalogItem.supplier' => fn ($query) => $query->select(self::SUPPLIER_COLUMNS),
            ])
            ->orderBy('priority')->orderBy('id')->limit(101)->get();
        $sourcesTruncated = $sources->count() > 100;
        $sources = $sources->take(100);
        $policy = SupplierPricePolicy::query()->where('product_id', $product->id)->first();
        $policyData = $policy?->only(['version', 'mode', 'markup_basis_points', 'profit_idr', 'minimum_profit_idr', 'rounding_idr']) ?? ['version' => 0, 'mode' => 'manual', 'markup_basis_points' => 0, 'profit_idr' => 0, 'minimum_profit_idr' => 0, 'rounding_idr' => 1];

        return [
            'product' => $data,
            'versions' => array_combine(array_keys(self::SECTIONS), array_map(fn (string $section): string => $this->sectionVersion($product, $section, $priceTiers), array_keys(self::SECTIONS)))
                + ['family' => app(ProductFamilyCategoryCoherence::class)->membershipVersion($product)],
            'urls' => ['workspace' => route('admin.products.workspace', $product), 'update' => route('admin.products.update', $product), 'catalog_search' => route('admin.products.workspace.catalog', $product), 'price' => route('admin.products.supplier-pricing', $product), 'price_preview' => route('admin.products.supplier-pricing.preview', $product), 'family_update' => route('admin.products.family.update', $product), 'dashboard' => route('admin.dashboard', ['tab' => 'produk'])],
            'stock' => ['stock' => $product->stock, 'reserved_stock' => $product->reserved_stock, 'available_stock' => $product->localAvailableStock(), 'available_stock_item_count' => $product->availableStockItemCount(), 'supplier_available_stock' => $product->supplierAvailableStock(), 'supplier_available_until' => $product->supplier_available_until?->toISOString(), 'total_available_stock' => $product->availableStock(), 'summary' => $stockSummary],
            'stock_payload' => $stockPayload, 'edit_payload' => $editPayload,
            'sources' => $sources->map(function (ProductSupplierSource $source): array {
                $item = $source->catalogItem;

                return ['id' => $source->id, 'version' => $source->version, 'priority' => $source->priority, 'is_active' => $source->is_active, 'catalog_item_id' => $item->id, 'supplier_version' => $item->supplier->version, 'supplier' => $this->supplier($item), 'catalog' => $this->catalog($item), 'cost' => $this->cost($item), 'urls' => ['map' => route('admin.suppliers.map', $item), 'move' => route('admin.suppliers.move', $item), 'detach' => route('admin.suppliers.detach', $item)]];
            })->all(),
            'sources_truncated' => $sourcesTruncated,
            'policy' => $policyData,
            'structured_stock_input_enabled' => app(ProductStockIntake::class)->structuredInputEnabled(),
            ...($includeOptions ? [
                'category_options' => Category::query()->orderBy('name')->orderBy('id')->get(['id', 'name'])->toArray(),
                'family_options' => ProductFamily::query()->orderBy('name')->orderBy('id')->get(['id', 'name'])->toArray(),
            ] : []),
        ];
    }

    public function catalog(SupplierCatalogItem $item): array
    {
        return $item->only(['id', 'name', 'external_id', 'stock', 'orderable', 'requires_input', 'currency']);
    }

    public function supplier(SupplierCatalogItem $item): array
    {
        return $item->supplier->only(['id', 'name', 'provider', 'currency', 'exchange_rate_idr', 'is_active']) + [
            'synced_at' => $item->supplier->synced_at?->toISOString(),
            'sync_expires_at' => $item->supplier->sync_expires_at?->toISOString(),
            'is_fresh' => $item->supplier->sync_expires_at?->isFuture() === true,
        ];
    }

    public function cost(SupplierCatalogItem $item): array
    {
        $micros = $this->pricing->costMicros($item, 1);
        try {
            $idr = $micros === null ? null : SupplierMoney::costIdr($micros, $item->supplier->exchange_rate_idr);
        } catch (InvalidArgumentException) {
            $idr = null;
        }

        return ['unit' => $micros === null ? null : rtrim(rtrim(SupplierMoney::decimal($micros), '0'), '.'), 'idr' => $idr];
    }

    private function usdt(?int $micros): ?string
    {
        return $micros === null ? null : (rtrim(rtrim(sprintf('%d.%06d', intdiv($micros, 1000000), $micros % 1000000), '0'), '.') ?: '0');
    }
}
