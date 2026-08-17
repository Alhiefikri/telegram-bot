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
use App\Services\Binance\BinancePayClient;
use App\Services\Binance\BinanceUniquePaymentAmount;
use App\Services\Checkout\CheckoutPaymentIntent;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\Checkout\PaymentProviderResolver;
use App\Services\Checkout\ProviderBoundCheckoutPaymentIntent;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class OrderCheckoutService
{
    public function __construct(
        private readonly InvoiceNumberGenerator $numbers,
        private readonly InventoryLedger $inventory,
        private readonly ProductCredentialAllocator $credentials,
        private readonly BinancePayClient $binance,
        private readonly BinanceUniquePaymentAmount $uniqueBinanceAmount,
        private readonly PaymentProviderResolver $providerResolver,
        private readonly CheckoutMaintenance $maintenance,
        private readonly TelegramOutboundEffectService $outboundEffects,
    ) {}

    public function checkout(Cart $cart, ?CheckoutPaymentIntent $paymentIntent = null, ?string $checkoutKey = null): Order
    {
        $paymentIntent ??= CheckoutPaymentIntents::manualQris();

        $order = null;
        for ($i = 0; $i < 5; $i++) {
            try {
                $order = DB::transaction(fn () => $this->checkoutOnce($cart, $paymentIntent, $checkoutKey), 5);

                return $order->fresh(['orderItems', 'payments']);
            } catch (QueryException $e) {
                if (! $this->isUniqueViolation($e) || $i === 4) {
                    throw $e;
                }
            }
        }

        throw new InvalidCheckout('Unable to create unique checkout references.');
    }

    private function checkoutOnce(Cart $cart, CheckoutPaymentIntent $paymentIntent, ?string $checkoutKey): Order
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
        $this->maintenance->guardNewOrder();

        $items = $lockedCart->cartItems()->orderBy('product_id')->lockForUpdate()->get();
        if ($items->isEmpty()) {
            throw new InvalidCheckout('Cart is empty.');
        }

        $products = $this->inventory->lockedCheckoutProducts($items);
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

        $expiresAt = $paymentIntent->expiresAt();
        $paymentIntent->assertReady($lockedCart, $items, $products);

        $total = $items->sum(fn ($item) => $products[$item->product_id]->price_amount * $item->quantity);
        $order = Order::create(['customer_id' => $lockedCart->customer_id, 'cart_id' => $lockedCart->id, 'checkout_key' => $checkoutKey, 'invoice_number' => $this->numbers->invoiceNumber(), 'order_status' => OrderStatus::PendingPayment, 'payment_status' => PaymentStatus::Pending, 'total_amount' => $total, 'currency' => $paymentIntent->orderCurrency(), 'expires_at' => $expiresAt]);
        $paymentMode = $paymentIntent->method() === 'BINANCE_PAY_ID' && method_exists($paymentIntent, 'verificationMode')
            ? $paymentIntent->verificationMode($lockedCart)
            : 'amount_unique';
        $receiverType = null;
        $receiverValue = null;
        if ($paymentIntent->method() === 'BINANCE_PAY_ID') {
            $receiverType = filled(config('services.binance.pay_id')) ? 'pay_id' : 'uid';
            $receiverValue = filled(config('services.binance.pay_id'))
                ? (string) config('services.binance.pay_id')
                : (string) config('services.binance.pay_uid');
        }
        $payment = Payment::create([
            'order_id' => $order->id,
            'provider' => $paymentIntent->provider(),
            'merchant_ref' => $this->numbers->merchantReference(),
            'method' => $paymentIntent->method(),
            'binance_auto_verification_enabled' => $paymentIntent->method() === 'BINANCE_PAY_ID' && $this->binance->configured(),
            'binance_verification_mode' => $paymentMode,
            'binance_receiver_type' => $receiverType,
            'binance_receiver_value' => $receiverValue,
            'binance_verification_status' => $paymentMode === 'order_id' ? 'awaiting_submission' : 'checking',
            'amount' => $this->paymentAmountFor($paymentIntent, $items, $products, $paymentMode),
            'currency' => $paymentIntent->paymentCurrency(),
            'status' => PaymentStatus::Pending,
            'expired_at' => $expiresAt,
        ]);

        foreach ($items as $item) {
            $product = $products[$item->product_id];
            $orderItem = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name, 'delivery_note' => $product->delivery_note, 'delivery_note_snapshotted' => true, 'usage_guide_title' => $product->usage_guide_title, 'usage_guide_url' => $product->usage_guide_url, 'usage_guide_snapshotted' => true, 'quantity' => $item->quantity, 'unit_price' => $product->price_amount, 'subtotal' => $product->price_amount * $item->quantity]);
            $this->credentials->allocate($orderItem, $product, $item->quantity);
            $this->inventory->reserveOrderItem($order, $product, $item->quantity);
        }

        $this->outboundEffects->recordCheckoutInstructions($order, $payment, $lockedCart->customer);

        $lockedCart->update(['status' => CartStatus::CheckedOut]);

        return $order->fresh(['orderItems', 'payments']);
    }

    private function paymentAmountFor(CheckoutPaymentIntent $paymentIntent, $items, $products, ?string $mode = null): int
    {
        $amount = $paymentIntent->paymentAmount($items, $products);
        $mode ??= $paymentIntent->method() === 'BINANCE_PAY_ID' && method_exists($paymentIntent, 'verificationMode')
            ? $paymentIntent->verificationMode()
            : null;

        return $paymentIntent->method() === 'BINANCE_PAY_ID' && $mode === 'amount_unique'
            ? $this->uniqueBinanceAmount->allocate($amount)
            : $amount;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return in_array((string) ($e->errorInfo[0] ?? ''), ['23000', '23505'], true) || str_contains(strtolower($e->getMessage()), 'unique');
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
