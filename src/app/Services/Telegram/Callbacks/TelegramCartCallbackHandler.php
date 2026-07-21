<?php

namespace App\Services\Telegram\Callbacks;

use App\Enums\CartStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;
use App\Services\Telegram\TelegramCartIntents;
use App\Services\Telegram\TelegramCatalogIntents;
use App\Services\Telegram\TelegramCheckoutIntents;
use App\Services\Telegram\TelegramIntentSession;
use App\Services\Telegram\TelegramLocale;

class TelegramCartCallbackHandler implements TelegramCallbackHandler
{
    public function __construct(
        private TelegramBotClient $bot,
        private TelegramCartIntents $cart,
        private TelegramCatalogIntents $catalog,
        private TelegramCheckoutIntents $checkoutIntents,
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
        return $context->data === 'batal'
            || preg_match('/^qty:([1-9][0-9]*):([1-9][0-9]*)$/', $context->data) === 1
            || preg_match('/^buy:(qris|binance):([1-9][0-9]*)$/', $context->data) === 1
            || preg_match('/^buy:([1-9][0-9]*)$/', $context->data) === 1;
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        if ($context->data === 'batal') {
            $this->bot->answerCallbackQuery($context->callbackQueryId);
            $this->session->forget($context->customer);
            $context->customer->carts()->where('status', CartStatus::Active)->update(['status' => CartStatus::Abandoned]);
            $this->bot->sendMessage($context->chatId, $this->text($context->customer, "🛒 Keranjang aktif sudah dibatalkan.\n\nKalau mau pilih produk lagi, buka /produk ya.", "🛒 Your active cart has been cancelled.\n\nOpen /products to browse again."));

            return true;
        }

        if (preg_match('/^buy:(qris|binance):([1-9][0-9]*)$/', $context->data, $matches)) {
            $paymentMethod = $matches[1];
            $product = Product::publicVisible()->whereKey((int) $matches[2])->first();
            $availableStock = $product ? $product->availableStock() : 0;
            if (! $product || $availableStock < 1) {
                $this->session->forget($context->customer);
                $this->bot->answerCallbackQuery($context->callbackQueryId, $this->text($context->customer, 'Produk ini belum bisa dibeli sekarang.', 'This product cannot be purchased right now.'));

                return true;
            }
            if ($paymentMethod === 'binance' && ($product->usdt_price_amount === null || ! $this->checkoutIntents->binancePayEnabled())) {
                $this->session->forget($context->customer);
                $this->bot->answerCallbackQuery($context->callbackQueryId, $this->text($context->customer, 'Binance Pay sedang tidak tersedia untuk produk ini.', 'Binance Pay is not available for this product.'));

                return true;
            }

            $this->bot->answerCallbackQuery($context->callbackQueryId);
            $this->session->put($context->customer, $product->id);
            $this->catalog->sendQuantityChoices($context->customer, $context->chatId, $product, $availableStock, $paymentMethod);

            return true;
        }

        if (preg_match('/^buy:([1-9][0-9]*)$/', $context->data, $matches)) {
            $product = Product::publicVisible()->whereKey((int) $matches[1])->first();
            $availableStock = $product ? $product->availableStock() : 0;
            if (! $product || $availableStock < 1) {
                $this->session->forget($context->customer);
                $this->bot->answerCallbackQuery($context->callbackQueryId, $this->text($context->customer, 'Produk ini belum bisa dibeli sekarang.', 'This product cannot be purchased right now.'));

                return true;
            }

            $this->bot->answerCallbackQuery($context->callbackQueryId);
            $this->session->put($context->customer, $product->id);
            $this->catalog->sendQuantityChoices($context->customer, $context->chatId, $product, $availableStock);

            return true;
        }

        if (preg_match('/^qty:([1-9][0-9]*):([1-9][0-9]*)$/', $context->data, $matches)) {
            $this->bot->answerCallbackQuery($context->callbackQueryId);
            $productId = (int) $matches[1];
            $selectedProductId = $this->session->getSelectedProductId($context->customer);
            $this->session->forget($context->customer);
            $this->cart->addProductToCart($context->customer, $context->chatId, $productId, (int) $matches[2], (int) $selectedProductId === $productId);

            return true;
        }

        return false;
    }

    private function text(Customer $customer, string $id, string $en): string
    {
        return $this->locale->text($customer, $id, $en);
    }
}
