<?php

namespace App\Services;

use App\Enums\CartStatus;
use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidCheckout;
use App\Models\Cart;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class OrderCheckoutService
{
    public function __construct(private readonly InvoiceNumberGenerator $numbers) {}

    public function checkout(Cart $cart, string $paymentMethod = 'manual', string $provider = 'manual', ?CarbonInterface $expiresAt = null): Order
    {
        $expiresAt ??= now()->addMinutes((int) config('orders.pending_payment_ttl_minutes', 60));

        for ($i = 0; $i < 5; $i++) {
            try {
                return DB::transaction(fn () => $this->checkoutOnce($cart, $paymentMethod, $provider, $expiresAt), 5);
            } catch (QueryException $e) {
                if (! $this->isUniqueViolation($e) || $i === 4) {
                    throw $e;
                }
            }
        }

        throw new InvalidCheckout('Unable to create unique checkout references.');
    }

    private function checkoutOnce(Cart $cart, string $paymentMethod, string $provider, CarbonInterface $expiresAt): Order
    {
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

        $products = Product::withTrashed()->whereIn('id', $items->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($items as $item) {
            $product = $products[$item->product_id] ?? null;
            if (! $product || ! $product->is_active || $product->trashed()) {
                throw new InvalidCheckout('Cart contains unavailable product.');
            }
            if (($product->stock - $product->reserved_stock) < $item->quantity) {
                throw new InsufficientStock('Insufficient available stock.');
            }
        }

        $total = $items->sum(fn ($item) => $products[$item->product_id]->price_amount * $item->quantity);
        $order = Order::create(['customer_id' => $lockedCart->customer_id, 'cart_id' => $lockedCart->id, 'invoice_number' => $this->numbers->invoiceNumber(), 'order_status' => OrderStatus::PendingPayment, 'payment_status' => PaymentStatus::Pending, 'total_amount' => $total, 'currency' => 'IDR', 'expires_at' => $expiresAt]);
        Payment::create(['order_id' => $order->id, 'provider' => $provider, 'merchant_ref' => $this->numbers->merchantReference(), 'method' => $paymentMethod, 'amount' => $total, 'currency' => 'IDR', 'status' => PaymentStatus::Pending, 'expired_at' => $expiresAt]);

        foreach ($items as $item) {
            $product = $products[$item->product_id];
            OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => $item->quantity, 'unit_price' => $product->price_amount, 'subtotal' => $product->price_amount * $item->quantity]);
            $product->increment('reserved_stock', $item->quantity);
            InventoryMovement::create(['product_id' => $product->id, 'order_id' => $order->id, 'type' => InventoryMovementType::Reservation, 'quantity' => $item->quantity, 'notes' => 'Order reservation', 'created_at' => now()]);
        }

        $lockedCart->update(['status' => CartStatus::CheckedOut]);

        return $order->fresh(['orderItems', 'payments']);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return in_array((string) ($e->errorInfo[0] ?? ''), ['23000', '23505'], true) || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
