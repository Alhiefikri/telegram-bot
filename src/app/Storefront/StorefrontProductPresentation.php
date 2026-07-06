<?php

namespace App\Storefront;

final readonly class StorefrontProductPresentation
{
    /**
     * @param  array<string, StorefrontProductCheckoutChoice>  $checkoutChoices
     */
    public function __construct(
        public int $id,
        public string $categoryKey,
        public string $name,
        public string $description,
        public string $descriptionSnippet,
        public int $priceAmount,
        public ?int $fakeDiscountPrice,
        public int $stockAvailable,
        public bool $isAvailable,
        public bool $isBuyable,
        public bool $canOpenModal,
        public string $availabilityLabel,
        public string $stockLabel,
        public string $statusLabel,
        public string $cardActionLabel,
        public string $productUrl,
        public StorefrontProductCoverPresentation $cover,
        public array $checkoutChoices,
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
                'fakeDiscountAmount' => $this->fakeDiscountPrice,
            ],
            'stock' => [
                'available' => $this->stockAvailable,
                'label' => $this->stockLabel,
                'emptyLabel' => 'Stok kosong',
            ],
            'availability' => [
                'isAvailable' => $this->isAvailable,
                'canOpenModal' => $this->canOpenModal,
            ],
            'cover' => $this->cover->toModalPayload(),
            'checkout' => [
                'websiteQris' => $this->checkoutChoice('website_qris')?->toModalPayload(),
                'telegram' => $this->checkoutChoice('telegram_admin')?->toModalPayload(),
            ],
        ];
    }
}
