<?php

namespace App\Storefront;

final readonly class StorefrontProductCoverPresentation
{
    /**
     * @param  array{bg:string, accent:string, badge:string, badge_bg:string, desc:string, logo:string}  $theme
     */
    public function __construct(
        public array $theme,
        public ?string $imageUrl,
        public ?string $icon = null,
        public string $emptyStampTitle = 'STOK HABIS',
        public string $emptyStampSubtitle = 'Restock segera',
    ) {}

    public function hasImage(): bool
    {
        return $this->imageUrl !== null;
    }
}
