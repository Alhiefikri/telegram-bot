<?php

namespace App\Storefront;

final readonly class StorefrontShopCheckoutInput
{
    public function __construct(
        public int $productId,
        public int $quantity,
        public string $buyerHandle,
    ) {}

    /**
     * @param  array{product_id: int|string, quantity: int|string, buyer_handle: string}  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            productId: (int) $validated['product_id'],
            quantity: (int) $validated['quantity'],
            buyerHandle: $validated['buyer_handle'],
        );
    }
}
