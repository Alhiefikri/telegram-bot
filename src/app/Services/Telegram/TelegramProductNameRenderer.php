<?php

namespace App\Services\Telegram;

use App\Catalog\CatalogIconSelection;
use App\Models\Product;
use App\Services\Catalog\CatalogFamilyIconResolver;

final class TelegramProductNameRenderer
{
    public function __construct(
        private readonly CatalogIconResolver $icons,
        private readonly TelegramHtmlCaption $html,
        private readonly CatalogFamilyIconResolver $familyIcons,
    ) {}

    public function render(
        string $name,
        ?string $glyph,
        ?string $customEmojiId,
        string $scope = CustomEmojiCapability::SCOPE_MESSAGES,
    ): string {
        $icon = $this->icons->resolve($glyph, $customEmojiId, $scope);

        return $this->html->icon($icon).' '.$name;
    }

    /**
     * Render a live Product with the icon its family presents in the catalog, so
     * a variant that owns no emoji of its own never drops to a plain glyph while
     * every other surface shows the shared family icon.
     */
    public function renderProduct(Product $product, string $scope = CustomEmojiCapability::SCOPE_MESSAGES): string
    {
        return $this->renderSelection($product->name, $this->familyIcons->forProduct($product), $scope);
    }

    public function renderSelection(
        string $name,
        CatalogIconSelection $selection,
        string $scope = CustomEmojiCapability::SCOPE_MESSAGES,
    ): string {
        return $this->render($name, $selection->icon, $selection->customEmojiId, $scope);
    }
}
