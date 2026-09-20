<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use App\Models\Product;
use App\Models\StoreChannelSetting;
use App\Services\Pricing\ProductPriceTierQuote;
use App\Services\Pricing\ProductQuantityPricing;
use App\Storefront\StorefrontUrl;
use InvalidArgumentException;
use Throwable;

class TelegramProductNotificationService
{
    public function __construct(
        private TelegramBroadcastService $broadcast,
        private TelegramBotClient $bot,
        private TelegramAdminAccess $adminAccess,
        private StorefrontUrl $urls,
        private TelegramLocale $locale,
        private ProductQuantityPricing $pricing,
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

        $promotedTier = $this->promotedTier($product, null, $availableStock);

        return $this->broadcast->sendToActiveUsers(
            fn (Customer $customer) => implode("\n", [
                $this->locale->translate($customer, 'notification.product.created.lead'),
                '📦 '.$product->name,
                $this->locale->translate($customer, 'notification.product.price', [
                    'amount' => number_format($product->price_amount, 0, ',', '.'),
                ]),
                $this->locale->translate($customer, 'notification.product.stock', ['stock' => $availableStock]),
                ...$this->customerTierLines($customer, $promotedTier),
                '',
                $this->locale->translate($customer, 'notification.product.description'),
                $this->descriptionText($product, $this->locale->code($customer)),
            ]),
            fn (Customer $customer) => $this->locale->translate($customer, 'notification.product.created.title'),
            fn (Customer $customer) => $this->productReplyMarkup($product, $availableStock, $this->locale->code($customer)),
        );
    }

    public function notifyStockAdded(
        Product $product,
        int $addedQuantity,
        ?int $availableBefore = null,
        ?int $availableAfter = null,
    ): array {
        $product = $product->fresh();
        if (! $product || ! $product->is_active || $addedQuantity < 1) {
            return $this->skippedResult();
        }

        $availableStock = $product->availableStock();
        if ($availableStock < 1) {
            return $this->skippedResult();
        }

        $availableAfter ??= $availableStock;
        $promotedTier = $this->promotedTier($product, $availableBefore, $availableAfter);
        $channelChatId = $this->storeChannelChatId();
        $this->sendStoreChannel(
            $this->storeChannelRestockMessage($product, $addedQuantity, $availableStock, $promotedTier),
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
                    ...$this->customerTierLines($customer, $promotedTier),
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
        $deliveries = [];
        $cursor = 0;

        do {
            $batch = $this->stockAddedDeliveryBatch(
                $product,
                $addedQuantity,
                $availableBefore,
                $availableAfter,
                $cursor,
                100,
            );
            $deliveries = [...$deliveries, ...$batch['deliveries']];
            $cursor = $batch['next_cursor'];
        } while ($batch['has_more']);

        return $deliveries;
    }

    /**
     * @return array{
     *     deliveries:list<array{customer_id:?int,recipient_type:string,telegram_id:string,locale:?string,text:string,reply_markup:array,photo_path:?string}>,
     *     next_cursor:int,
     *     has_more:bool
     * }
     */
    public function stockAddedDeliveryBatch(
        Product $product,
        int $addedQuantity,
        ?int $availableBefore,
        ?int $availableAfter = null,
        int $customerCursor = 0,
        int $limit = 100,
        ?ProductPriceTierQuote $promotedTier = null,
        bool $promotedTierResolved = false,
    ): array {
        $product = $product->fresh();
        $customerCursor = max(0, $customerCursor);
        $limit = max(1, $limit);

        if (! $product || ! $product->is_active || $addedQuantity < 1) {
            return ['deliveries' => [], 'next_cursor' => $customerCursor, 'has_more' => false];
        }
        $availableStock = $availableAfter ?? $product->availableStock();
        if ($availableStock < 1) {
            return ['deliveries' => [], 'next_cursor' => $customerCursor, 'has_more' => false];
        }

        if (! $promotedTierResolved) {
            $promotedTier = $this->promotedTier($product, $availableBefore, $availableStock);
        }
        $channelChatId = $this->storeChannelChatId();
        $deliveries = [];
        if ($customerCursor === 0 && $channelChatId) {
            $deliveries[] = [
                'customer_id' => null,
                'recipient_type' => 'store_channel',
                'telegram_id' => $channelChatId,
                'locale' => null,
                'text' => $this->storeChannelRestockMessage($product, $addedQuantity, $availableStock, $promotedTier),
                'reply_markup' => $this->storeChannelProductReplyMarkup($product),
                'photo_path' => $this->telegramImagePath($product),
            ];
        }

        if (! $this->shouldNotifyCustomers($availableBefore, $channelChatId)) {
            return ['deliveries' => $deliveries, 'next_cursor' => $customerCursor, 'has_more' => false];
        }

        $customers = Customer::query()
            ->whereNotNull('telegram_id')
            ->where('id', '>', $customerCursor)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get(['id', 'telegram_id', 'locale']);
        $customerPage = $customers->take($limit);
        $deliveries = [
            ...$deliveries,
            ...$this->renderCustomerDeliveries(
                $product,
                $availableStock,
                false,
                $customerPage->filter(fn (Customer $customer): bool => trim((string) $customer->telegram_id) !== ''),
                $promotedTier,
            ),
        ];

        return [
            'deliveries' => $deliveries,
            'next_cursor' => (int) ($customerPage->last()?->id ?? $customerCursor),
            'has_more' => $customers->count() > $limit,
        ];
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
        $customers = Customer::query()
            ->whereNotNull('telegram_id')
            ->orderBy('id')
            ->get(['id', 'telegram_id', 'locale'])
            ->filter(function (Customer $customer) use (&$seen): bool {
                $id = trim((string) $customer->telegram_id);
                if ($id === '' || isset($seen[$id])) {
                    return false;
                }
                $seen[$id] = true;

                return true;
            });

        return $this->renderCustomerDeliveries($product, $availableStock, $created, $customers);
    }

    /**
     * @param  iterable<Customer>  $customers
     * @return list<array{customer_id:?int,recipient_type:string,telegram_id:string,locale:string,text:string,reply_markup:array,photo_path:?string}>
     */
    private function renderCustomerDeliveries(
        Product $product,
        int $availableStock,
        bool $created,
        iterable $customers,
        ?ProductPriceTierQuote $promotedTier = null,
    ): array {
        $deliveries = [];
        foreach ($customers as $customer) {
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
                ...$this->customerTierLines($customer, $promotedTier),
                '',
                $this->locale->translateLocale($locale, 'notification.product.description'),
                $this->descriptionText($product, $locale),
                '',
                $this->locale->translateLocale($locale, 'notification.broadcast.help'),
            ]);

            $deliveries[] = [
                'customer_id' => $customer->id,
                'recipient_type' => 'customer',
                'telegram_id' => (string) $customer->telegram_id,
                'locale' => $locale,
                'text' => $text,
                'reply_markup' => $this->productReplyMarkup($product, $availableStock, $locale),
                'photo_path' => null,
            ];
        }

