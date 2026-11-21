<?php

namespace App\Services\Checkout;

use App\Enums\CartOrigin;
use App\Enums\CartStatus;
use App\Exceptions\CheckoutPaymentUnavailable;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidCheckout;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductSupplierSource;
use App\Services\Checkout\PaymentPlan\TelegramStarsCheckoutPaymentSnapshot;
use App\Services\Credentials\UniqueCredentialEmailPolicy;
use App\Services\Pricing\ProductPriceQuote;
use App\Services\Pricing\ProductQuantityPricing;
use App\Services\TelegramStars\StarsPricingPolicy;
use App\Services\TelegramStars\StarsPricingPolicyManager;
use App\Services\TelegramStars\StarsQuoteCalculator;
use App\Support\PaymentProvider;
use DomainException;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final readonly class TelegramStarsCheckoutPaymentPreparation implements CheckoutPaymentPreparation
{
    public const IDENTITY = PaymentProvider::TELEGRAM_STARS.'|'.TelegramStarsCheckoutIntent::METHOD;

    public function __construct(
        private StarsPricingPolicyManager $policies,
        private StarsQuoteCalculator $calculator,
        private ProductQuantityPricing $pricing,
        private UniqueCredentialEmailPolicy $credentialEmails,
    ) {}

    /** Read-only preview for an existing Cart or a direct Product selection. */
    public function preview(Cart|Product $selection, TelegramStarsConfirmation $confirmation, ?int $quantity = null): TelegramStarsCheckoutQuote
    {
        $buyer = Customer::query()->find($confirmation->customerId);
        if ($buyer === null || ! $confirmation->matchesCustomer($buyer)) {
            throw new InvalidCheckout('Telegram Stars buyer is unavailable.');
        }

        $cart = $selection instanceof Cart ? $selection->fresh() : null;
        if ($selection instanceof Cart && ($cart === null || (int) $cart->customer_id !== $confirmation->customerId
                || $cart->origin !== CartOrigin::Customer || $cart->status !== CartStatus::Active
                || $cart->expires_at?->isPast())) {
            throw new InvalidCheckout('Cart is not available for Telegram Stars.');
        }

        $items = $cart?->cartItems()->orderBy('product_id')->get()
            ?? collect([(object) ['product_id' => $selection->getKey(), 'quantity' => $quantity]]);
        $products = Product::query()->withAvailability()->whereIn('id', $items->pluck('product_id'))->get()->keyBy('id');
        try {
            $pricing = CheckoutPricingSnapshot::fromCartItems($items, $products, $this->pricing);
        } catch (InvalidArgumentException $e) {
            throw new InvalidCheckout('Telegram Stars pricing is unavailable.', previous: $e);
        }
        $policy = $this->policies->current();
        $totalXtr = $this->amount($pricing, $policy);
        $this->assertLocalCredentials($products, $pricing, $confirmation->customerId);

        return new TelegramStarsCheckoutQuote(
            $confirmation, $cart?->getKey(), $this->fingerprint($pricing, $cart === null ? null : $items),
            $policy, $pricing->idrTotal(), $totalXtr, now()->toImmutable()->addMinutes(5),
        );
    }

    public function preflight(CheckoutPaymentPreflightContext $context): CheckoutPaymentPreflight
    {
        // No provider requests or mutable policy checks before replay resolution.
        return CheckoutPaymentPreflight::none($context);
    }

    public function prepare(CheckoutPaymentPreparationContext $context): CheckoutPaymentPlan
    {
        $intent = $context->intent;
        if (! $intent instanceof TelegramStarsCheckoutIntent || $context->lockedCustomer === null
            || ! $intent->confirmation->matchesCustomer($context->lockedCustomer)
            || (int) $context->cart->customer_id !== $intent->confirmation->customerId
            || $context->cart->origin !== ($intent->quote->cartId === null ? CartOrigin::TelegramDirect : CartOrigin::Customer)
            || $context->pricing->isResellerChannel()) {
            throw new InvalidCheckout('Telegram Stars is only available for private retail checkout.');
        }

        $quote = $intent->quote;
        $policy = $this->policies->lockForCheckout();
        if ($quote->confirmation != $intent->confirmation || ! $quote->expiresAt->isFuture()
            || ($quote->cartId !== null && $quote->cartId !== (int) $context->cart->getKey())
            || ($quote->cartId === null && $context->items->count() !== 1)
            || $quote->policy->values() !== $policy->values()
            || ! hash_equals($quote->selectionFingerprint, $this->fingerprint($context->pricing, $quote->cartId === null ? null : $context->items))
            || $quote->subtotalIdr !== $context->pricing->idrTotal()) {
            throw new CheckoutPaymentUnavailable('Telegram Stars quote changed. Request and confirm a new quote.', reason: 'snapshot_changed');
        }

        $totalXtr = $this->amount($context->pricing, $policy);
        if ($quote->totalXtr !== $totalXtr) {
            throw new CheckoutPaymentUnavailable('Telegram Stars quote changed. Request and confirm a new quote.', reason: 'snapshot_changed');
        }
        $this->assertLocalCredentials($context->products, $context->pricing);
        $snapshot = new TelegramStarsCheckoutPaymentSnapshot(
            $intent->confirmation, $policy, $context->pricing->idrTotal(), $this->retailLines($context->pricing),
            $totalXtr, now()->toImmutable()->addMinutes(15)->startOfSecond(),
        );

        return new CheckoutPaymentPlan(
            'IDR', $snapshot->expiresAt(), $snapshot->provider(), $snapshot->method(), 'XTR', $totalXtr,
            $snapshot->expiresAt(), $snapshot, $intent->postCreationBehavior(),
        );
    }

    private function amount(CheckoutPricingSnapshot $pricing, StarsPricingPolicy $policy): int
    {
        if (! $policy->issuanceEnabled) {
            throw new CheckoutPaymentUnavailable('Telegram Stars issuance is disabled.', reason: 'issuance_disabled');
        }

        try {
            return $this->calculator->quote($pricing->idrTotal(), $policy);
        } catch (DomainException $e) {
            throw new InvalidCheckout($e->getMessage(), previous: $e);
        }
    }

    private function assertLocalCredentials(Collection $products, CheckoutPricingSnapshot $pricing, ?int $previewCustomerId = null): void
    {
        if (ProductSupplierSource::query()->whereIn('product_id', $products->keys())->where('is_active', true)->exists()) {
            throw new InsufficientStock('Supplier products are not eligible for Telegram Stars.');
        }

        foreach ($pricing->quotes() as $line) {
            $product = $products->get($line->productId);
            if (! $product->isPublicVisible() || ! $product->isCredentialManaged()
                || $product->localAvailableStock() < $line->quantity || $product->supplierAvailableStock() > 0
                || ($previewCustomerId !== null && $product->unique_credential_email
                    && ! $this->credentialEmails->canAllocate($previewCustomerId, $line->productId, $line->quantity))) {
                throw new InsufficientStock('Telegram Stars requires full local credential stock.');
            }
        }
    }

    private function fingerprint(CheckoutPricingSnapshot $pricing, ?Collection $items): string
    {
        $cartItems = $items?->map(fn ($item): array => [$item->getKey(), $item->updated_at?->toISOString()])->values()->all();

        return hash('sha256', json_encode([$this->retailLines($pricing), $cartItems], JSON_THROW_ON_ERROR));
    }

    /** @return list<array{product_id: int, quantity: int, base_unit_idr: int, unit_idr: int, tier_minimum: ?int, subtotal_idr: int}> */
    private function retailLines(CheckoutPricingSnapshot $pricing): array
    {
        return collect($pricing->quotes())
            ->sortBy('productId')
            ->map(fn (ProductPriceQuote $line): array => [
                'product_id' => $line->productId,
                'quantity' => $line->quantity,
                'base_unit_idr' => $line->baseIdrUnitAmount,
                'unit_idr' => $line->effectiveIdrUnitAmount,
                'tier_minimum' => $line->appliedTierMinimum,
                'subtotal_idr' => $line->idrSubtotal,
            ])->values()->all();
    }
}
