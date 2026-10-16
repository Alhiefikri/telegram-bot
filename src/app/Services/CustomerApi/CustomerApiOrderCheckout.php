<?php

namespace App\Services\CustomerApi;

use App\Enums\CartOrigin;
use App\Enums\CartStatus;
use App\Exceptions\CheckoutPriceChanged;
use App\Exceptions\InsufficientCheckoutBalance;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InsufficientStoreBalance;
use App\Exceptions\InsufficientUsdtBalance;
use App\Exceptions\InvalidCheckout;
use App\Exceptions\MissingCheckoutPriceConsent;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\CustomerApiOrderClaim;
use App\Models\Order;
use App\Models\Product;
use App\Services\Checkout\BalanceCheckoutRequest;
use App\Services\OrderCheckoutService;
use App\Services\Pricing\ResellerPricingContext;
use App\Services\UsdtBalance\UsdtMicroAmount;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Creates exactly one paid, balance-funded API Order through the canonical
 * {@see OrderCheckoutService}, protected by a durable idempotency claim.
 *
 * The money guarantee does not depend on this class's coordination: the claim
 * row carries a unique (Customer, operation, key) guard, and the canonical
 * checkout key derived from the durable claim is unique on `orders`, so at most
 * one Order/Payment/debit/allocation can ever exist for one accepted key. A
 * financially rolled-back attempt releases its lease and is never accepted, so
 * a later retry can run the same local DB work again.
 */
final class CustomerApiOrderCheckout
{
    /** Advisory TTL for the isolated API Cart; it commits only as `checked_out`. */
    private const CART_TTL_MINUTES = 30;

    public function __construct(
        private readonly CustomerApiOrderClaims $claims,
        private readonly OrderCheckoutService $checkout,
    ) {}

    public function place(CustomerApiPrincipal $principal, CustomerApiOrderRequest $request): CustomerApiOrderResult
    {
        $acquisition = $this->claims->acquire($principal, $request);
        if ($acquisition->acceptedOrderId !== null) {
            return CustomerApiOrderResult::replay($this->acceptedOrder($principal, $acquisition->acceptedOrderId));
        }

        $claim = $acquisition->claim;
        try {
            // Only local DB work is retried; the canonical checkout owns the
            // transaction, its deterministic lock order and its own retries.
            return CustomerApiOrderResult::created(
                DB::transaction(fn (): Order => $this->commit($principal, $request, $claim), 3),
            );
        } catch (Throwable $error) {
            $this->claims->abandon($claim);

            throw $this->map($error);
        }
    }

    private function commit(CustomerApiPrincipal $principal, CustomerApiOrderRequest $request, CustomerApiOrderClaim $claim): Order
    {
        // The Customer row is the revocation-ordering resource: rotate/disable
        // take it exclusively before the credential row, so this shared lock
        // precedes the terminal credential fence and is held through commit.
        Customer::query()->whereKey($principal->customerId)->sharedLock()->firstOrFail();
        $principal->revalidateForMutation();

        // Serialize takeover with the whole financial commit, not only acceptance.
        $ownedClaim = CustomerApiOrderClaim::query()->whereKey($claim->getKey())->lockForUpdate()->firstOrFail();
        if ($ownedClaim->state !== CustomerApiOrderClaim::STATE_IN_PROGRESS
            || $ownedClaim->attempt_token !== $claim->attempt_token) {
            throw new CustomerApiFailure(409, 'in_progress', 'This request is owned by another attempt. Retry with the same Idempotency-Key.', ['Retry-After' => '2']);
        }

        $checkoutKey = $this->checkoutKey($claim);

        // Crash/lost-response recovery: an already committed Order for this
        // durable claim identity is adopted without current stock or price.
        if (($adopted = Order::query()->where('checkout_key', $checkoutKey)->first()) !== null) {
            $this->claims->accept($claim, $adopted);

            return $adopted->fresh(['orderItems', 'payments']);
        }

        if (! Product::query()->publicVisible()->whereKey($request->productId)->exists()) {
            throw new CustomerApiFailure(404, 'not_found', 'Resource not found.');
        }

        $cart = $this->openIsolatedCart($principal, $request);
        $order = $this->checkout->checkout(
            $cart,
            null,
            $checkoutKey,
            null,
            null,
            ResellerPricingContext::forAuthenticatedApiRequest($principal->customerId),
            new BalanceCheckoutRequest(
                // The resolved preference is the durable claim snapshot, so later
                // account edits, price edits, rotations and replays cannot change
                // this request's outcome.
                $claim->preference,
                $claim->expected_idr_total,
                $claim->expected_usd_total_micro,
                $claim->preference_source,
            ),
        );

        // A concurrent identical attempt won the unique checkout key: our cart
        // was never checked out, so remove it before this transaction commits.
        if ((int) $order->cart_id !== (int) $cart->getKey()) {
            Cart::query()->whereKey($cart->getKey())->delete();
        }

        $this->claims->accept($claim, $order);

        return $order;
    }

