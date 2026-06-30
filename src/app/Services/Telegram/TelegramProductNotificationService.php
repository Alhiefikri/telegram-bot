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

        $this->broadcast->sendToActiveUsers(implode("\n", [
            'Produk baru sudah masuk:',
            '📦 '.$product->name,
            '💰 Rp '.number_format($product->price_amount, 0, ',', '.'),
            '',
            'Buka /katalog untuk cek detail dan stok terbaru.',
        ]), '🆕 Produk Baru');
    }

    public function notifyStockAdded(Product $product, int $addedQuantity): void
    {
        if (! $product->is_active || $addedQuantity < 1) {
            return;
        }

        $availableStock = max(0, $product->stock - $product->reserved_stock);
        $this->broadcast->sendToActiveUsers(implode("\n", [
            'Stok produk bertambah:',
            '📦 '.$product->name,
            '➕ Masuk '.$addedQuantity.' item',
            '✅ Tersedia sekarang: '.$availableStock,
            '',
            'Buka /katalog untuk order sebelum habis.',
        ]), '✅ Stok Ready');
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
