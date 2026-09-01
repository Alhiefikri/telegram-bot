<?php

namespace App\Storefront;

use App\Catalog\CatalogFamilyReadModel;
use App\Catalog\CatalogProductReadModel;
use App\Models\ProductFamily;
use App\Services\Catalog\CatalogBrowse;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use Illuminate\Support\Collection;

final class StorefrontFamilyPageBuilder
{
    public function __construct(
        private readonly CatalogBrowse $catalog,
        private readonly StorefrontPresenter $storefront,
        private readonly StorefrontCatalogProductPresenter $catalogPresenter,
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
        $members = $this->memberPresentations($family);
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
    private function memberPresentations(CatalogFamilyReadModel $family): Collection
    {
        return collect($family->members)
            ->map(fn (CatalogProductReadModel $member): StorefrontCatalogProduct => new StorefrontCatalogProduct(
                presentation: $this->catalogPresenter->present($member),
            ))
            ->values();
    }
}
