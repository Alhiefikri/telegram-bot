<?php

namespace App\Services\Telegram;

use App\Models\Product;
use Throwable;

class TelegramProductNotificationService
{
    public function __construct(
        private TelegramBroadcastService $broadcast,
        private TelegramBotClient $bot,
        private TelegramAdminAccess $adminAccess,
    ) {}

    public function notifyProductCreated(Product $product): void
    {
        if (! $product->is_active) {
            return;
        }

        $availableStock = max(0, $product->stock - $product->reserved_stock);

        $this->broadcast->sendToActiveUsers(implode("\n", [
            'Produk baru sudah masuk:',
            '📦 '.$product->name,
            '💰 Harga: Rp'.number_format($product->price_amount, 0, ',', '.'),
            '📦 Stok: '.$availableStock,
            '',
            '📝 Deskripsi',
            $this->descriptionText($product),
        ]), '🆕 Produk Baru', $this->productReplyMarkup($product, $availableStock));
    }

    public function notifyStockAdded(Product $product, int $addedQuantity): void
    {
        if (! $product->is_active || $addedQuantity < 1) {
            return;
        }

        $availableStock = max(0, $product->stock - $product->reserved_stock);
        $this->broadcast->sendToActiveUsers(implode("\n", [
            'Produk ready dibeli:',
            '📦 '.$product->name,
            '💰 Harga: Rp'.number_format($product->price_amount, 0, ',', '.'),
            '📦 Stok: '.$availableStock,
            '',
            '📝 Deskripsi',
            $this->descriptionText($product),
        ]), '✅ Stok Ready', $this->productReplyMarkup($product, $availableStock));
    }


    private function productReplyMarkup(Product $product, int $availableStock): array
    {
        if ($availableStock < 1) {
            return [];
        }

        return [
            'inline_keyboard' => [[
                ['text' => '👁️ Lihat', 'callback_data' => 'product:'.$product->id],
            ]],
        ];
    }

    private function descriptionText(Product $product): string
    {
        $description = trim((string) $product->description);

        return $description !== '' ? $description : 'Deskripsi produk segera dilengkapi.';
    }
    public function notifyOutOfStock(Product $product): void
    {
        $adminChatId = $this->adminAccess->adminChatId();
        if (blank(config('services.telegram.token')) || ! $adminChatId) {
            return;
        }

        try {
            $this->bot->sendMessage($adminChatId, implode("\n", [
                '⚠️ Stok habis',
                '━━━━━━━━━━━━━━',
                '📦 '.$product->name,
                'Stok tersedia sekarang 0.',
                '',
                'Tambahkan stok dari dashboard agar produk tampil lagi di katalog.',
            ]));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
