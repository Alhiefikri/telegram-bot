<?php

namespace App\Storefront;

use App\Services\Pricing\ProductPriceTierQuote;

final readonly class StorefrontProductPresentation
{
    /**
     * @param  array<string, StorefrontProductCheckoutChoice>  $checkoutChoices
     * @param  list<ProductPriceTierQuote>  $reachableQuantityPriceTiers
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
        public string $availabilityLabel,
        public string $stockLabel,
        public string $soldLabel,
        public string $statusLabel,
        public string $cardActionLabel,
        public string $productUrl,
        public StorefrontProductCoverPresentation $cover,
        public array $checkoutChoices,
        public StorefrontProductCheckoutReadiness $checkoutReadiness,
        public bool $checkoutMaintenanceActive = false,
        public ?string $checkoutMaintenanceNotice = null,
        public ?int $usdtPriceAmount = null,
        public bool $canJoinWaitlist = false,
        public string $waitlistUrl = '',
        public string $waitlistCtaLabel = 'Beri tahu saya saat tersedia',
        public ?string $familyOptionLabel = null,
        public array $reachableQuantityPriceTiers = [],
    ) {}

    public function hasReachableQuantityPriceTiers(): bool
    {
        return $this->reachableQuantityPriceTiers !== [];
    }

    public function lowestReachableQuantityPriceTier(): ?ProductPriceTierQuote
    {
        return $this->reachableQuantityPriceTiers[0] ?? null;
    }

    /**
     * @return list<array{minimum_quantity:int,price_amount:int,usdt_price_amount:?int}>
     */
    public function reachableQuantityPriceTierData(): array
    {
        return array_map(
            static fn (ProductPriceTierQuote $tier): array => [
                'minimum_quantity' => $tier->minimumQuantity,
                'price_amount' => $tier->priceAmount,
                'usdt_price_amount' => $tier->usdtPriceAmount,
            ],
            $this->reachableQuantityPriceTiers,
        );
    }

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
}
