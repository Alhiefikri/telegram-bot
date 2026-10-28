<?php

namespace App\Services;

use App\Enums\BalancePaymentPreference;
use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\CheckoutPaymentUnavailable;
use App\Exceptions\InvalidCheckout;
use App\Models\BalanceRestoreGate;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\CustomerUsdtFundingPolicy;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Catalog\CatalogFamilyIconResolver;
use App\Services\Checkout\BalanceCheckoutRequest;
use App\Services\Checkout\CheckoutPaymentIntent;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\Checkout\CheckoutPaymentPlanWriter;
use App\Services\Checkout\CheckoutPaymentPreflight;
use App\Services\Checkout\CheckoutPaymentPreflightContext;
use App\Services\Checkout\CheckoutPaymentPreparationContext;
use App\Services\Checkout\CheckoutPaymentPreparationResolver;
use App\Services\Checkout\CheckoutPostCreationBehavior;
use App\Services\Checkout\CheckoutPricingSnapshot;
use App\Services\Checkout\PaymentProviderResolver;
use App\Services\Checkout\ProviderBoundCheckoutPaymentIntent;
use App\Services\Checkout\UsdtBalanceCheckoutPaymentPreparation;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use App\Services\Pricing\ProductQuantityPricing;
use App\Services\Pricing\ResellerPricingContext;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\Suppliers\SupplierFulfillmentService;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\UsdtPurchase;
use App\Services\UsdtFunding\CustomerUsdtFundingGate;
use App\Support\PaymentProvider;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OrderCheckoutService
{
    public function __construct(
        private readonly InvoiceNumberGenerator $numbers,
        private readonly InventoryLedger $inventory,
        private readonly CatalogFamilyIconResolver $familyIcons,
        private readonly ProductCredentialAllocator $credentials,
        private readonly PaymentProviderResolver $providerResolver,
        private readonly CheckoutPaymentPreparationResolver $paymentPreparations,
        private readonly CheckoutPaymentPlanWriter $paymentPlanWriter,
        private readonly ProductQuantityPricing $pricing,
        private readonly CheckoutMaintenance $maintenance,
        private readonly TelegramOutboundEffectService $outboundEffects,
        private readonly StoreBalanceLedger $storeBalance,
        private readonly CustomerUsdtBalanceLedger $usdtBalance,
        private readonly CustomerUsdtFundingGate $usdtFundingGate,
        private readonly OrderLifecycle $lifecycle,
        private readonly SupplierFulfillmentService $supplierFulfillment,
    ) {}

    public function checkout(
        Cart $cart,
        ?CheckoutPaymentIntent $paymentIntent = null,
        ?string $checkoutKey = null,
        ?string $termsVersion = null,
        ?DateTimeInterface $termsAcceptedAt = null,
        ?ResellerPricingContext $resellerContext = null,
        ?BalanceCheckoutRequest $balanceRequest = null,
        ?string $apiOrderClaimId = null,
    ): Order {
        if ($balanceRequest !== null && $paymentIntent !== null) {
            throw new InvalidCheckout('A preference request cannot override an explicit payment rail.');
        }
        $paymentIntent ??= $balanceRequest !== null
            ? CheckoutPaymentIntents::storeBalance()
            : $this->providerResolver->resolve()->paymentIntent;
        $this->assertStoreBalanceEligibility($cart, $paymentIntent);
        if (($termsVersion === null) !== ($termsAcceptedAt === null)) {
            throw new InvalidCheckout('Checkout terms snapshot is incomplete.');
        }
        if (strtolower(trim($paymentIntent->provider())) === PaymentProvider::PAKASIR) {
            throw new InvalidCheckout('Pakasir sudah dipensiunkan untuk checkout baru.');
        }
        $paymentPreparation = $this->paymentPreparations->for($paymentIntent);
        $preflight = $paymentPreparation->preflight(new CheckoutPaymentPreflightContext(
            currentSetting: ManualPaymentSetting::current(),
            requestedProvider: $paymentIntent->provider(),
            requestedMethod: $paymentIntent->method(),
        ));

        $order = null;
        for ($i = 0; $i < 5; $i++) {
            try {
                $order = DB::transaction(
                    fn () => $this->checkoutOnce($cart, $paymentIntent, $checkoutKey, $preflight, $termsVersion, $termsAcceptedAt, $resellerContext, $balanceRequest, $apiOrderClaimId),
                    5,
                );

                return $order->fresh(['orderItems', 'payments']);
            } catch (QueryException $e) {
                if (! $this->isUniqueViolation($e) || $i === 4) {
                    throw $e;
                }
            }
        }

        throw new InvalidCheckout('Unable to create unique checkout references.');
    }

    private function checkoutOnce(
        Cart $cart,
        CheckoutPaymentIntent $paymentIntent,
        ?string $checkoutKey,
        CheckoutPaymentPreflight $preflight,
        ?string $termsVersion,
        ?DateTimeInterface $termsAcceptedAt,
        ?ResellerPricingContext $resellerContext = null,
        ?BalanceCheckoutRequest $balanceRequest = null,
        ?string $apiOrderClaimId = null,
    ): Order {
        if ($checkoutKey !== null && ($existing = Order::query()->where('checkout_key', $checkoutKey)->first()) !== null) {
            $this->assertCheckoutCustomer($existing, $cart);
            $this->assertCheckoutRailCanBeReused($existing, $paymentIntent);

            return $existing;
        }

        $balanceCustomer = null;
        if (in_array(strtolower(trim($paymentIntent->provider())), [PaymentProvider::STORE_BALANCE, PaymentProvider::USDT_BALANCE], true)) {
            // CartService takes Customer before Cart. This shared prefix also
            // fences preference edits and first-account FK creation vs top-ups.
            $customerId = Cart::query()->whereKey($cart->id)->value('customer_id');
            $balanceCustomer = Customer::query()->whereKey($customerId)->sharedLock()->firstOrFail();
        }

        $lockedCart = Cart::whereKey($cart->id)->lockForUpdate()->firstOrFail();

        if ($lockedCart->status === CartStatus::CheckedOut) {
            $existing = Order::where('cart_id', $lockedCart->id)->latest('id')->first();
            if ($existing?->order_status === OrderStatus::Cancelled) {
                $lockedCart->update(['status' => CartStatus::Active]);
            } elseif ($existing) {
                $this->assertCheckoutCustomer($existing, $lockedCart);
                $this->assertCheckoutRailCanBeReused($existing, $paymentIntent);

                return $existing;
            }
        }

        if ($lockedCart->status !== CartStatus::Active || ($lockedCart->expires_at && $lockedCart->expires_at->isPast())) {
            throw new InvalidCheckout('Cart is not active.');
        }
        $storeBalanceCustomer = null;
        $usdtBalanceCustomer = null;
        $idrAccount = null;
        $usdtAccount = null;
        $resolvedPreference = null;
        $provider = strtolower(trim($paymentIntent->provider()));
        if ($balanceRequest !== null || in_array($provider, [PaymentProvider::STORE_BALANCE, PaymentProvider::USDT_BALANCE], true)) {
            $customer = $balanceCustomer;
            if ($customer === null || (int) $customer->id !== (int) $lockedCart->customer_id) {
                throw new InvalidCheckout('Checkout Customer changed.');
            }
            if ($customer->telegram_id === null) {
                throw new InvalidCheckout('Balance checkout is available only for Telegram customers.');
            }
            $resolvedPreference = $balanceRequest === null
                ? ($provider === PaymentProvider::STORE_BALANCE ? BalancePaymentPreference::IdrOnly : BalancePaymentPreference::UsdOnly)
                : ($balanceRequest->preference ?? $customer->balance_payment_preference);
            $currencies = $resolvedPreference->currencies();

            // Always IDR → USDT, regardless of preference. Both automatic
            // candidates must be healthy before any inventory/supplier locks.
            if (in_array('IDR', $currencies, true)) {
                $storeBalanceCustomer = $customer;
                $idrAccount = $this->storeBalance->lockForPurchase($customer);
            }
            if (in_array('USDT', $currencies, true)) {
                $usdtBalanceCustomer = $customer;
                $usdtAccount = $this->usdtBalance->lockForTransaction($customer);
            }
            CustomerUsdtFundingPolicy::query()
                ->where('singleton_key', CustomerUsdtFundingPolicy::SINGLETON_KEY)
                ->sharedLock()->first();
            if (BalanceRestoreGate::query()->whereKey(BalanceRestoreGate::SINGLETON_ID)->sharedLock()->first() === null) {
                throw new InvalidCheckout('Balance restore state is unavailable.');
            }
            $this->usdtFundingGate->assertSpendingAvailable();
        }

        // Every checkout rail acquires the maintenance singleton before any
        // CartItem/Product or ManualPaymentSetting lock.
        $this->maintenance->guardNewOrder();

        $items = $lockedCart->cartItems()->orderBy('product_id')->lockForUpdate()->get();
        if ($items->isEmpty()) {
            throw new InvalidCheckout('Cart is empty.');
        }

        $products = $this->inventory->lockedCheckoutProducts($items);
        $this->supplierFulfillment->lockCheckoutResources($products);
        $this->assertWebReservationBudget($lockedCart, $items, $products);

        // Provider switch mutations and checkout creation share this lock so a
        // selected intent is persisted atomically with its order and payment.
        $lockedSetting = ManualPaymentSetting::query()
            ->where('is_active', true)
            ->oldest('id')
            ->lockForUpdate()
            ->first();

        if ($lockedSetting === null) {
            $lockedSetting = ManualPaymentSetting::query()
                ->oldest('id')
                ->lockForUpdate()
                ->first();
        }

        $activeSetting = $lockedSetting?->is_active ? $lockedSetting : null;
        if ($paymentIntent instanceof ProviderBoundCheckoutPaymentIntent
            && ! $this->providerResolver->matchesCurrentSetting($paymentIntent, $activeSetting)) {
            $paymentIntent = $this->providerResolver->resolve(setting: $activeSetting)->paymentIntent;
        }

        try {
            $pricing = CheckoutPricingSnapshot::fromCartItems($items, $products, $this->pricing, $resellerContext);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidCheckout(
                'Checkout pricing is not available. Please try again later or contact support.',
                previous: $exception,
            );
        }
        if ($balanceRequest !== null) {
            $paymentIntent = $balanceRequest->select($resolvedPreference, $pricing, $idrAccount?->balance, $usdtAccount?->balance_micro);
        }
        $paymentPreparation = $this->paymentPreparations->for($paymentIntent);
        $preflightContext = new CheckoutPaymentPreflightContext(
            currentSetting: $activeSetting,
            requestedProvider: $paymentIntent->provider(),
            requestedMethod: $paymentIntent->method(),
        );
        if (! $preflight->matches($preflightContext)) {
            if ($preflight->hasState()) {
                throw new CheckoutPaymentUnavailable('Checkout payment preflight is stale.', reason: 'snapshot_changed');
            }

            $preflight = CheckoutPaymentPreflight::none($preflightContext);
        }
        $paymentPlan = $paymentPreparation->prepare(new CheckoutPaymentPreparationContext(
            cart: $lockedCart,
            items: $items,
            products: $products,
            lockedSetting: $activeSetting,
            preflight: $preflight,
            pricing: $pricing,
        ));
        $isStoreBalance = $paymentPlan->postCreationBehavior() === CheckoutPostCreationBehavior::StoreBalancePaid;
        $isUsdtBalance = $paymentPlan->postCreationBehavior() === CheckoutPostCreationBehavior::UsdtBalancePaid;
        if ($isUsdtBalance) {
            if ($paymentPlan->provider() !== PaymentProvider::USDT_BALANCE
                || $paymentPlan->method() !== UsdtBalanceCheckoutPaymentPreparation::METHOD
                || $paymentPlan->orderCurrency() !== 'USDT'
                || $paymentPlan->paymentCurrency() !== 'USDT'
                || $paymentPlan->amount() !== $pricing->usdtTotal()
                || $usdtBalanceCustomer === null) {
                throw new InvalidCheckout('USDT Balance checkout requires the complete positive micro-USDT Order total.');
            }
        }
        if ($isStoreBalance) {
            if ($paymentPlan->orderCurrency() !== 'IDR'
                || $paymentPlan->paymentCurrency() !== 'IDR'
                || $paymentPlan->amount() !== $pricing->idrTotal()
                || $storeBalanceCustomer === null) {
                throw new InvalidCheckout('Store Balance checkout requires the complete positive IDR Order total.');
            }
        }

        $order = new Order(['customer_id' => $lockedCart->customer_id, 'cart_id' => $lockedCart->id, 'checkout_key' => $checkoutKey, 'terms_version' => $termsVersion, 'terms_accepted_at' => $termsAcceptedAt, 'invoice_number' => $this->numbers->invoiceNumber(), 'order_status' => OrderStatus::PendingPayment, 'payment_status' => PaymentStatus::Pending, 'total_amount' => $pricing->idrTotal(), 'currency' => $paymentPlan->orderCurrency(), 'expires_at' => $paymentPlan->orderExpiresAt()]);
        if ($apiOrderClaimId !== null) {
            $order->forceFill(['api_order_claim_id' => $apiOrderClaimId]);
        }
        if ($resolvedPreference !== null) {
            $order->forceFill(['balance_checkout_snapshot' => [
                'preference' => $resolvedPreference->value,
                'source' => $balanceRequest === null ? 'explicit_rail' : ($balanceRequest->preferenceSource ?? ($balanceRequest->preference === null ? 'account' : 'override')),
                'currency' => $paymentPlan->paymentCurrency(),
                'amount' => $paymentPlan->amount(),
                'expected_idr_total' => $balanceRequest?->expectedIdrTotal,
                'expected_usd_total_micro' => $balanceRequest?->expectedUsdTotalMicro,
            ]]);
        }
        $order->save();

        $payment = $this->paymentPlanWriter->persist($order, $paymentPlan);

        $productIcons = $this->familyIcons->forProducts($products);

        foreach ($items as $item) {
            $product = $products[$item->product_id];
            $quote = $pricing->quoteForItem($item);
            $isUsdtPayment = $paymentPlan->paymentCurrency() === 'USDT';
            $usdtUnitPrice = $isUsdtPayment ? $quote->effectiveUsdtUnitAmount : null;
            $usdtSubtotal = $isUsdtPayment ? $quote->usdtSubtotal : null;
            $productIcon = $productIcons[(int) $product->getKey()] ?? $this->familyIcons->forProduct($product);
            $localQuantity = min($item->quantity, $product->localAvailableStock());
            $supplierQuantity = $item->quantity - $localQuantity;
            $orderItem = OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_icon' => $productIcon->icon,
                'product_custom_emoji_id' => $productIcon->customEmojiId,
                'family_id' => $product->product_family_id,
                'family_name' => $product->family?->name,
                'delivery_note' => $product->delivery_note,
                'delivery_note_snapshotted' => true,
                'usage_guide_title' => $product->usage_guide_title,
                'usage_guide_url' => $product->usage_guide_url,
                'usage_guide_snapshotted' => true,
                'quantity' => $item->quantity,
                'local_quantity' => $localQuantity,
                'supplier_quantity' => $supplierQuantity,
                'quantity_price_tier_minimum' => $quote->appliedTierMinimum,
                'unit_price' => $quote->effectiveIdrUnitAmount,
                'usdt_unit_price' => $usdtUnitPrice,
                'subtotal' => $quote->idrSubtotal,
                'usdt_subtotal' => $usdtSubtotal,
            ]);
            $this->credentials->allocate($orderItem, $product, $localQuantity);
            if ($supplierQuantity > 0) {
                $this->supplierFulfillment->reserve($orderItem, $product, $supplierQuantity, $quote->idrSubtotal);
            }
            $this->inventory->reserveOrderItem($order, $product, $item->quantity);
        }

        if ($isStoreBalance) {
            // Store Balance account lock was acquired before Product locks;
            // debit and paid lifecycle remain inside this transaction.
            $this->storeBalance->debit(
                customer: $storeBalanceCustomer,
                amount: $pricing->idrTotal(),
                sourceType: 'order',
                sourceId: (string) $order->getKey(),
                idempotencyKey: 'order:'.$order->getKey().':debit',
            );
            $this->lifecycle->applyPaymentPaid($payment);
        } elseif ($isUsdtBalance) {
            $this->usdtBalance->debitPurchase(
                $usdtBalanceCustomer,
                new UsdtPurchase(
                    (int) $usdtBalanceCustomer->getKey(),
                    UsdtMicroAmount::fromUnits($paymentPlan->amount()),
                    (string) $order->getKey(),
                    (string) $payment->getKey(),
                ),
                'order:'.$order->getKey().':usdt:debit',
            );
            $this->lifecycle->applyPaymentPaid($payment);
        } else {
            $this->outboundEffects->recordCheckoutInstructions($order, $payment, $lockedCart->customer);
            $this->outboundEffects->recordAdminOrderPendingNotification($order);
        }

        $lockedCart->update(['status' => CartStatus::CheckedOut]);

        return $order->fresh(['orderItems', 'payments']);
    }

    private function assertStoreBalanceEligibility(Cart $cart, CheckoutPaymentIntent $paymentIntent): void
    {
        if (! in_array(strtolower(trim($paymentIntent->provider())), [PaymentProvider::STORE_BALANCE, PaymentProvider::USDT_BALANCE], true)) {
            return;
        }

        $cart->loadMissing('customer');
        if ($cart->customer?->telegram_id === null) {
            throw new InvalidCheckout('Balance checkout is available only for Telegram customers.');
        }
    }

    private function assertCheckoutCustomer(Order $order, Cart $cart): void
    {
        $customerId = Cart::query()->whereKey($cart->getKey())->value('customer_id');
        if ($customerId === null || (int) $order->customer_id !== (int) $customerId) {
            throw new InvalidCheckout('Checkout key belongs to another Customer.');
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return in_array((string) ($e->errorInfo[0] ?? ''), ['23000', '23505'], true) || str_contains(strtolower($e->getMessage()), 'unique');
    }

    private function assertCheckoutRailCanBeReused(Order $order, CheckoutPaymentIntent $paymentIntent): void
    {
        $existingMethod = $order->payments()->latest('created_at')->value('method');
        if ($existingMethod === null || $existingMethod === $paymentIntent->method()) {
            return;
        }

        if (! CheckoutPaymentIntents::isBinancePaymentMethod($existingMethod)
            && ! CheckoutPaymentIntents::isBinancePaymentMethod($paymentIntent->method())) {
            return;
        }

        throw new InvalidCheckout('Changing the Binance payment rail requires cancelling the existing Order and starting checkout again.');
    }

    private function assertWebReservationBudget(Cart $cart, $items, $products): void
    {
        if ($cart->customer?->telegram_id !== null) {
            return;
        }

        $floor = max(0, (int) config('services.storefront.telegram_reservation_floor', 1));
        if ($floor === 0) {
            return;
        }

        foreach ($items as $item) {
            $product = $products[$item->product_id];
            if ($product->availableStock() - $item->quantity < $floor) {
                throw new InvalidCheckout('Stok terakhir disisakan untuk pembelian lewat Telegram. Pilih jumlah lebih kecil atau coba lagi nanti.');
            }
        }
    }
}
