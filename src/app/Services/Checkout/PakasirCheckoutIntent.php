<?php

namespace App\Services\Checkout;

use App\Exceptions\InvalidCheckout;
use App\Models\Cart;
use App\Models\ManualPaymentSetting;
use App\Services\Pakasir\PakasirProviderReadiness;
use App\Support\PakasirCheckoutFlow;
use App\Support\PaymentProvider;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final readonly class PakasirCheckoutIntent implements CheckoutPaymentIntent
{
    public function __construct(
        private string $checkoutFlow,
        private ?CarbonInterface $deadline = null,
    ) {
        if (! PakasirCheckoutFlow::isAllowed($checkoutFlow)) {
            throw new InvalidCheckout(PakasirCheckoutFlow::validationMessage());
        }
    }

    public function provider(): string
    {
        return PaymentProvider::PAKASIR;
    }

    public function method(): string
    {
        return $this->checkoutFlow;
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
            ?? now()->addMinutes(ManualPaymentSetting::current()?->qrisPaymentTtlMinutes() ?? ManualPaymentSetting::DEFAULT_QRIS_PAYMENT_TTL_MINUTES);
    }

    public function assertReady(Cart $cart, Collection $items, Collection $products): void
    {
        $readiness = app(PakasirProviderReadiness::class)->checkout($this->checkoutFlow);
        if (! $readiness->isReady()) {
            throw new InvalidCheckout(trim('Pakasir payment rail is not ready. '.$readiness->configurationMessage()));
        }
    }

    public function postCreationBehavior(): CheckoutPostCreationBehavior
    {
        return CheckoutPostCreationBehavior::PakasirInstructions;
    }
}
