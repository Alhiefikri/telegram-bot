<?php

namespace App\Services\Telegram\Callbacks;

use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidCartState;
use App\Exceptions\InvalidCheckout;
use App\Jobs\SendAdminOrderPendingNotification;
use App\Jobs\SendBinanceCheckoutInstructions;
use App\Jobs\SendKlikQrisCheckoutInstructions;
use App\Jobs\SendManualCheckoutNotifications;
use App\Jobs\SendPakasirCheckoutInstructions;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPostCreationBehavior;
use App\Services\OrderCheckoutService;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;
use App\Services\Telegram\TelegramCartIntents;
use App\Services\Telegram\TelegramCheckoutIntents;
use App\Services\Telegram\TelegramCheckoutPaymentIntentSelector;
use App\Services\Telegram\TelegramIntentSession;
use App\Services\Telegram\TelegramLocale;
use App\Support\DogfoodTiming;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class TelegramCheckoutCallbackHandler implements TelegramCallbackHandler
{
    public function __construct(
        private TelegramBotClient $bot,
        private CartService $carts,
        private OrderCheckoutService $checkout,
        private TelegramCartIntents $cart,
        private TelegramCheckoutIntents $checkoutIntents,
        private TelegramCheckoutPaymentIntentSelector $paymentIntentSelector,
        private TelegramIntentSession $session,
        private TelegramLocale $locale,
    ) {}

    public function stage(): TelegramCallbackStage
    {
        return TelegramCallbackStage::AfterCommunityGate;
    }

    public function allowsNonPrivateChat(TelegramCallbackContext $context): bool
    {
        return false;
    }

    public function supports(TelegramCallbackContext $context): bool
    {
        return $context->data === 'checkout'
            || in_array($context->data, ['checkout:current', 'checkout:binance'], true)
            || preg_match('/^qty:(qris|binance):([1-9][0-9]*):([1-9][0-9]*)$/', $context->data) === 1;
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        if ($context->data === 'checkout') {
            $checkoutKey = $this->checkoutIntentKey($context->customer, $context->chatId, $context->messageId, $this->checkoutIntentPayload($context->data));
            $this->withCheckoutIntentLock($context, $checkoutKey, fn () => $this->choosePaymentMethod($context, $checkoutKey));

            return true;
        }

        if (in_array($context->data, ['checkout:current', 'checkout:binance'], true)) {
            $checkoutKey = $this->checkoutIntentKey($context->customer, $context->chatId, $context->messageId, $this->checkoutIntentPayload($context->data));
            $this->withCheckoutIntentLock($context, $checkoutKey, fn () => $this->checkout($context, $context->data === 'checkout:binance', true, $checkoutKey));

            return true;
        }

        if (preg_match('/^qty:(qris|binance):([1-9][0-9]*):([1-9][0-9]*)$/', $context->data, $matches)) {
            $checkoutKey = $this->checkoutIntentKey($context->customer, $context->chatId, $context->messageId, $this->checkoutIntentPayload($context->data));
            $this->withCheckoutIntentLock($context, $checkoutKey, function () use ($context, $matches, $checkoutKey): void {
                if ($this->checkoutIntentAlreadyHandled($checkoutKey)) {
                    $this->bot->answerCallbackQuery($context->callbackQueryId);

                    return;
                }

                $paymentMethod = $matches[1];
                $productId = (int) $matches[2];
                $quantity = (int) $matches[3];
                $product = Product::publicVisible()->whereKey($productId)->first();
                if (! $product || $product->availableStock() < $quantity) {
                    $this->session->forget($context->customer);
                    $this->bot->answerCallbackQuery($context->callbackQueryId, $this->text($context->customer, 'Produk atau jumlah ini sudah tidak tersedia.', 'This product or quantity is no longer available.'));

                    return;
                }
                if ($paymentMethod === 'binance' && ($product->usdt_price_amount === null || ! $this->checkoutIntents->binancePayEnabled())) {
                    $this->session->forget($context->customer);
                    $this->bot->answerCallbackQuery($context->callbackQueryId, $this->text($context->customer, 'Binance Pay sedang tidak tersedia untuk produk ini.', 'Binance Pay is not available for this product.'));

                    return;
                }

                $this->bot->answerCallbackQuery($context->callbackQueryId);
                $this->session->forget($context->customer);
                if ($this->cart->addProductToCart($context->customer, $context->chatId, $productId, $quantity, true, false)) {
                    $this->checkout($context, $paymentMethod === 'binance', false, $checkoutKey);
                }
            });

            return true;
        }

        return false;
    }

    private function choosePaymentMethod(TelegramCallbackContext $context, ?string $checkoutKey = null): void
    {
        $cart = $this->carts->currentActiveCartFor($context->customer);
        if (! $cart) {
            $this->checkout($context, false, true, $checkoutKey);

            return;
        }
        $cart->load('cartItems.product');
        if (! $this->checkoutIntents->binancePayEnabled() || ! $cart->cartItems->every(fn ($item) => $item->product?->usdt_price_amount !== null)) {
            $this->checkout($context, false, true, $checkoutKey);

            return;
        }
        $micro = $cart->cartItems->sum(fn ($item) => $item->product->usdt_price_amount * $item->quantity);
        $qrisButton = [['text' => '💳 QRIS', 'callback_data' => 'checkout:current']];
        $binanceButton = [['text' => '💎 USDT · Binance Pay ('.$this->checkoutIntents->formatUsdt($micro).' USDT)', 'callback_data' => 'checkout:binance']];
        $buttons = $context->customer->locale === 'en'
            ? [$binanceButton, $qrisButton]
            : [$qrisButton, $binanceButton];
        $this->bot->answerCallbackQuery($context->callbackQueryId);
        $this->bot->sendMessage($context->chatId, $this->text($context->customer, 'Pilih metode pembayaran. QRIS hanya untuk aplikasi bank dan e-wallet Indonesia:', 'Choose a payment method. QRIS is for Indonesian bank and e-wallet apps only:'), ['inline_keyboard' => $buttons]);
    }

    private function checkout(TelegramCallbackContext $context, bool $binance = false, bool $answerCallback = true, ?string $checkoutKey = null): void
    {
        if ($this->checkoutIntentAlreadyHandled($checkoutKey)) {
            if ($answerCallback) {
                $this->bot->answerCallbackQuery($context->callbackQueryId);
            }

            return;
        }

        if ($answerCallback) {
            $this->bot->answerCallbackQuery($context->callbackQueryId);
        }

        try {
            $cart = $this->carts->currentActiveCartFor($context->customer);
            if (! $cart) {
                throw new InvalidCheckout('Cart is empty.');
            }
            if ($binance) {
                $cart->load('cartItems.product');
                if (! $this->checkoutIntents->binancePayEnabled() || ! $cart->cartItems->every(fn ($item) => $item->product?->usdt_price_amount !== null)) {
                    $this->bot->sendMessage($context->chatId, $this->text($context->customer, 'Binance Pay sedang tidak tersedia untuk keranjang ini. Pilih QRIS untuk melanjutkan.', 'Binance Pay is not available for this cart. Choose QRIS to continue.'), [
                        'inline_keyboard' => [[['text' => '💳 QRIS', 'callback_data' => 'checkout:current']]],
                    ]);

                    return;
                }
            }

            $selection = $this->paymentIntentSelector->select($binance);
            $requestedPaymentMode = $selection->requestedPaymentMode;
            $paymentMode = $selection->paymentMode;
            $paymentIntent = $selection->paymentIntent;
            $this->assertSupportedPostCreationBehavior($paymentIntent->postCreationBehavior());
            $order = DogfoodTiming::time('dogfood.telegram.checkout_step', [
                'step' => 'order_checkout',
                'payment_mode' => $paymentMode,
                'customer_correlation_id' => DogfoodTiming::correlation($context->customer->telegram_id),
                'cart_correlation_id' => DogfoodTiming::correlation($cart->id),
            ], fn () => $this->checkout->checkout($cart, $paymentIntent, checkoutKey: $checkoutKey));
        } catch (InvalidCheckout|InvalidCartState|InsufficientStock) {
            $this->bot->sendMessage($context->chatId, $this->text($context->customer, "Checkout belum berhasil.\nKeranjang kosong, stok berubah, atau produknya sudah tidak tersedia.\n\nCoba buka /produk lagi dan pilih produk ulang ya.", "Checkout could not be completed.\nYour cart is empty, stock changed, or the product is no longer available.\n\nOpen /products and choose the product again."));

            return;
        }

        if ($requestedPaymentMode === 'pakasir' && $paymentMode === 'manual') {
            $this->bot->sendMessage($context->chatId, $this->checkoutIntents->pakasirUnavailableCustomerMessage($context->customer));
        }

        match ($paymentIntent->postCreationBehavior()) {
            CheckoutPostCreationBehavior::BinancePayInstructions => SendBinanceCheckoutInstructions::dispatch($order->id, now()->toISOString())->onQueue('telegram-outbound')->afterCommit(),
            CheckoutPostCreationBehavior::KlikQrisInstructions => SendKlikQrisCheckoutInstructions::dispatch($order->id, now()->toISOString())->onQueue('telegram-outbound')->afterCommit(),
            CheckoutPostCreationBehavior::PakasirInstructions => SendPakasirCheckoutInstructions::dispatch($order->id, now()->toISOString())->onQueue('telegram-outbound')->afterCommit(),
            CheckoutPostCreationBehavior::ManualQrisInstructions => SendManualCheckoutNotifications::dispatch($order->id, now()->toISOString())->onQueue('telegram-outbound')->afterCommit(),
        };
        SendAdminOrderPendingNotification::dispatch($order->id, now()->toISOString())->onQueue('telegram-outbound')->afterCommit();
    }

    private function assertSupportedPostCreationBehavior(CheckoutPostCreationBehavior $behavior): void
    {
        match ($behavior) {
            CheckoutPostCreationBehavior::BinancePayInstructions,
            CheckoutPostCreationBehavior::PakasirInstructions,
            CheckoutPostCreationBehavior::ManualQrisInstructions,
            CheckoutPostCreationBehavior::KlikQrisInstructions => null,
        };
    }

    private function checkoutIntentKey(Customer $customer, string $chatId, ?int $messageId, string $data): ?string
    {
        if ($messageId === null) {
            return null;
        }

        $payload = implode('|', [
            'telegram-direct-checkout',
            $customer->getKey(),
            $customer->telegram_id,
            $chatId,
            $messageId,
            $data,
        ]);

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    private function checkoutIntentPayload(string $data): string
    {
        if (preg_match('/^qty:(qris|binance):([1-9][0-9]*):[1-9][0-9]*$/', $data, $matches)) {
            return 'qty:'.$matches[1].':'.$matches[2];
        }

        if (in_array($data, ['checkout:current', 'checkout:binance'], true)) {
            return 'checkout:choice';
        }

        return $data;
    }

    private function checkoutIntentAlreadyHandled(?string $checkoutKey): bool
    {
        return $checkoutKey !== null
            && Order::query()->where('checkout_key', $checkoutKey)->exists();
    }

    private function withCheckoutIntentLock(TelegramCallbackContext $context, ?string $checkoutKey, callable $callback): void
    {
        if ($checkoutKey === null) {
            $callback();

            return;
        }

        try {
            Cache::lock('telegram:checkout:'.$checkoutKey, 45)
                ->block(5, fn () => $callback());
        } catch (LockTimeoutException) {
            $this->bot->answerCallbackQuery($context->callbackQueryId, $this->text($context->customer, 'Checkout sedang diproses. Tunggu sebentar ya.', 'Checkout is being processed. Please wait a moment.'));
        }
    }

    private function text(Customer $customer, string $id, string $en): string
    {
        return $this->locale->text($customer, $id, $en);
    }
}
