<?php

namespace App\Services\Telegram\Callbacks;

use App\Models\Customer;
use App\Services\CartService;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use App\Services\CheckoutMaintenance\CheckoutMaintenanceState;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;
use App\Services\Telegram\TelegramLocale;

final class TelegramCheckoutMaintenanceCallbackHandler implements TelegramCallbackHandler
{
    public function __construct(
        private TelegramBotClient $bot,
        private CheckoutMaintenance $maintenance,
        private TelegramLocale $locale,
        private CartService $carts,
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
        return $context->data === 'checkout-maintenance:status';
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        $state = $this->maintenance->current();
        $this->bot->answerCallbackQuery($context->callbackQueryId);

        if ($state->active) {
            $this->bot->sendMessage(
                $context->chatId,
                $this->activeMessage($context->customer, $state),
                ['inline_keyboard' => [[[
                    'text' => $this->text($context->customer, '🔄 Cek Status Lagi', '🔄 Check Status Again'),
                    'callback_data' => 'checkout-maintenance:status',
                ]]]],
            );

            return true;
        }

        $this->bot->sendMessage(
            $context->chatId,
            $this->text(
                $context->customer,
                '✅ Checkout sudah dibuka kembali. Kamu bisa membuat Order baru sekarang.',
                '✅ Checkout is open again. You can create a new Order now.',
            ),
            ['inline_keyboard' => $this->reopenedKeyboard($context->customer)],
        );

        return true;
    }

    private function activeMessage(Customer $customer, CheckoutMaintenanceState $state): string
    {
        return $this->text(
            $customer,
            '⏸️ Checkout sedang dalam maintenance. Kamu tetap bisa melihat produk, stok, dan mengelola keranjang, tetapi belum dapat membuat pesanan baru. Pesanan yang sudah dibuat tetap diproses. Silakan cek status kembali beberapa saat lagi.'
                .$this->locale->checkoutMaintenanceNote($customer, $state->publicNote),
            '⏸️ Checkout is under maintenance. You can still browse products, view stock, and manage your cart, but new Orders cannot be created right now. Existing Orders continue to be processed.'
                .$this->locale->checkoutMaintenanceNote($customer, $state->publicNote),
        );
    }

    /** @return list<list<array<string, string>>> */
    private function reopenedKeyboard(Customer $customer): array
    {
        $keyboard = [[[
            'text' => $this->text($customer, '🛍️ Lihat Produk', '🛍️ Browse Products'),
            'callback_data' => 'katalog',
        ]]];

        $cart = $this->carts->currentActiveCartFor($customer);
        if ($cart?->cartItems()->exists()) {
            $keyboard[] = [[
                'text' => $this->text($customer, '💳 Lanjut Checkout', '💳 Continue to Checkout'),
                'callback_data' => 'checkout',
            ]];
        }

        $keyboard[] = [[
            'text' => $this->text($customer, '📦 Cek Pesanan', '📦 Check Orders'),
            'callback_data' => 'status',
        ]];

        return $keyboard;
    }

    private function text(Customer $customer, string $id, string $en): string
    {
        return $this->locale->text($customer, $id, $en);
    }
}