        return $deliveries;
    }

    /** @return list<string> */
    private function customerTierLines(Customer $customer, ?ProductPriceTierQuote $tier): array
    {
        if ($tier === null) {
            return [];
        }

        return [$this->locale->translate($customer, 'notification.product.tier', [
            'minimum' => $tier->minimumQuantity,
            'amount' => number_format($tier->priceAmount, 0, ',', '.'),
        ])];
    }

    public function tierPromotionForStock(Product $product, ?int $availableBefore, int $availableAfter): ?ProductPriceTierQuote
    {
        return $this->promotedTier($product, $availableBefore, $availableAfter);
    }

    private function promotedTier(Product $product, ?int $availableBefore, int $availableAfter): ?ProductPriceTierQuote
    {
        if ($availableAfter < 1) {
            return null;
        }

        try {
            $quote = $this->pricing->quote($product, $availableAfter);
        } catch (InvalidArgumentException $exception) {
            report($exception);

            return null;
        }

        $before = $availableBefore === null ? null : max(0, $availableBefore);
        foreach ($quote->orderedTiers as $tier) {
            if ($tier->minimumQuantity > $availableAfter) {
                break;
            }

            if ($before === null || $before < $tier->minimumQuantity) {
                return $tier;
            }
        }

        return null;
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

    private function storeChannelRestockMessage(
        Product $product,
        int $addedQuantity,
        int $availableStock,
        ?ProductPriceTierQuote $promotedTier = null,
    ): string {
        $locale = TelegramLocale::STORE_CHANNEL_LOCALE;
        $unitPrice = $this->locale->translateLocale($locale, 'notification.store_channel.restock.price', [
            'amount' => number_format($product->price_amount, 0, ',', '.'),
        ]);
        if ($product->usdt_price_amount !== null) {
            $unitPrice .= ' / '.$this->locale->translateLocale($locale, 'notification.store_channel.restock.binance', [
                'amount' => $this->formatUsdt($product->usdt_price_amount),
            ]);
        }

        $lines = [
            $this->locale->translateLocale($locale, 'notification.store_channel.restock.title', ['product' => $product->name]),
            $this->locale->translateLocale($locale, 'notification.store_channel.restock.added', [
                'quantity' => $addedQuantity,
            ]),
            $this->locale->translateLocale($locale, 'notification.store_channel.restock.available', [
                'stock' => $availableStock,
            ]),
            $unitPrice,
        ];
        if ($promotedTier !== null) {
            $tierLine = $this->locale->translateLocale($locale, 'notification.store_channel.restock.tier', [
                'minimum' => $promotedTier->minimumQuantity,
                'amount' => number_format($promotedTier->priceAmount, 0, ',', '.'),
            ]);
            if ($promotedTier->usdtPriceAmount !== null) {
                $tierLine .= ' / '.$this->formatUsdt($promotedTier->usdtPriceAmount).' USDT each';
            }
            $lines[] = $tierLine;
        }

        return implode("\n", $lines);
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
            $this->locale->translateLocale($locale, 'notification.store_channel.sold_out.thanks'),
            '',
            $this->locale->translateLocale($locale, 'notification.store_channel.sold_out.unavailable'),
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
