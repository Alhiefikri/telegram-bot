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
        if ($availableStock < 1) {
            return $this->skippedResult();
        }

        $channelChatId = $this->storeChannelChatId();
        $this->sendStoreChannel(
            $this->storeChannelRestockMessage($product, $addedQuantity, $availableStock),
            $this->storeChannelProductReplyMarkup($product),
        );

        return $this->shouldNotifyCustomers($availableBefore, $channelChatId)
            ? $this->broadcast->sendToActiveUsers(
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
            )
            : $this->skippedResult();
    }

    /** @return list<array{customer_id:?int,recipient_type:string,telegram_id:string,locale:?string,text:string,reply_markup:array,photo_path:?string}> */
    public function stockAddedDeliveries(
        Product $product,
        int $addedQuantity,
        ?int $availableBefore,
        ?int $availableAfter = null,
    ): array {
        $product = $product->fresh();
        if (! $product || ! $product->is_active || $addedQuantity < 1) {
            return [];
        }
        $availableStock = $availableAfter ?? $product->availableStock();
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
                'text' => $this->storeChannelRestockMessage($product, $addedQuantity, $availableStock),
                'reply_markup' => $this->storeChannelProductReplyMarkup($product),
                'photo_path' => $this->telegramImagePath($product),
            ];
        }

        if ($this->shouldNotifyCustomers($availableBefore, $channelChatId)) {
            $deliveries = [...$deliveries, ...$this->customerDeliveries($product, $availableStock, false)];
        }

        return $deliveries;
    }

    /** @return list<array{customer_id:?int,recipient_type:string,telegram_id:string,locale:?string,text:string,reply_markup:array,photo_path:?string}> */
    public function productSoldOutDeliveries(Product $product): array
    {
        $product = $product->fresh();
        if (! $product) {
            return [];
        }
        $deliveries = [];
        if ($channelChatId = $this->storeChannelChatId()) {
            $deliveries[] = ['customer_id' => null, 'recipient_type' => 'store_channel', 'telegram_id' => $channelChatId, 'locale' => null, 'text' => $this->storeChannelSoldOutMessage($product), 'reply_markup' => [], 'photo_path' => null];
        }
        if (! blank(config('services.telegram.token')) && ($adminChatId = $this->adminAccess->adminChatId())) {
            $deliveries[] = ['customer_id' => null, 'recipient_type' => 'admin', 'telegram_id' => (string) $adminChatId, 'locale' => null, 'text' => implode("\n", ['⚠️ Stok habis', '━━━━━━━━━━━━━━', '📦 '.$product->name, '', 'Stok yang tersedia untuk dibeli sekarang 0.', '', 'Tambahkan stok dari dashboard agar produk bisa tampil dan dibeli lagi di katalog.']), 'reply_markup' => [], 'photo_path' => null];
        }

        return $deliveries;
    }

    /** @return list<array{customer_id:?int,recipient_type:string,telegram_id:string,locale:string,text:string,reply_markup:array,photo_path:?string}> */
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
                    'photo_path' => null,
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

    private function telegramImagePath(Product $product): ?string
    {
        return $product->telegram_image_path
            ?? $product->family?->telegram_image_path
            ?? $product->image_path
            ?? $product->family?->image_path;
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
        $this->sendStoreChannel($this->storeChannelSoldOutMessage($product));

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

    private function storeChannelRestockMessage(Product $product, int $addedQuantity, int $availableStock): string
    {
        $locale = TelegramLocale::STORE_CHANNEL_LOCALE;
        $unitPrice = $this->locale->translateLocale($locale, 'notification.store_channel.restock.price', [
            'amount' => number_format($product->price_amount, 0, ',', '.'),
        ]);
        if ($product->usdt_price_amount !== null) {
            $unitPrice .= ' / '.$this->locale->translateLocale($locale, 'notification.store_channel.restock.binance', [
                'amount' => $this->formatUsdt($product->usdt_price_amount),
            ]);
        }

        return implode("\n", [
            $this->locale->translateLocale($locale, 'notification.store_channel.restock.title', ['product' => $product->name]),
            $this->locale->translateLocale($locale, 'notification.store_channel.restock.added', [
                'quantity' => $addedQuantity,
            ]),
            $this->locale->translateLocale($locale, 'notification.store_channel.restock.available', [
                'stock' => $availableStock,
            ]),
            $unitPrice,
        ]);
    }

    private function storeChannelSoldOutMessage(Product $product): string
    {
        $locale = TelegramLocale::STORE_CHANNEL_LOCALE;

        return implode("\n", [
            '📦 '.$product->name,
            $this->locale->translateLocale($locale, 'notification.store_channel.sold_out.label'),
            '━━━━━━━━━━━━━━',
            '',
            $this->locale->translateLocale($locale, 'notification.store_channel.sold_out.confirmation'),
            '',
            $this->locale->translateLocale($locale, 'notification.store_channel.sold_out.thanks'),
            $this->locale->translateLocale($locale, 'notification.store_channel.sold_out.unavailable'),
            '',
            $this->locale->translateLocale($locale, 'notification.store_channel.sold_out.follow_up'),
        ]);
    }

    private function storeChannelProductReplyMarkup(Product $product): array
    {
        return [
            'inline_keyboard' => [[
                [
                    'text' => $this->locale->translateLocale(TelegramLocale::STORE_CHANNEL_LOCALE, 'notification.store_channel.cta'),
                    'url' => $this->urls->telegramProductStartUrl($product),
                ],
            ]],
        ];
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

    private function shouldNotifyCustomers(?int $availableBefore, ?string $channelChatId): bool
    {
        // The Store Channel is the canonical high-frequency stock feed. DM
        // customers only when availability is restored, unless the channel is
        // unavailable and must be used as a fallback.
        return $channelChatId === null || $availableBefore === null || $availableBefore <= 0;
    }

    private function formatUsdt(int $amount): string
    {
        return rtrim(rtrim(number_format($amount / 1_000_000, 6, '.', ''), '0'), '.');
    }
}
