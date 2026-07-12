<?php

namespace App\Services;

use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidCheckout;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class OrderCheckoutService
{
    public function __construct(
        private readonly InvoiceNumberGenerator $numbers,
        private readonly InventoryLedger $inventory,
        private readonly ProductCredentialAllocator $credentials,
    ) {}

    public function checkout(Cart $cart, string $paymentMethod = 'manual', string $provider = 'manual', ?CarbonInterface $expiresAt = null, ?string $checkoutKey = null): Order
    {
        $expiresAt ??= now()->addMinutes((int) config('orders.pending_payment_ttl_minutes', 60));

        $order = null;
        for ($i = 0; $i < 5; $i++) {
            try {
                $order = DB::transaction(fn () => $this->checkoutOnce($cart, $paymentMethod, $provider, $expiresAt, $checkoutKey), 5);

                return $order->fresh(['orderItems', 'payments']);
            } catch (QueryException $e) {
                if (! $this->isUniqueViolation($e) || $i === 4) {
                    throw $e;
                }
            }
        }

        throw new InvalidCheckout('Unable to create unique checkout references.');
    }

    private function checkoutOnce(Cart $cart, string $paymentMethod, string $provider, CarbonInterface $expiresAt, ?string $checkoutKey): Order
    {
        if ($checkoutKey !== null && ($existing = Order::query()->where('checkout_key', $checkoutKey)->first()) !== null) {
            return $existing;
        }

        $lockedCart = Cart::whereKey($cart->id)->lockForUpdate()->firstOrFail();

        if ($lockedCart->status === CartStatus::CheckedOut) {
            $existing = Order::where('cart_id', $lockedCart->id)->first();
            if ($existing) {
                return $existing;
            }
        }

        if ($lockedCart->status !== CartStatus::Active || ($lockedCart->expires_at && $lockedCart->expires_at->isPast())) {
            throw new InvalidCheckout('Cart is not active.');
        }

        $items = $lockedCart->cartItems()->orderBy('product_id')->lockForUpdate()->get();
        if ($items->isEmpty()) {
            throw new InvalidCheckout('Cart is empty.');
        }

        $products = $this->inventory->lockedCheckoutProducts($items);

        $total = $items->sum(fn ($item) => $products[$item->product_id]->price_amount * $item->quantity);
        $order = Order::create(['customer_id' => $lockedCart->customer_id, 'cart_id' => $lockedCart->id, 'checkout_key' => $checkoutKey, 'invoice_number' => $this->numbers->invoiceNumber(), 'order_status' => OrderStatus::PendingPayment, 'payment_status' => PaymentStatus::Pending, 'total_amount' => $total, 'currency' => 'IDR', 'expires_at' => $expiresAt]);
        Payment::create(['order_id' => $order->id, 'provider' => $provider, 'merchant_ref' => $this->numbers->merchantReference(), 'method' => $paymentMethod, 'amount' => $total, 'currency' => 'IDR', 'status' => PaymentStatus::Pending, 'expired_at' => $expiresAt]);

        foreach ($items as $item) {
            $product = $products[$item->product_id];
            $orderItem = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name, 'delivery_note' => $product->delivery_note, 'delivery_note_snapshotted' => true, 'quantity' => $item->quantity, 'unit_price' => $product->price_amount, 'subtotal' => $product->price_amount * $item->quantity]);
            $this->credentials->allocate($orderItem, $product, $item->quantity);
            $this->inventory->reserveOrderItem($order, $product, $item->quantity);
        }

        $lockedCart->update(['status' => CartStatus::CheckedOut]);

        return $order->fresh(['orderItems', 'payments']);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return in_array((string) ($e->errorInfo[0] ?? ''), ['23000', '23505'], true) || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
