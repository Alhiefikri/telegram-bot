<?php

namespace App\Services;

use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidCheckout;
use App\Models\Cart;
use App\Models\ManualPaymentSetting;
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
        $expiresAt ??= now()->addMinutes($this->paymentDeadlineMinutes($paymentMethod));

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
            $existing = Order::where('cart_id', $lockedCart->id)->latest('id')->first();
            if ($existing?->order_status === OrderStatus::Cancelled) {
                $lockedCart->update(['status' => CartStatus::Active]);
            } elseif ($existing) {
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
        $this->assertWebReservationBudget($lockedCart, $items, $products);

        $total = $items->sum(fn ($item) => $products[$item->product_id]->price_amount * $item->quantity);
        $usdtTotal = $provider === 'manual' && $paymentMethod === 'BINANCE_PAY_ID'
            ? $items->sum(function ($item) use ($products): int {
                $price = $products[$item->product_id]->usdt_price_amount;
                if ($price === null) {
                    throw new InvalidCheckout('A cart item has no USDT price.');
                }

                return $price * $item->quantity;
            }) : null;
        $order = Order::create(['customer_id' => $lockedCart->customer_id, 'cart_id' => $lockedCart->id, 'checkout_key' => $checkoutKey, 'invoice_number' => $this->numbers->invoiceNumber(), 'order_status' => OrderStatus::PendingPayment, 'payment_status' => PaymentStatus::Pending, 'total_amount' => $total, 'currency' => 'IDR', 'expires_at' => $expiresAt]);
        Payment::create(['order_id' => $order->id, 'provider' => $provider, 'merchant_ref' => $this->numbers->merchantReference(), 'method' => $paymentMethod, 'amount' => $usdtTotal ?? $total, 'currency' => $usdtTotal === null ? 'IDR' : 'USDT', 'status' => PaymentStatus::Pending, 'expired_at' => $expiresAt]);

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

    private function paymentDeadlineMinutes(string $paymentMethod): int
    {
        $settings = ManualPaymentSetting::current();

        if ($paymentMethod === 'BINANCE_PAY_ID') {
            return $settings?->binancePaymentTtlMinutes() ?? ManualPaymentSetting::DEFAULT_BINANCE_PAYMENT_TTL_MINUTES;
        }

        return $settings?->qrisPaymentTtlMinutes() ?? ManualPaymentSetting::DEFAULT_QRIS_PAYMENT_TTL_MINUTES;
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
