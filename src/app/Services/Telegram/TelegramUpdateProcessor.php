<?php

namespace App\Services\Telegram;

use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidCartState;
use App\Exceptions\InvalidCheckout;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\TelegramUpdate;
use App\Services\CartService;
use App\Services\OrderCheckoutService;
use App\Services\Tripay\TripayClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Throwable;

class TelegramUpdateProcessor
{
    public function __construct(private TelegramBotClient $bot, private CartService $carts, private OrderCheckoutService $checkout, private TripayClient $tripay) {}

    public function process(TelegramUpdate $update): void
    {
        if ($update->processed_at) {
            return;
        }

        $lock = Cache::lock('telegram-update:'.$update->id, 60);
        if (! $lock->get()) {
            return;
        }

        try {
            $update->refresh();
            if ($update->processed_at) {
                return;
            }

            $payload = $update->payload;
            if ($message = Arr::get($payload, 'message')) {
                $customer = $this->customer(Arr::get($message, 'from', []));
                $update->update(['customer_id' => $customer->id]);
                $this->message($customer, (string) Arr::get($message, 'chat.id'), trim((string) Arr::get($message, 'text', '')));
            } elseif ($callback = Arr::get($payload, 'callback_query')) {
                $customer = $this->customer(Arr::get($callback, 'from', []));
                $update->update(['customer_id' => $customer->id]);
                $this->callback($customer, (string) Arr::get($callback, 'message.chat.id'), (string) Arr::get($callback, 'id'), (string) Arr::get($callback, 'data'));
            }

            $update->update(['processed_at' => now()]);
        } finally {
            $lock->release();
        }
    }

    private function message(Customer $customer, string $chatId, string $text): void
    {
        if ($text === '/start') {
            Cache::forget($this->intentKey($customer));
            $this->bot->sendMessage($chatId, 'Selamat datang! Ketik /katalog untuk melihat produk.');

            return;
        }
        if ($text === '/katalog') {
            Cache::forget($this->intentKey($customer));
            $products = Product::where('is_active', true)->orderBy('id')->get();
            $keyboard = $products->map(fn ($p) => [['text' => $p->name.' - Rp '.number_format($p->price_amount, 0, ',', '.'), 'callback_data' => 'product:'.$p->id]])->values()->all();
            $this->bot->sendMessage($chatId, $products->isEmpty() ? 'Katalog kosong.' : 'Pilih produk:', ['inline_keyboard' => $keyboard]);

            return;
        }
        if ($text === '/batal') {
            Cache::forget($this->intentKey($customer));
            $customer->carts()->where('status', CartStatus::Active)->update(['status' => CartStatus::Abandoned]);
            $this->bot->sendMessage($chatId, 'Keranjang aktif dibatalkan.');

            return;
        }
        if (in_array($text, ['/status', '/pesanan'], true)) {
            Cache::forget($this->intentKey($customer));
            $order = $customer->orders()->latest()->first();
            $this->bot->sendMessage($chatId, $order ? 'Status pesanan '.$order->invoice_number.': '.$order->order_status->value.' / '.$order->payment_status->value : 'Belum ada pesanan.');

            return;
        }
        if (ctype_digit($text) && ($productId = Cache::pull($this->intentKey($customer)))) {
            $quantity = (int) $text;
            if ($quantity < 1) {
                $this->bot->sendMessage($chatId, 'Jumlah tidak valid.');

                return;
            }
            try {
                $product = Product::findOrFail($productId);
                $cart = $this->carts->activeCartFor($customer);
                $this->carts->addItem($cart, $product, $quantity);
                $this->bot->sendMessage($chatId, "Keranjang: {$product->name} x {$quantity}", ['inline_keyboard' => [[['text' => 'Checkout', 'callback_data' => 'checkout']]]]);
            } catch (Throwable) {
                $this->bot->sendMessage($chatId, 'Produk tidak tersedia atau stok tidak cukup.');
            }

            return;
        }
        Cache::forget($this->intentKey($customer));
        $this->bot->sendMessage($chatId, 'Perintah tidak dikenal. Ketik /katalog.');
    }

    private function callback(Customer $customer, string $chatId, string $id, string $data): void
    {
        if (preg_match('/^product:([1-9][0-9]*)$/', $data, $matches)) {
            $productId = (int) $matches[1];
            $product = Product::whereKey($productId)->where('is_active', true)->first();
            if (! $product || ($product->stock - $product->reserved_stock) < 1) {
                $this->bot->answerCallbackQuery($id, 'Produk tidak tersedia.');

                return;
            }
            $this->bot->answerCallbackQuery($id);
            Cache::put($this->intentKey($customer), $product->id, now()->addMinutes(15));
            $this->bot->sendMessage($chatId, 'Masukkan jumlah untuk '.$product->name.':');

            return;
        }
        if ($data === 'checkout') {
            $this->bot->answerCallbackQuery($id);
            $existing = $this->pendingTripayOrder($customer);
            if ($existing) {
                $payment = $existing->payments()->where('provider', 'tripay')->latest()->firstOrFail();
                if (! $payment->provider_reference) {
                    $this->tripay->createTransaction($payment);
                    $payment->refresh();
                }
                $this->sendPaymentInstructions($chatId, $existing->fresh(), $payment);

                return;
            }
            try {
                $order = $this->checkout->checkout($this->carts->activeCartFor($customer), config('services.telegram.default_payment_method', 'BRIVA'), 'tripay');
            } catch (InvalidCheckout|InvalidCartState|InsufficientStock) {
                $this->bot->sendMessage($chatId, 'Checkout gagal. Pastikan keranjang tidak kosong.');

                return;
            }
            $this->sendPaymentInstructions($chatId, $order, $order->payments()->latest()->first());

            return;
        }

        $this->bot->answerCallbackQuery($id, 'Aksi tidak dikenal.');
    }

    private function pendingTripayOrder(Customer $customer): ?Order
    {
        return $customer->orders()->where('order_status', OrderStatus::PendingPayment)->whereHas('payments', fn ($q) => $q->where('provider', 'tripay'))->latest()->first();
    }

    private function sendPaymentInstructions(string $chatId, Order $order, ?Payment $payment): void
    {
        $this->bot->sendMessage($chatId, 'Pembayaran '.$order->invoice_number.' merchant_ref '.$payment?->merchant_ref.' reference '.$payment?->provider_reference);
    }

    private function customer(array $from): Customer
    {
        return Customer::updateOrCreate(['telegram_id' => (int) $from['id']], ['first_name' => $from['first_name'] ?? 'Telegram Customer', 'username' => $from['username'] ?? null]);
    }

    private function intentKey(Customer $customer): string
    {
        return 'telegram:selected-product:'.$customer->telegram_id;
    }
}
