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
        private TelegramLocale $locale,
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
            fn (Customer $customer) => implode("\n", [
                $this->locale->translate($customer, 'notification.product.created.lead'),
                '📦 '.$product->name,
                $this->locale->translate($customer, 'notification.product.price', [
                    'amount' => number_format($product->price_amount, 0, ',', '.'),
                ]),
                $this->locale->translate($customer, 'notification.product.stock', ['stock' => $availableStock]),
                '',
                $this->locale->translate($customer, 'notification.product.description'),
                $this->descriptionText($product, $this->locale->code($customer)),
            ]),
            fn (Customer $customer) => $this->locale->translate($customer, 'notification.product.created.title'),
            fn (Customer $customer) => $this->productReplyMarkup($product, $availableStock, $this->locale->code($customer)),
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
            fn (Customer $customer) => implode("\n", [
                $this->locale->translate($customer, 'notification.product.restock.lead'),
                '📦 '.$product->name,
                $this->locale->translate($customer, 'notification.product.price', [
                    'amount' => number_format($product->price_amount, 0, ',', '.'),
                ]),
                $this->locale->translate($customer, 'notification.product.stock', ['stock' => $availableStock]),
                '',
                $this->locale->translate($customer, 'notification.product.description'),
                $this->descriptionText($product, $this->locale->code($customer)),
            ]),
            fn (Customer $customer) => $this->locale->translate($customer, 'notification.product.restock.title'),
            fn (Customer $customer) => $this->productReplyMarkup($product, $availableStock, $this->locale->code($customer)),
        );
    }

    /** @return list<array{customer_id:?int,recipient_type:string,telegram_id:string,locale:?string,text:string,reply_markup:array}> */
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
                'customer_id' => null,
                'recipient_type' => 'store_channel',
                'telegram_id' => $channelChatId,
                'locale' => null,
                'text' => implode("\n", ['✨ '.$product->name.' RESTOCK — SUDAH TERSEDIA', '━━━━━━━━━━━━━━', 'Stok baru sudah masuk dan produk siap dipesan kembali.', '', '📥 Stok masuk: +'.$addedQuantity.' item', '📦 Stok tersedia sekarang: '.$availableStock.' item', '💳 Harga QRIS: Rp'.number_format($product->price_amount, 0, ',', '.'), ...($product->usdt_price_amount !== null ? ['💎 Binance Pay: '.$this->formatUsdt($product->usdt_price_amount).' USDT'] : []), '', 'Jangan sampai kehabisan lagi ya 🙌']),
                'reply_markup' => ['inline_keyboard' => [[['text' => '🛒 Lihat Produk', 'url' => $this->urls->telegramProductStartUrl($product)]]]],
            ];
        }

        return [...$deliveries, ...$this->customerDeliveries($product, $availableStock, false)];
    }

    /** @return list<array{customer_id:?int,recipient_type:string,telegram_id:string,locale:?string,text:string,reply_markup:array}> */
    public function productSoldOutDeliveries(Product $product): array
    {
        $product = $product->fresh();
        if (! $product) {
            return [];
        }
        $deliveries = [];
        if ($channelChatId = $this->storeChannelChatId()) {
            $deliveries[] = ['customer_id' => null, 'recipient_type' => 'store_channel', 'telegram_id' => $channelChatId, 'locale' => null, 'text' => implode("\n", ['📦 '.$product->name, '🔴 TERJUAL HABIS', '━━━━━━━━━━━━━━', '', 'Stok terakhir baru saja terjual setelah pembayaran berhasil dikonfirmasi ✅', '', 'Terima kasih atas antusiasme dan kepercayaannya 🙏', 'Produk ini sementara belum dapat dipesan kembali.', '', 'Pantau channel ini untuk mendapatkan informasi stok berikutnya. Kami akan mengabari saat produk tersedia lagi.']), 'reply_markup' => []];
        }
        if (! blank(config('services.telegram.token')) && ($adminChatId = $this->adminAccess->adminChatId())) {
            $deliveries[] = ['customer_id' => null, 'recipient_type' => 'admin', 'telegram_id' => (string) $adminChatId, 'locale' => null, 'text' => implode("\n", ['⚠️ Stok habis', '━━━━━━━━━━━━━━', '📦 '.$product->name, '', 'Stok yang tersedia untuk dibeli sekarang 0.', '', 'Tambahkan stok dari dashboard agar produk bisa tampil dan dibeli lagi di katalog.']), 'reply_markup' => []];
        }

        return $deliveries;
    }

    /** @return list<array{customer_id:?int,recipient_type:string,telegram_id:string,locale:string,text:string,reply_markup:array}> */
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
                $locale = $this->locale->code($customer);
                $title = $this->locale->translateLocale($locale, $created ? 'notification.product.created.title' : 'notification.product.restock.title');
                $lead = $this->locale->translateLocale($locale, $created ? 'notification.product.created.lead' : 'notification.product.restock.lead');
                $text = implode("\n", [
                    $title,
                    '━━━━━━━━━━━━━━',
                    $lead,
                    '📦 '.$product->name,
                    $this->locale->translateLocale($locale, 'notification.product.price', [
                        'amount' => number_format($product->price_amount, 0, ',', '.'),
                    ]),
                    $this->locale->translateLocale($locale, 'notification.product.stock', ['stock' => $availableStock]),
                    '',
                    $this->locale->translateLocale($locale, 'notification.product.description'),
                    $this->descriptionText($product, $locale),
                    '',
                    $this->locale->translateLocale($locale, 'notification.broadcast.help'),
                ]);

                return [
                    'customer_id' => $customer->id,
                    'recipient_type' => 'customer',
                    'telegram_id' => (string) $customer->telegram_id,
                    'locale' => $locale,
                    'text' => $text,
                    'reply_markup' => $this->productReplyMarkup($product, $availableStock, $locale),
                ];
            })->values()->all();
    }

    private function descriptionText(Product $product, string $locale): string
    {
        $description = (string) $product->description;

        return trim($description) !== ''
            ? $description
            : $this->locale->translateLocale($locale, 'notification.product.description_missing');
    }

    private function productReplyMarkup(Product $product, int $availableStock, string $locale): array
    {
        if ($availableStock < 1) {
            return [];
        }

        return [
            'inline_keyboard' => [[
                ['text' => $this->locale->translateLocale($locale, 'notification.product.cta'), 'callback_data' => 'product:'.$product->id],
            ]],
        ];
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
