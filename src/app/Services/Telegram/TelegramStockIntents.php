<?php

namespace App\Services\Telegram;

use App\Catalog\CatalogBrowseQuery;
use App\Catalog\CatalogFamilyReadModel;
use App\Catalog\CatalogIcon;
use App\Catalog\CatalogProductReadModel;
use App\Models\Customer;
use App\Services\Catalog\CatalogBrowse;
use App\Services\Catalog\CatalogFamilyIconResolver;
use App\Storefront\StorefrontUrl;

/**
 * Render the public stock snapshot used by the /stock command.
 *
 * CatalogBrowse owns public visibility and live availability, while
 * StorefrontUrl owns the Telegram start-link contract.
 */
final class TelegramStockIntents
{
    private const CATALOG_PER_PAGE = 100;

    public function __construct(
        private readonly TelegramBotClient $bot,
        private readonly TelegramLocale $locale,
        private readonly CatalogBrowse $browse,
        private readonly CatalogIconResolver $icons,
        private readonly CatalogFamilyIconResolver $familyIcons,
        private readonly TelegramHtmlCaption $captions,
        private readonly TelegramMessageChunker $chunker,
        private readonly StorefrontUrl $urls,
    ) {}

    public function send(Customer $customer, string $chatId): void
    {
        $result = $this->browse->browsePublic(new CatalogBrowseQuery(perPage: self::CATALOG_PER_PAGE));
        $families = [];
        $standalone = [];

        foreach ($result->entries as $entry) {
            if ($entry->family instanceof CatalogFamilyReadModel) {
                $members = array_values(array_filter(
                    $entry->family->members,
                    static fn (mixed $member): bool => $member instanceof CatalogProductReadModel
                        && $member->isAvailable
                        && $member->availableStock > 0,
                ));

                if ($members !== []) {
                    $families[] = [$entry->family, $members];
                }

                continue;
            }

            if ($entry->product instanceof CatalogProductReadModel
                && $entry->product->isAvailable
                && $entry->product->availableStock > 0) {
                $standalone[] = $entry->product;
            }
        }

        if ($families === [] && $standalone === []) {
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

        foreach ($families as [$family, $members]) {
            /** @var CatalogFamilyReadModel $family */
            /** @var list<CatalogProductReadModel> $members */
            $icon = $this->familyIcon($family);
            $lines[] = '';
            $lines[] = $this->locale->translate($customer, 'stock.family_heading', [
                'icon' => $this->captions->icon($icon),
                'name' => $this->escapeHtml($family->name),
            ]);
            foreach ($members as $member) {
                $lines[] = $this->variantLine($customer, $member, $icon);
            }
        }

        if ($standalone !== []) {
            $lines[] = '';
            $lines[] = $this->locale->translate($customer, 'stock.other_heading');
            foreach ($standalone as $product) {
                $lines[] = $this->variantLine($customer, $product, $this->catalogIcon($product));
            }
        }

        $lines[] = '';
        $lines[] = $this->locale->translate($customer, 'stock.footer');

        foreach ($this->chunker->fromLines($lines) as $chunk) {
            $this->bot->sendMessage($chatId, $chunk, options: ['parse_mode' => 'HTML']);
        }
    }

    private function variantLine(
        Customer $customer,
        CatalogProductReadModel $product,
        CatalogIcon $icon,
    ): string {
        $name = $product->label !== '' ? $product->label : $product->name;
        $url = htmlspecialchars(
            $this->urls->telegramProductStartUrlForId($product->id),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
        $buy = '<a href="'.$url.'">'.$this->escapeHtml($this->locale->translate($customer, 'stock.buy')).'</a>';

        return $this->locale->translate($customer, 'stock.variant', [
            'icon' => $this->captions->icon($icon),
            'name' => $this->escapeHtml($name),
            'price' => $this->price($customer, $product),
            'stock' => (string) $product->availableStock,
            'sold' => (string) $product->soldQuantity,
            'buy' => $buy,
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

    private function catalogIcon(CatalogProductReadModel $product): CatalogIcon
    {
        return $this->icons->resolve($product->icon, $product->customEmojiId);
    }

    private function familyIcon(CatalogFamilyReadModel $family): CatalogIcon
    {
        $members = array_map(
            static fn (CatalogProductReadModel $member): array => [
                'icon' => $member->icon,
                'customEmojiId' => $member->customEmojiId,
            ],
            array_values(array_filter(
                $family->members,
                static fn (mixed $member): bool => $member instanceof CatalogProductReadModel,
            )),
        );
        $selection = $this->familyIcons->fromMembers($members);

        return $this->icons->resolve($selection->icon, $selection->customEmojiId);
    }

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
