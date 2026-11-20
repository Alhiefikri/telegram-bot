<?php

namespace App\Services\Telegram;

use App\Catalog\CatalogProductReadModel;
use App\Models\Customer;
use App\Services\Catalog\CatalogBrowse;
use App\Storefront\StorefrontUrl;

/**
 * Render the public stock snapshot used by /stock and /stocks.
 *
 * CatalogBrowse owns public visibility and live availability, while
 * StorefrontUrl owns the Telegram start-link contract.
 */
final class TelegramStockIntents
{
    public function __construct(
        private readonly TelegramBotClient $bot,
        private readonly TelegramLocale $locale,
        private readonly CatalogBrowse $browse,
        private readonly CatalogIconResolver $icons,
        private readonly TelegramHtmlCaption $captions,
        private readonly TelegramMessageChunker $chunker,
        private readonly StorefrontUrl $urls,
    ) {}

    public function send(Customer $customer, string $chatId): void
    {
        $entries = $this->browse->allPublicEntries();
        if ($entries === []) {
            $this->bot->sendMessage(
                $chatId,
                $this->locale->translate($customer, 'stock.empty'),
                options: ['parse_mode' => 'HTML'],
            );

            return;
        }

        $lines = [
            $this->locale->translate($customer, 'stock.title'),
            '━━━━━━━━━━━━━━',
        ];

        $hasAvailableProducts = false;
        $hasOtherHeading = false;
        foreach ($entries as $entry) {
            $hasAvailableProducts = $hasAvailableProducts || $entry->isAvailable();
            $family = $entry->family;
            if ($family === null) {
                if ($entry->product === null) {
                    continue;
                }
                if (! $hasOtherHeading) {
                    $lines[] = '';
                    $lines[] = $this->locale->translate($customer, 'stock.other_heading');
                    $hasOtherHeading = true;
                }
                $lines[] = $this->variantLine($customer, $entry->product);

                continue;
            }

            $lines[] = '';
            $lines[] = $this->locale->translate($customer, 'stock.family_heading', [
                'icon' => $this->captions->icon($this->icons->resolve($family->icon, $family->customEmojiId)),
                'name' => $this->escapeHtml($family->name),
            ]);
            foreach ($family->members as $member) {
                $lines[] = $this->variantLine($customer, $member);
            }
        }

        if ($hasAvailableProducts) {
            $lines[] = '';
            $lines[] = $this->locale->translate($customer, 'stock.footer');
        }

        foreach ($this->chunker->fromLines($lines) as $chunk) {
            $this->bot->sendMessage($chatId, $chunk, options: ['parse_mode' => 'HTML']);
        }
    }

    private function variantLine(Customer $customer, CatalogProductReadModel $product): string
    {
        $name = $product->label !== '' ? $product->label : $product->name;
        $status = $this->locale->translate($customer, 'stock.sold_out');
        if ($product->isAvailable) {
            $url = $this->escapeHtml($this->urls->telegramProductStartUrlForId($product->id));
            $buy = $this->captions->escapeTextPreservingIcons($this->locale->translate($customer, 'stock.buy'));
            $status = '<a href="'.$url.'">'.$buy.'</a>';
        }

        return $this->locale->translate($customer, 'stock.variant', [
            'icon' => $this->captions->icon($this->icons->resolve($product->icon, $product->customEmojiId)),
            'name' => $this->escapeHtml($name),
            'price' => $this->price($customer, $product),
            'stock' => (string) $product->availableStock,
            'sold' => (string) $product->soldQuantity,
            'status' => $status,
        ]);
    }

    private function price(Customer $customer, CatalogProductReadModel $product): string
    {
        $key = $product->usdtPriceAmount === null ? 'stock.price' : 'stock.price_dual';
        $replace = ['amount' => number_format($product->priceAmount, 0, ',', '.')];
        if ($product->usdtPriceAmount !== null) {
            $replace['usdt'] = $this->locale->usdt($product->usdtPriceAmount);
        }

        return $this->locale->translate($customer, $key, $replace);
    }

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
