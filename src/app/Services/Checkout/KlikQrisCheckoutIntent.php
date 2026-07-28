<?php

namespace App\Services\Checkout;

use App\Exceptions\InvalidCheckout;
use App\Models\Cart;
use App\Services\KlikQris\KlikQrisProviderReadiness;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use RuntimeException;

final readonly class KlikQrisCheckoutIntent implements CheckoutPaymentIntent
{
    public function __construct(private ?CarbonInterface $deadline = null) {}

    public function provider(): string
    {
        return 'klikqris';
    }

    public function method(): string
    {
        return 'QRIS';
    }

    public function orderCurrency(): string
    {
        return 'IDR';
    }

    public function paymentAmount(Collection $items, Collection $products): int
    {
        return $items->sum(fn ($item): int => $products[$item->product_id]->price_amount * $item->quantity);
    }

    public function paymentCurrency(): string
    {
        return 'IDR';
    }

    public function expiresAt(?CarbonInterface $requested = null): CarbonInterface
    {
        return $requested
            ?? $this->deadline
            ?? now()->addMinutes((int) config('services.klikqris.qris_ttl_minutes', 15));
    }

    public function assertReady(Cart $cart, Collection $items, Collection $products): void
    {
        try {
            app(KlikQrisProviderReadiness::class)
                ->sandbox()
                ->assertReady('KlikQRIS sandbox checkout is not ready.');
        } catch (RuntimeException $exception) {
            throw new InvalidCheckout($exception->getMessage(), previous: $exception);
        }
    }

    public function postCreationBehavior(): CheckoutPostCreationBehavior
    {
        return CheckoutPostCreationBehavior::KlikQrisInstructions;
    }
}
