<?php

namespace App\Storefront;

final readonly class StorefrontShopCheckoutInput
{
    public function __construct(
        public int $productId,
        public int $quantity,
        public string $buyerName,
        public ?string $supportContactChannel = null,
        public ?string $supportContactValue = null,
        public string $paymentMethod = 'qris',
    ) {}

    /**
     * @param  array{product_id: int|string, quantity: int|string, buyer_name: string, support_contact_channel?: ?string, support_contact_value?: ?string, payment_method?: string}  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            productId: (int) $validated['product_id'],
            quantity: (int) $validated['quantity'],
            buyerName: $validated['buyer_name'],
            supportContactChannel: $validated['support_contact_channel'] ?? null,
            supportContactValue: $validated['support_contact_value'] ?? null,
            paymentMethod: $validated['payment_method'] ?? 'qris',
        );
    }
}
