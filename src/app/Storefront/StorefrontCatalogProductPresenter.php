<?php

namespace App\Storefront;

use App\Catalog\CatalogProductReadModel;
use App\Catalog\LocalizedProductCopy;

final class StorefrontCatalogProductPresenter
{
    public function __construct(
        private readonly StorefrontUrl $urls,
        private readonly StorefrontProductPlaceholder $placeholder,
    ) {}

    public function present(CatalogProductReadModel $product): StorefrontCatalogProductPresentation
    {
        $isAvailable = $product->isAvailable;
        $familyOptionLabel = trim((string) $product->optionLabel);
        $familyOptionLabel = $familyOptionLabel === '' ? null : $familyOptionLabel;
        $description = LocalizedProductCopy::text($product->copy, 'description', StorefrontLocale::code());

        return new StorefrontCatalogProductPresentation(
            id: $product->id,
            categoryKey: (string) ($product->categoryId ?? 'lainnya'),
            name: $product->name,
            description: $description,
            descriptionSnippet: StorefrontProductPresentationNormalizer::descriptionSnippet($description),
            merchandising: StorefrontProductMerchandising::fromReadModel($product),
            priceAmount: $product->priceAmount,
            usdtPriceAmount: $product->usdtPriceAmount,
            currency: $product->currency,
            stockAvailable: $product->availableStock,
            soldQuantity: $product->soldQuantity,
            isAvailable: $isAvailable,
            availabilityLabel: $isAvailable ? 'Tersedia' : 'Habis',
            stockLabel: $isAvailable ? 'Stok: '.$product->availableStock : 'Stok kosong',
            soldLabel: 'Terjual: '.$product->soldQuantity,
            cover: new StorefrontProductCoverPresentation(
                theme: StorefrontProductPresentationNormalizer::defaultCoverTheme(),
                imageUrl: $this->imageUrl($product),
                icon: $product->icon,
            ),
            productUrl: $this->urls->productUrlForId($product->id),
            checkoutUrl: $this->urls->checkoutProductUrlForId($product->id),
            canJoinWaitlist: ! $isAvailable,
            waitlistUrl: $this->urls->waitlistUrlForId($product->id),
            waitlistCtaLabel: (string) config('storefront.waitlist.cta', 'Beri tahu saya saat tersedia'),
            familyOptionLabel: $familyOptionLabel,
        );
    }

    private function imageUrl(CatalogProductReadModel $product): ?string
    {
        $imageUrl = StorefrontProductPresentationNormalizer::publicStorageUrl($product->imagePath)
            ?? StorefrontProductPresentationNormalizer::publicStorageUrl($product->imageUrl);

        return $imageUrl ?? (trim((string) $product->icon) === '' ? $this->placeholder->imageUrl() : null);
    }
}
