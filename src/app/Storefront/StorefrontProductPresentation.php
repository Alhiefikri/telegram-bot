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
        public StorefrontProductMerchandising $merchandising,
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
        public StorefrontProductCheckoutReadiness $checkoutReadiness,
        public array $support,
        public bool $checkoutMaintenanceActive = false,
        public ?string $checkoutMaintenanceNotice = null,
        public ?int $usdtPriceAmount = null,
        public bool $canJoinWaitlist = false,
        public string $waitlistUrl = '',
        public string $waitlistCtaLabel = 'Beri tahu saya saat tersedia',
        public ?string $familyOptionLabel = null,
    ) {}

    public function checkoutChoice(string $key): ?StorefrontProductCheckoutChoice
    {
        return $this->checkoutChoices[$key] ?? null;
    }

    public function primaryCheckoutChoice(): ?StorefrontProductCheckoutChoice
    {
        return $this->checkoutChoice('website_qris')
            ?? $this->checkoutChoice('website_binance_pay')
            ?? array_values($this->checkoutChoices)[0]
            ?? null;
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
            'merchandising' => $this->merchandising->toPublicPayload(),
            'price' => array_filter([
                'amount' => $this->priceAmount,
                'usdtAmount' => $this->usdtPriceAmount,
            ], static fn (mixed $value): bool => $value !== null),
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
            'checkout' => array_filter([
                'websiteQris' => $this->checkoutChoice('website_qris')?->toModalPayload(),
                'websiteBinancePay' => $this->checkoutChoice('website_binance_pay')?->toModalPayload(),
                'websiteBinancePayId' => $this->checkoutChoice('website_binance_pay_id')?->toModalPayload(),
                'websiteUsdtBep20' => $this->checkoutChoice('website_usdt_bep20')?->toModalPayload(),
            ], static fn (?array $choice): bool => $choice !== null && ($choice['enabled'] ?? false)),
            'maintenance' => [
                'active' => $this->checkoutMaintenanceActive,
                'notice' => $this->checkoutMaintenanceNotice,
            ],
            'support' => $this->support,
        ];
    }
}
