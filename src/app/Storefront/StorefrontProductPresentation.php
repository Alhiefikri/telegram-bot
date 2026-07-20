<?php

namespace App\Storefront;

final readonly class StorefrontProductPresentation
{
    /**
     * @param  array<string, StorefrontProductCheckoutChoice>  $checkoutChoices
     * @param  array<string, mixed>  $support
     */
    public function __construct(
        public int $id,
        public string $categoryKey,
        public string $name,
        public string $description,
        public string $descriptionSnippet,
        public int $priceAmount,
        public int $stockAvailable,
        public int $soldQuantity,
        public bool $isAvailable,
        public bool $isBuyable,
        public bool $canOpenModal,
        public string $availabilityLabel,
        public string $stockLabel,
        public string $soldLabel,
        public string $statusLabel,
        public string $cardActionLabel,
        public string $productUrl,
        public StorefrontProductCoverPresentation $cover,
        public array $checkoutChoices,
        public array $support,
    ) {}

    public function checkoutChoice(string $key): ?StorefrontProductCheckoutChoice
    {
        return $this->checkoutChoices[$key] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function modalPayload(): ?array
    {
        if (! $this->canOpenModal) {
            return null;
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'descriptionSnippet' => $this->descriptionSnippet,
            'price' => [
                'amount' => $this->priceAmount,
            ],
            'stock' => [
                'available' => $this->stockAvailable,
                'label' => $this->stockLabel,
                'emptyLabel' => 'Stok kosong',
            ],
            'sold' => [
                'quantity' => $this->soldQuantity,
                'label' => $this->soldLabel,
            ],
            'availability' => [
                'isAvailable' => $this->isAvailable,
                'canOpenModal' => $this->canOpenModal,
            ],
            'cover' => $this->cover->toModalPayload(),
            'checkout' => [
                'websiteQris' => $this->checkoutChoice('website_qris')?->toModalPayload(),
                'websiteBinancePay' => $this->checkoutChoice('website_binance_pay')?->toModalPayload(),
            ],
            'support' => $this->support,
        ];
    }
}
