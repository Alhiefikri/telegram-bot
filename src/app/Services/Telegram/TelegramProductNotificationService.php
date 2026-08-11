<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use App\Models\Product;
use App\Models\StoreChannelSetting;
use App\Storefront\StorefrontUrl;
use Throwable;

class TelegramProductNotificationService
{
    public function __construct(
        private TelegramBroadcastService $broadcast,
        private TelegramBotClient $bot,
        private TelegramAdminAccess $adminAccess,
        private StorefrontUrl $urls,
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
                'A new product just arrived',
                '📦 '.$product->name,
                '💰 Price: Rp'.number_format($product->price_amount, 0, ',', '.'),
                '📦 Stock: '.$availableStock,
                '',
                '📝 Description',
                $this->descriptionText($product, true),
            ] : [
                'Produk baru sudah masuk nih',
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

        $this->sendStoreChannel(
            implode("\n", [
                '✨ '.$product->name.' RESTOCK — SUDAH TERSEDIA',
                '━━━━━━━━━━━━━━',
                'Stok baru sudah masuk dan produk siap dipesan kembali.',
                '',
                '📥 Stok masuk: +'.$addedQuantity.' item',
                '📦 Stok tersedia sekarang: '.$availableStock.' item',
                '💳 Harga QRIS: Rp'.number_format($product->price_amount, 0, ',', '.'),
                ...($product->usdt_price_amount !== null
                    ? ['💎 Binance Pay: '.$this->formatUsdt($product->usdt_price_amount).' USDT']
                    : []),
                '',
                'Jangan sampai kehabisan lagi ya 🙌',
            ]),
            [
                'inline_keyboard' => [[
                    ['text' => '🛒 Lihat Produk', 'url' => $this->urls->telegramProductStartUrl($product)],
                ]],
            ],
        );

        return $this->broadcast->sendToActiveUsers(
            fn (Customer $customer) => implode("\n", $customer->locale === 'en' ? [
                'Back in stock',
                '📦 '.$product->name,
                '💰 Price: Rp'.number_format($product->price_amount, 0, ',', '.'),
                '📦 Stock: '.$availableStock,
                '',
                '📝 Description',
                $this->descriptionText($product, true),
            ] : [
                'Stok tersedia lagi',
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

    /** @return list<array{customer_id:?int,recipient_type:string,telegram_id:string,text:string,reply_markup:array}> */
    public function stockAddedDeliveries(Product $product, int $addedQuantity, ?int $availableBefore): array
    {
        $product = $product->fresh();
        if (! $product || ! $product->is_active || $addedQuantity < 1 || ($availableBefore !== null && $availableBefore > 0)) {
            return [];
        }
        $availableStock = $product->availableStock();
        if ($availableStock < 1) {
            return [];
        }

        $deliveries = [];
        if ($channelChatId = $this->storeChannelChatId()) {
            $deliveries[] = [
                'customer_id' => null, 'recipient_type' => 'store_channel', 'telegram_id' => $channelChatId,
                'text' => implode("\n", ['✨ '.$product->name.' RESTOCK — SUDAH TERSEDIA', '━━━━━━━━━━━━━━', 'Stok baru sudah masuk dan produk siap dipesan kembali.', '', '📥 Stok masuk: +'.$addedQuantity.' item', '📦 Stok tersedia sekarang: '.$availableStock.' item', '💳 Harga QRIS: Rp'.number_format($product->price_amount, 0, ',', '.'), ...($product->usdt_price_amount !== null ? ['💎 Binance Pay: '.$this->formatUsdt($product->usdt_price_amount).' USDT'] : []), '', 'Jangan sampai kehabisan lagi ya 🙌']),
                'reply_markup' => ['inline_keyboard' => [[['text' => '🛒 Lihat Produk', 'url' => $this->urls->telegramProductStartUrl($product)]]]],
            ];
        }

        return [...$deliveries, ...$this->customerDeliveries($product, $availableStock, false)];
    }

    /** @return list<array{customer_id:?int,recipient_type:string,telegram_id:string,text:string,reply_markup:array}> */
    public function productSoldOutDeliveries(Product $product): array
    {
        $product = $product->fresh();
        if (! $product) {
            return [];
        }
        $deliveries = [];
        if ($channelChatId = $this->storeChannelChatId()) {
            $deliveries[] = ['customer_id' => null, 'recipient_type' => 'store_channel', 'telegram_id' => $channelChatId, 'text' => implode("\n", ['📦 '.$product->name, '🔴 TERJUAL HABIS', '━━━━━━━━━━━━━━', '', 'Stok terakhir baru saja terjual setelah pembayaran berhasil dikonfirmasi ✅', '', 'Terima kasih atas antusiasme dan kepercayaannya 🙏', 'Produk ini sementara belum dapat dipesan kembali.', '', 'Pantau channel ini untuk mendapatkan informasi stok berikutnya. Kami akan mengabari saat produk tersedia lagi.']), 'reply_markup' => []];
        }
        if (! blank(config('services.telegram.token')) && ($adminChatId = $this->adminAccess->adminChatId())) {
            $deliveries[] = ['customer_id' => null, 'recipient_type' => 'admin', 'telegram_id' => (string) $adminChatId, 'text' => implode("\n", ['⚠️ Stok habis', '━━━━━━━━━━━━━━', '📦 '.$product->name, '', 'Stok yang tersedia untuk dibeli sekarang 0.', '', 'Tambahkan stok dari dashboard agar produk bisa tampil dan dibeli lagi di katalog.']), 'reply_markup' => []];
        }

        return $deliveries;
    }

    /** @return list<array{customer_id:?int,recipient_type:string,telegram_id:string,text:string,reply_markup:array}> */
    private function customerDeliveries(Product $product, int $availableStock, bool $created): array
    {
        $seen = [];

        return Customer::query()->whereNotNull('telegram_id')->orderBy('id')->get(['id', 'telegram_id', 'locale'])
            ->filter(function (Customer $customer) use (&$seen): bool {
                $id = trim((string) $customer->telegram_id);
                if ($id === '' || isset($seen[$id])) {
                    return false;
                }
                $seen[$id] = true;

                return true;
            })->map(function (Customer $customer) use ($product, $availableStock, $created): array {
                $english = $customer->locale === 'en';
                $title = $created ? ($english ? '🆕 New Product' : '🆕 Produk Baru') : ($english ? '✅ Back in Stock' : '✅ Stok Tersedia');
                $lead = $created ? ($english ? 'A new product just arrived' : 'Produk baru sudah masuk nih') : ($english ? 'Back in stock' : 'Stok tersedia lagi');
                $text = implode("\n", [$title, '━━━━━━━━━━━━━━', $lead, '📦 '.$product->name, '💰 '.($english ? 'Price' : 'Harga').': Rp'.number_format($product->price_amount, 0, ',', '.'), '📦 '.($english ? 'Stock' : 'Stok').': '.$availableStock, '', '📝 '.($english ? 'Description' : 'Deskripsi'), $this->descriptionText($product, $english), '', $english ? 'Need help? Reply with /support.' : 'Butuh bantuan? Balas /support ya.']);

                return ['customer_id' => $customer->id, 'recipient_type' => 'customer', 'telegram_id' => (string) $customer->telegram_id, 'text' => $text, 'reply_markup' => $this->productReplyMarkup($product, $availableStock, $english)];
            })->values()->all();
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

    public function notifyProductSoldOut(Product $product): void
    {
        $this->sendStoreChannel(implode("\n", [
            '📦 '.$product->name,
            '🔴 TERJUAL HABIS',
            '━━━━━━━━━━━━━━',
            '',
            'Stok terakhir baru saja terjual setelah pembayaran berhasil dikonfirmasi ✅',
            '',
            'Terima kasih atas antusiasme dan kepercayaannya 🙏',
            'Produk ini sementara belum dapat dipesan kembali.',
            '',
            'Pantau channel ini untuk mendapatkan informasi stok berikutnya. Kami akan mengabari saat produk tersedia lagi.',
        ]));

        $this->notifyAdminOutOfStock($product);
    }

    /**
     * Kept as a compatibility alias for callers that used the old internal name.
     */
    public function notifyOutOfStock(Product $product): void
    {
        $this->notifyProductSoldOut($product);
    }

    private function notifyAdminOutOfStock(Product $product): void
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
                'Stok yang tersedia untuk dibeli sekarang 0.',
                '',
                'Tambahkan stok dari dashboard agar produk bisa tampil dan dibeli lagi di katalog.',
            ]));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function sendStoreChannel(string $text, array $replyMarkup = []): void
    {
        $channelChatId = $this->storeChannelChatId();
        if (blank(config('services.telegram.token')) || $channelChatId === null) {
            return;
        }

        try {
            $this->bot->sendMessage($channelChatId, $text, $replyMarkup);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function storeChannelChatId(): ?string
    {
        $setting = StoreChannelSetting::current();
        if ($setting->last_test_success !== true) {
            return null;
        }

        $chatId = trim((string) $setting->telegram_chat_id);

        return $chatId === '' ? null : $chatId;
    }

    private function formatUsdt(int $amount): string
    {
        return rtrim(rtrim(number_format($amount / 1_000_000, 6, '.', ''), '0'), '.');
    }
}