    /**
     * One isolated API Cart created inside the checkout transaction, so a
     * rolled-back request leaves zero cart rows and a committed API cart exists
     * only as `checked_out`. It is never the Customer's Telegram/Web Cart.
     */
    private function openIsolatedCart(CustomerApiPrincipal $principal, CustomerApiOrderRequest $request): Cart
    {
        $cart = Cart::create([
            'customer_id' => $principal->customerId,
            'origin' => CartOrigin::Api,
            'status' => CartStatus::Active,
            'expires_at' => now()->addMinutes(self::CART_TTL_MINUTES),
        ]);
        CartItem::create([
            'cart_id' => $cart->getKey(),
            'product_id' => $request->productId,
            'quantity' => $request->quantity,
        ]);

        return $cart;
    }

    /** Namespaced canonical checkout key derived only from the durable claim. */
    private function checkoutKey(CustomerApiOrderClaim $claim): string
    {
        return 'api-'.substr(hash('sha256', 'api-order:'.$claim->getKey()), 0, 60);
    }

    private function acceptedOrder(CustomerApiPrincipal $principal, string $orderId): Order
    {
        $order = Order::query()->whereKey($orderId)->where('customer_id', $principal->customerId)->first();
        if ($order === null) {
            throw new CustomerApiFailure(404, 'not_found', 'Resource not found.');
        }

        return $order;
    }

    /** Map canonical checkout outcomes to the documented API machine codes. */
    private function map(Throwable $error): CustomerApiFailure
    {
        if ($error instanceof CustomerApiFailure) {
            return $error;
        }
        if ($error instanceof CheckoutPriceChanged) {
            return new CustomerApiFailure(
                409,
                'price_changed',
                'Checkout prices changed; authorize fresh totals before purchasing.',
                [],
                ['quotes' => $this->changedQuotes($error)],
            );
        }
        if ($error instanceof MissingCheckoutPriceConsent) {
            return new CustomerApiFailure(422, 'invalid_input', 'An expected total is required for the primary currency of the resolved payment preference.');
        }
        if ($error instanceof InsufficientCheckoutBalance
            || $error instanceof InsufficientStoreBalance
            || $error instanceof InsufficientUsdtBalance) {
            return new CustomerApiFailure(402, 'insufficient_funds', 'No eligible balance wallet fully covers this order.');
        }
        if ($error instanceof InsufficientStock) {
            return new CustomerApiFailure(409, 'stock_conflict', 'The requested quantity is no longer available.');
        }
        if ($error instanceof InvalidCheckout) {
            return new CustomerApiFailure(422, 'invalid_input', 'The order could not be created as requested.');
        }

        // Unknown failures stay fail-closed 503 and never fabricate an outcome.
        return CustomerApiFailure::fromException($error);
    }

    /**
     * @return list<array{currency: string, total: string}>
     */
    private function changedQuotes(CheckoutPriceChanged $error): array
    {
        $quotes = [['currency' => 'IDR', 'total' => (string) $error->idrTotal]];
        if ($error->usdtTotal !== null) {
            $quotes[] = ['currency' => 'USD', 'total' => UsdtMicroAmount::formatUnits($error->usdtTotal)];
        }

        return $quotes;
    }
}
