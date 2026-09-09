<?php

namespace App\Storefront;

use App\Services\CheckoutMaintenance\CheckoutMaintenanceState;
use Illuminate\Support\Collection;

final readonly class StorefrontCheckoutReviewPresentation
{
    /**
     * @param  Collection<int, StorefrontCartItemPresentation>  $items
     * @param  list<array{value:string,label:string,description:string,provider:?string}>  $paymentOptions
     * @param  array<string, mixed>  $copy
     * @param  array<string, mixed>  $supportContext
     */
    public function __construct(
        public StorefrontCheckoutReviewSource $source,
        public Collection $items,
        public int $itemCount,
        public int $subtotal,
        public ?int $usdtSubtotal,
        public string $currency,
        public bool $hasConflicts,
        public ?string $snapshot,
        public array $paymentOptions,
        public ?string $defaultPaymentMethod,
        public CheckoutMaintenanceState $maintenance,
        public string $maintenanceNotice,
        public ?StorefrontProductPresentation $productPresentation,
        public int $quantityMax,
        public bool $canSubmit,
        public ?string $unavailableNotice,
        public string $returnUrl,
        public string $returnLabel,
        public StorefrontPageMetadata $metadata,
        public StorefrontPresenter $storefront,
        public string $brand,
        public array $copy,
        public string $homeUrl,
        public string $shopUrl,
        public ?string $supportHref,
        public string $supportDestination,
        public array $supportContext,
        public string $cartUrl,
        public string $checkoutUrl,
    ) {}

    public function isDirect(): bool
    {
        return $this->source instanceof StorefrontDirectCheckoutReviewSource;
    }

    public function sourceKey(): string
    {
        return $this->isDirect() ? 'direct' : 'cart';
    }

    /**
     * @return array<string, mixed>
     */
    public function viewData(): array
    {
        $directSource = $this->source instanceof StorefrontDirectCheckoutReviewSource
            ? $this->source
            : null;

        return [
            'checkoutReview' => $this,
            'checkoutSource' => $this->sourceKey(),
            'checkoutProduct' => $directSource?->product,
            'checkoutProductPresentation' => $this->productPresentation,
            'checkoutQuantity' => $directSource?->quantity ?? 0,
            'checkoutQuantityMax' => $this->quantityMax,
            'checkoutCanSubmit' => $this->canSubmit,
            'checkoutUnavailableNotice' => $this->unavailableNotice,
            'checkoutReturnUrl' => $this->returnUrl,
            'checkoutReturnLabel' => $this->returnLabel,
            'cart' => $this->source instanceof StorefrontCartCheckoutReviewSource ? $this->source->cart : null,
            'cartItems' => $this->items,
            'cartItemCount' => $this->itemCount,
            'cartSubtotal' => $this->subtotal,
            'cartUsdtSubtotal' => $this->usdtSubtotal,
            'cartCurrency' => $this->currency,
            'cartHasConflicts' => $this->hasConflicts,
            'checkoutSnapshot' => $this->snapshot,
            'checkoutPaymentOptions' => $this->paymentOptions,
            'checkoutDefaultPaymentMethod' => $this->defaultPaymentMethod,
            'checkoutMaintenance' => $this->maintenance,
            'checkoutMaintenanceNotice' => $this->maintenanceNotice,
            'storefront' => $this->storefront,
            'brand' => $this->brand,
            'copy' => $this->copy,
            'homeUrl' => $this->homeUrl,
            'shopUrl' => $this->shopUrl,
            'supportHref' => $this->supportHref,
            'supportDestination' => $this->supportDestination,
            'supportContext' => $this->supportContext,
            'pageTitle' => $this->metadata->title,
            'pageDescription' => $this->metadata->description,
            'canonicalUrl' => $this->metadata->canonicalUrl,
            'metadata' => $this->metadata,
            'cartUrl' => $this->cartUrl,
            'checkoutUrl' => $this->checkoutUrl,
        ];
    }
}
