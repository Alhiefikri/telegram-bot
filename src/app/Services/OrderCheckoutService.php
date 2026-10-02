<?php

namespace App\Services;

use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidCheckout;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Catalog\CatalogFamilyIconResolver;
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
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use App\Services\Pricing\ProductQuantityPricing;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Support\PaymentProvider;
use App\Services\Suppliers\SupplierFulfillmentService;
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
        private readonly OrderLifecycle $lifecycle,
        private readonly SupplierFulfillmentService $supplierFulfillment,
    ) {}

    public function checkout(
        Cart $cart,
        ?CheckoutPaymentIntent $paymentIntent = null,
        ?string $checkoutKey = null,
        ?string $termsVersion = null,
        ?DateTimeInterface $termsAcceptedAt = null,
    ): Order {
        $paymentIntent ??= $this->providerResolver->resolve()->paymentIntent;
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
                    fn () => $this->checkoutOnce($cart, $paymentIntent, $checkoutKey, $preflight, $termsVersion, $termsAcceptedAt),
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
    ): Order {
        if ($checkoutKey !== null && ($existing = Order::query()->where('checkout_key', $checkoutKey)->first()) !== null) {
            $this->assertCheckoutRailCanBeReused($existing, $paymentIntent);

            return $existing;
        }

        $lockedCart = Cart::whereKey($cart->id)->lockForUpdate()->firstOrFail();

        if ($lockedCart->status === CartStatus::CheckedOut) {
            $existing = Order::where('cart_id', $lockedCart->id)->latest('id')->first();
            if ($existing?->order_status === OrderStatus::Cancelled) {
                $lockedCart->update(['status' => CartStatus::Active]);
            } elseif ($existing) {
                $this->assertCheckoutRailCanBeReused($existing, $paymentIntent);

                return $existing;
            }
        }

        if ($lockedCart->status !== CartStatus::Active || ($lockedCart->expires_at && $lockedCart->expires_at->isPast())) {
            throw new InvalidCheckout('Cart is not active.');
        }
        $storeBalanceCustomer = null;
        if (strtolower(trim($paymentIntent->provider())) === PaymentProvider::STORE_BALANCE) {
            $storeBalanceCustomer = Customer::query()->findOrFail($lockedCart->customer_id);
            if ($storeBalanceCustomer->telegram_id === null) {
                throw new InvalidCheckout('Store Balance checkout is available only for Telegram customers.');
            }

            // Store Balance checkout and refund paths both acquire the account
            // before any Product locks.
            $this->storeBalance->lockForTransaction($storeBalanceCustomer);
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

        $paymentPreparation = $this->paymentPreparations->for($paymentIntent);

        $preflightContext = new CheckoutPaymentPreflightContext(
            currentSetting: $activeSetting,
            requestedProvider: $paymentIntent->provider(),
            requestedMethod: $paymentIntent->method(),
        );
        if (! $preflight->matches($preflightContext)) {
            if ($preflight->hasState()) {
                throw new InvalidCheckout('Checkout payment preflight is stale.');
            }

            $preflight = CheckoutPaymentPreflight::none($preflightContext);
        }
        try {
            $pricing = CheckoutPricingSnapshot::fromCartItems($items, $products, $this->pricing);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidCheckout(
                'Checkout pricing is not available. Please try again later or contact support.',
                previous: $exception,
            );
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
        if ($isStoreBalance) {
            if ($paymentPlan->orderCurrency() !== 'IDR'
                || $paymentPlan->paymentCurrency() !== 'IDR'
                || $paymentPlan->amount() !== $pricing->idrTotal()
                || $storeBalanceCustomer === null) {
                throw new InvalidCheckout('Store Balance checkout requires the complete positive IDR Order total.');
            }
        }

        $order = Order::create(['customer_id' => $lockedCart->customer_id, 'cart_id' => $lockedCart->id, 'checkout_key' => $checkoutKey, 'terms_version' => $termsVersion, 'terms_accepted_at' => $termsAcceptedAt, 'invoice_number' => $this->numbers->invoiceNumber(), 'order_status' => OrderStatus::PendingPayment, 'payment_status' => PaymentStatus::Pending, 'total_amount' => $pricing->idrTotal(), 'currency' => $paymentPlan->orderCurrency(), 'expires_at' => $paymentPlan->orderExpiresAt()]);

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
        } else {
            $this->outboundEffects->recordCheckoutInstructions($order, $payment, $lockedCart->customer);
            $this->outboundEffects->recordAdminOrderPendingNotification($order);
        }

        $lockedCart->update(['status' => CartStatus::CheckedOut]);

        return $order->fresh(['orderItems', 'payments']);
    }

    private function assertStoreBalanceEligibility(Cart $cart, CheckoutPaymentIntent $paymentIntent): void
    {
        if (strtolower(trim($paymentIntent->provider())) !== PaymentProvider::STORE_BALANCE) {
            return;
        }

        $cart->loadMissing('customer');
        if ($cart->customer?->telegram_id === null) {
            throw new InvalidCheckout('Store Balance checkout is available only for Telegram customers.');
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
