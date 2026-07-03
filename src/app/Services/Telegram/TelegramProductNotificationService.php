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

    public function notifyProductCreated(Product $product): array
    {
        if (! $product->is_active) {
            return $this->skippedResult();
        }

        $availableStock = max(0, $product->stock - $product->reserved_stock);
        if ($availableStock < 1) {
            return $this->skippedResult();
        }

        return $this->broadcast->sendToActiveUsers(implode("\n", [
            'Produk baru sudah masuk nih 👀',
            '📦 '.$product->name,
            '💰 Harga: Rp'.number_format($product->price_amount, 0, ',', '.'),
            '📦 Stok: '.$availableStock,
            '',
            '📝 Deskripsi',
            $this->descriptionText($product),
        ]), '🆕 Produk Baru', $this->productReplyMarkup($product, $availableStock));
    }

    public function notifyStockAdded(Product $product, int $addedQuantity): array
    {
        $product = $product->fresh();
        if (! $product || ! $product->is_active || $addedQuantity < 1) {
            return $this->skippedResult();
        }

        $availableStock = $product->stock - $product->reserved_stock;
        if ($availableStock < 1) {
            return $this->skippedResult();
        }

        return $this->broadcast->sendToActiveUsers(implode("\n", [
            'Stok sudah ready lagi 🔥',
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
                ['text' => '👀 Intip Produk', 'callback_data' => 'product:'.$product->id],
            ]],
        ];
    }

    private function descriptionText(Product $product): string
    {
        $description = trim((string) $product->description);

        return $description !== '' ? $description : 'Deskripsi produk belum ditambahkan.';
    }

    private function skippedResult(): array
    {
        return [
            'sent' => 0,
            'failed' => 0,
            'skipped' => true,
            'recipient_count' => 0,
            'telegram_total_duration_ms' => 0.0,
            'per_recipient_telegram' => [],
        ];
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
                '',
                'Stok produk ini sekarang 0.',
                '',
                'Tambahkan stok dari dashboard agar produk bisa tampil dan dibeli lagi di katalog.',
            ]));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
