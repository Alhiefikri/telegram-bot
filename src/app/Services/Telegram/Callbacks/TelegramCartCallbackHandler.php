<?php

namespace App\Services\Telegram\Callbacks;

use App\Enums\CartStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use App\Services\CheckoutMaintenance\CheckoutMaintenanceState;
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
        private CheckoutMaintenance $maintenance,
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
            $maintenance = $this->maintenance->current();
            if ($maintenance->active) {
                if (! $product || $availableStock < 1) {
                    $this->sendMaintenanceNotice($context, $maintenance);

                    return true;
                }

                $this->bot->answerCallbackQuery($context->callbackQueryId);
                $this->session->put($context->customer, $product->id);
                $this->catalog->sendQuantityChoices($context->customer, $context->chatId, $product, $availableStock, $paymentMethod);

                return true;
            }
            if (! $product || $availableStock < 1) {
                $this->session->forget($context->customer);
                $this->bot->answerCallbackQuery($context->callbackQueryId, $this->text($context->customer, 'Produk ini belum bisa dibeli sekarang. Coba pilih produk lain.', 'This product cannot be purchased right now. Please choose another product.'));

                return true;
            }
            if ($paymentMethod === 'binance' && ($product->usdt_price_amount === null || ! $this->checkoutIntents->binancePayEnabled())) {
                $this->session->forget($context->customer);
                $this->bot->answerCallbackQuery($context->callbackQueryId, $this->text($context->customer, 'Binance Pay sedang tidak tersedia untuk produk ini. Pilih QRIS untuk melanjutkan.', 'Binance Pay is not available for this product. Choose QRIS to continue.'));

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
            $maintenance = $this->maintenance->current();
            if ($maintenance->active) {
                if (! $product || $availableStock < 1) {
                    $this->sendMaintenanceNotice($context, $maintenance);

                    return true;
                }

                $this->bot->answerCallbackQuery($context->callbackQueryId);
                $this->session->put($context->customer, $product->id);
                $this->catalog->sendQuantityChoices($context->customer, $context->chatId, $product, $availableStock);

                return true;
            }
            if (! $product || $availableStock < 1) {
                $this->session->forget($context->customer);
                $this->bot->answerCallbackQuery($context->callbackQueryId, $this->text($context->customer, 'Produk ini belum bisa dibeli sekarang. Coba pilih produk lain.', 'This product cannot be purchased right now. Please choose another product.'));

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

    private function sendMaintenanceNotice(TelegramCallbackContext $context, CheckoutMaintenanceState $state): void
    {
        $this->bot->answerCallbackQuery($context->callbackQueryId);
        $this->bot->sendMessage(
            $context->chatId,
            $this->maintenanceMessage($context->customer, $state),
            $this->maintenanceKeyboard($context->customer),
        );
    }

    private function maintenanceMessage(Customer $customer, CheckoutMaintenanceState $state): string
    {
        return $this->text(
            $customer,
            '⏸️ Checkout sedang dalam maintenance. Kamu tetap bisa melihat produk, stok, dan mengelola keranjang, tetapi belum dapat membuat pesanan baru. Pesanan yang sudah dibuat tetap diproses. Silakan cek status kembali beberapa saat lagi.'
                .$this->locale->checkoutMaintenanceNote($customer, $state->publicNote),
            '⏸️ Checkout is under maintenance. You can still browse products, view stock, and manage your cart, but new Orders cannot be created right now. Existing Orders continue to be processed. Check status again in a little while.'
                .$this->locale->checkoutMaintenanceNote($customer, $state->publicNote),
        );
    }

    /** @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>} */
    private function maintenanceKeyboard(Customer $customer): array
    {
        return ['inline_keyboard' => [[[
            'text' => $this->text($customer, '🔄 Cek Status Checkout', '🔄 Check Checkout Status'),
            'callback_data' => 'checkout-maintenance:status',
        ]]]];
    }

    private function text(Customer $customer, string $id, string $en): string
    {
        return $this->locale->text($customer, $id, $en);
    }
}
