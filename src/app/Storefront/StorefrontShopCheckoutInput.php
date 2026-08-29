<?php

namespace App\Storefront;

final readonly class StorefrontShopCheckoutInput
{
    public function __construct(
        public ?int $productId = null,
        public int $quantity = 1,
        public string $buyerName = '',
        public ?string $supportContactChannel = null,
        public ?string $supportContactValue = null,
        public string $paymentMethod = 'qris',
        public ?string $email = null,
        public bool $termsAccepted = false,
        public ?string $cartSnapshot = null,
    ) {}

    /**
     * @param  array{product_id?: int|string|null, quantity?: int|string, buyer_name: string, support_contact_channel?: ?string, support_contact_value?: ?string, payment_method?: string, email?: ?string, terms_accepted?: bool|string|int, cart_snapshot?: ?string}  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            productId: ! array_key_exists('product_id', $validated) || $validated['product_id'] === null
                ? null
                : (int) $validated['product_id'],
            quantity: (int) ($validated['quantity'] ?? 1),
            buyerName: $validated['buyer_name'],
            supportContactChannel: $validated['support_contact_channel'] ?? null,
            supportContactValue: $validated['support_contact_value'] ?? null,
            paymentMethod: $validated['payment_method'] ?? 'qris',
            email: $validated['email'] ?? null,
            termsAccepted: (bool) ($validated['terms_accepted'] ?? false),
            cartSnapshot: $validated['cart_snapshot'] ?? null,
        );
    }
}
