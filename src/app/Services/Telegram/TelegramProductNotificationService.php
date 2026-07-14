<?php

namespace App\Services\Telegram;

use App\Models\Customer;
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

        $availableStock = $product->availableStock();
        if ($availableStock < 1) {
            return $this->skippedResult();
        }

        return $this->broadcast->sendToActiveUsers(
            fn (Customer $customer) => implode("\n", $customer->locale === 'en' ? [
                'A new product just arrived 👀',
                '📦 '.$product->name,
                '💰 Price: Rp'.number_format($product->price_amount, 0, ',', '.'),
                '📦 Stock: '.$availableStock,
                '',
                '📝 Description',
                $this->descriptionText($product, true),
            ] : [
                'Produk baru sudah masuk nih 👀',
                '📦 '.$product->name,
                '💰 Harga: Rp'.number_format($product->price_amount, 0, ',', '.'),
                '📦 Stok: '.$availableStock,
                '',
                '📝 Deskripsi',
                $this->descriptionText($product, false),
            ]),
            fn (Customer $customer) => $customer->locale === 'en' ? '🆕 New Product' : '🆕 Produk Baru',
            fn (Customer $customer) => $this->productReplyMarkup($product, $availableStock, $customer->locale === 'en'),
        );
    }

    public function notifyStockAdded(Product $product, int $addedQuantity, ?int $availableBefore = null): array
    {
        $product = $product->fresh();
        if (! $product || ! $product->is_active || $addedQuantity < 1) {
            return $this->skippedResult();
        }

        $availableStock = $product->availableStock();
        if (($availableBefore !== null && $availableBefore > 0) || $availableStock < 1) {
            return $this->skippedResult();
        }

        return $this->broadcast->sendToActiveUsers(
            fn (Customer $customer) => implode("\n", $customer->locale === 'en' ? [
                'Back in stock 🔥',
                '📦 '.$product->name,
                '💰 Price: Rp'.number_format($product->price_amount, 0, ',', '.'),
                '📦 Stock: '.$availableStock,
                '',
                '📝 Description',
                $this->descriptionText($product, true),
            ] : [
                'Stok tersedia lagi 🔥',
                '📦 '.$product->name,
                '💰 Harga: Rp'.number_format($product->price_amount, 0, ',', '.'),
                '📦 Stok: '.$availableStock,
                '',
                '📝 Deskripsi',
                $this->descriptionText($product, false),
            ]),
            fn (Customer $customer) => $customer->locale === 'en' ? '✅ Back in Stock' : '✅ Stok Tersedia',
            fn (Customer $customer) => $this->productReplyMarkup($product, $availableStock, $customer->locale === 'en'),
        );
    }

    private function productReplyMarkup(Product $product, int $availableStock, bool $english): array
    {
        if ($availableStock < 1) {
            return [];
        }

        return [
            'inline_keyboard' => [[
                ['text' => $english ? '👀 View Product' : '👀 Lihat Produk', 'callback_data' => 'product:'.$product->id],
            ]],
        ];
    }

    private function descriptionText(Product $product, bool $english): string
    {
        $description = (string) $product->description;

        return trim($description) !== '' ? $description : ($english ? 'No product description has been added yet.' : 'Deskripsi produk belum ditambahkan.');
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
