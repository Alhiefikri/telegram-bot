<?php

namespace App\Storefront;

use App\Catalog\CatalogFamilyReadModel;
use App\Catalog\CatalogProductReadModel;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Services\Catalog\CatalogBrowse;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use App\Services\CheckoutMaintenance\CheckoutMaintenanceState;
use Illuminate\Support\Collection;

final class StorefrontFamilyPageBuilder
{
    /** @var list<string> */
    private const PRODUCT_COLUMNS = [
        'id',
        'category_id',
        'product_family_id',
        'family_sort_order',
        'family_option_label',
        'name',
        'description',
        'product_type',
        'access_duration',
        'region',
        'account_type',
        'device_limit',
        'included_items',
        'limitations',
        'fulfillment_expectation',
        'warranty_policy',
        'support_policy',
        'price_amount',
        'usdt_price_amount',
        'currency',
        'stock',
        'reserved_stock',
        'image_path',
        'is_active',
    ];

    public function __construct(
        private readonly CatalogBrowse $catalog,
        private readonly StorefrontPresenter $storefront,
        private readonly StorefrontProductPresenter $products,
        private readonly StorefrontUrl $urls,
        private readonly CheckoutMaintenance $maintenance,
        private readonly StorefrontSeo $seo,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function viewData(ProductFamily $routeFamily, ?string $returnUrl = null): array
    {
        $family = $this->catalog->showPublicFamily($routeFamily->slug);
        $maintenance = $this->maintenance->current();
        $members = $this->memberPresentations($family, $maintenance);
        $familyUrl = $this->urls->familyUrl($family->slug);
        $metadata = $this->seo->family($family, $familyUrl);
        $catalogFamily = StorefrontCatalogFamily::fromReadModel($family, $familyUrl);
        $returnUrl ??= $familyUrl;

        return [
            'storefront' => $this->storefront,
            'brand' => $this->storefront->brand(),
            'copy' => config('storefront'),
            'homeUrl' => route('storefront.home'),
            'shopUrl' => route('storefront.shop.index'),
            'pageTitle' => $metadata->title,
            'pageDescription' => $metadata->description,
            'canonicalUrl' => $metadata->canonicalUrl ?? $familyUrl,
            'metadata' => $metadata,
            'supportHref' => $this->storefront->supportHref(),
            'supportDestination' => $this->storefront->supportDestination(),
            'supportContext' => $this->storefront->support()->general(),
            'family' => $catalogFamily,
            'catalogFamily' => $catalogFamily,
            'members' => $members,
            'familyUrl' => $familyUrl,
            'returnUrl' => $returnUrl,
            'checkoutMaintenance' => $maintenance,
            'checkoutMaintenanceNotice' => StorefrontCheckoutMaintenanceCopy::notice($maintenance),
        ];
    }

    /**
     * @return Collection<int, StorefrontCatalogProduct>
     */
    private function memberPresentations(CatalogFamilyReadModel $family, CheckoutMaintenanceState $maintenance): Collection
    {
        $memberIds = collect($family->members)
            ->map(fn (CatalogProductReadModel $member): int => $member->id)
            ->all();
        $products = Product::query()
            ->select(self::PRODUCT_COLUMNS)
            ->with([
                'category' => fn ($query) => $query->select(['id', 'name', 'slug'])->publicVisible(),
                'family' => fn ($query) => $query->select(['id', 'name', 'slug']),
            ])
            ->publicVisible()
            ->whereIn('id', $memberIds)
            ->get()
            ->keyBy('id');

        return collect($family->members)
            ->map(function (CatalogProductReadModel $member) use ($products, $maintenance): ?StorefrontCatalogProduct {
                $product = $products->get($member->id);
                if (! $product instanceof Product) {
                    return null;
                }

                return new StorefrontCatalogProduct(
                    presentation: $this->products->present(
                        $product,
                        stockAvailable: $member->availableStock,
                        soldQuantity: $member->soldQuantity,
                        maintenanceState: $maintenance,
                    ),
                );
            })
            ->filter()
            ->values();
    }
}
