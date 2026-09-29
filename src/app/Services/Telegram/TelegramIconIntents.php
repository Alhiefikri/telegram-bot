<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\TelegramUiIcon;
use Closure;
use Throwable;

/**
 * Admin-only flow that captures Product, menu-button, and static message-copy
 * emoji settings from one private Telegram chat.
 */
class TelegramIconIntents
{
    private const CANCEL_COMMANDS = ['/batal', '/cancel'];

    public function __construct(
        private TelegramBotClient $bot,
        private TelegramLocale $locale,
        private TelegramIconCaptureSession $captures,
        private TelegramUiIcons $uiIcons,
        private CatalogIconResolver $catalogIcons,
    ) {}

    public function hasPendingCapture(Customer $customer): bool
    {
        return $this->captures->get($customer) !== null;
    }

    public function forgetPendingCapture(Customer $customer): void
    {
        $this->captures->forget($customer);
    }

    public function showProducts(Customer $customer, string $chatId, ?int $messageId = null): void
    {
        $families = ProductFamily::query()
            ->with('products:id,product_family_id,icon,custom_emoji_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $buttons = [];
        foreach ($families as $family) {
            $icon = $this->familySlotIcon($family);

            $buttons[] = [$this->slotButton(
                $customer,
                (string) $family->name,
                $icon['glyph'],
                $icon['id'],
                $icon['captured'],
                'familyicon:pick:'.$family->getKey(),
            )];
        }

        $standalone = Product::query()
            ->whereNull('product_family_id')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
        foreach ($standalone as $product) {
            $icon = $this->catalogIcons->resolve($product->icon, $product->custom_emoji_id);

            $buttons[] = [$this->slotButton(
                $customer,
                (string) $product->name,
                $icon->glyph,
                $icon->customEmojiId,
                $this->hasCapturedEmoji($product),
                'producticon:pick:'.$product->getKey(),
            )];
        }
        $buttons[] = [[
            'text' => $this->locale->translate($customer, 'catalog.ui_icon.menu_label'),
            'callback_data' => 'menuicon:list',
        ]];
        $buttons[] = [[
            'text' => $this->locale->translate($customer, 'catalog.ui_icon.message_label'),
            'callback_data' => 'messageicon:list',
        ]];
        $buttons[] = [$this->homeRow($customer)];

        $count = $families->count() + $standalone->count();
        $body = $count === 0
            ? $this->locale->translate($customer, 'catalog.product_icon.empty')
            : $this->locale->translate($customer, 'catalog.product_icon.list_body', ['count' => $count]);

        $this->bot->editMessageTextOrSend(
            $chatId,
            $body,
            ['inline_keyboard' => $buttons],
            $messageId,
        );
    }

    /**
     * A family icon is set once for the whole product line: the row previews the
     * variant that already carries a captured emoji, otherwise the first plan
     * glyph.
     *
     * @return array{glyph: string, id: string|null, captured: bool}
     */
    private function familySlotIcon(ProductFamily $family): array
    {
        $glyph = null;
        $customEmojiId = null;
        $captured = false;

        foreach ($family->products as $member) {
            $memberGlyph = trim((string) $member->icon);
            if ($glyph === null && $memberGlyph !== '') {
                $glyph = $memberGlyph;
            }

            if ($captured || ! $this->hasCapturedEmoji($member)) {
                continue;
            }

            $captured = true;
            $customEmojiId = $this->catalogIcons->resolve($member->icon, $member->custom_emoji_id)->customEmojiId;
            $glyph = $memberGlyph !== '' ? $memberGlyph : $glyph;
        }

        return [
            'glyph' => $glyph ?? $this->catalogIcons->resolve(null, null)->glyph,
            'id' => $customEmojiId,
            'captured' => $captured,
        ];
    }

    public function promptFamily(Customer $customer, string $chatId, ProductFamily $family, ?int $messageId): void
    {
        $this->captures->startFamily($customer, (int) $family->getKey(), $messageId);

        $this->bot->editMessageTextOrSend(
            $chatId,
            $this->locale->translate($customer, 'catalog.product_icon.prompt', ['label' => $family->name]),
            ['inline_keyboard' => [[$this->backRow($customer, 'producticon:list', 'catalog.product_icon.button_back')]]],
            $messageId,
        );
    }

    public function clearFamily(Customer $customer, string $chatId, ProductFamily $family, ?int $messageId): void
    {
        $family->products()->update(['custom_emoji_id' => null]);

        $this->bot->editMessageTextOrSend(
            $chatId,
            $this->locale->translate($customer, 'catalog.product_icon.cleared', ['label' => $family->name]),
            ['inline_keyboard' => [[$this->backRow($customer, 'producticon:list', 'catalog.product_icon.button_back')]]],
            $messageId,
        );
    }

    public function promptProduct(Customer $customer, string $chatId, Product $product, ?int $messageId): void
    {
        $this->captures->startProduct($customer, (int) $product->getKey(), $messageId);

        $this->bot->editMessageTextOrSend(
            $chatId,
            $this->locale->translate($customer, 'catalog.product_icon.prompt', ['label' => $product->name]),
            ['inline_keyboard' => [[$this->backRow($customer, 'producticon:list', 'catalog.product_icon.button_back')]]],
            $messageId,
        );
    }

    public function clearProduct(Customer $customer, string $chatId, Product $product, ?int $messageId): void
    {
        $product->update(['custom_emoji_id' => null]);

        $this->bot->editMessageTextOrSend(
            $chatId,
            $this->locale->translate($customer, 'catalog.product_icon.cleared', ['label' => $product->name]),
            ['inline_keyboard' => [[$this->backRow($customer, 'producticon:list', 'catalog.product_icon.button_back')]]],
            $messageId,
        );
    }

    public function showMenuIcons(Customer $customer, string $chatId, ?int $messageId = null): void
    {
        $buttons = [];
        foreach (TelegramUiIcon::query()->where('key', 'like', 'menu.%')->catalogOrder()->get() as $icon) {
            $buttons[] = [$this->slotButton(
                $customer,
                $icon->label,
                (string) $icon->fallback_emoji,
                $this->uiIcons->customEmojiId($icon->key),
                $icon->hasCustomEmoji(),
                'menuicon:pick:'.$icon->id,
            )];
        }
        $buttons[] = [[
            'text' => $this->locale->translate($customer, 'catalog.ui_icon.product_label'),
            'callback_data' => 'producticon:list',
        ]];
        $buttons[] = [[
            'text' => $this->locale->translate($customer, 'catalog.ui_icon.message_label'),
            'callback_data' => 'messageicon:list',
        ]];
        $buttons[] = [$this->homeRow($customer)];

        $this->bot->editMessageTextOrSend(
            $chatId,
            $this->locale->translate($customer, 'catalog.ui_icon.list_body'),
            ['inline_keyboard' => $buttons],
            $messageId,
        );
    }

    public function showMessageIcons(Customer $customer, string $chatId, ?int $messageId = null): void
    {
        $buttons = [];
        foreach ($this->uiIcons->messageCopyIcons() as $icon) {
            $buttons[] = [$this->slotButton(
                $customer,
                $icon->label,
                (string) $icon->fallback_emoji,
                $this->uiIcons->customEmojiId($icon->key),
                $icon->hasCustomEmoji(),
                'messageicon:pick:'.$icon->id,
            )];
        }
        $buttons[] = [[
            'text' => $this->locale->translate($customer, 'catalog.ui_icon.menu_label'),
            'callback_data' => 'menuicon:list',
        ]];
        $buttons[] = [[
            'text' => $this->locale->translate($customer, 'catalog.ui_icon.product_label'),
            'callback_data' => 'producticon:list',
        ]];
        $buttons[] = [$this->homeRow($customer)];

        $this->bot->editMessageTextOrSend(
            $chatId,
            $this->locale->translate($customer, 'catalog.message_icon.list_body'),
            ['inline_keyboard' => $buttons],
            $messageId,
        );
    }

    public function promptMessageIcon(Customer $customer, string $chatId, TelegramUiIcon $icon, ?int $messageId): void
    {
        $this->captures->startMessage($customer, (int) $icon->getKey(), $messageId);

        $this->bot->editMessageTextOrSend(
            $chatId,
            $this->locale->translate($customer, 'catalog.message_icon.prompt', ['label' => $icon->label]),
            ['inline_keyboard' => [[$this->backRow($customer, 'messageicon:list', 'catalog.message_icon.button_back')]]],
            $messageId,
        );
    }

    public function clearMessageIcon(Customer $customer, string $chatId, TelegramUiIcon $icon, ?int $messageId): void
    {
        $icon->update(['custom_emoji_id' => null]);

        $this->bot->editMessageTextOrSend(
            $chatId,
            $this->locale->translate($customer, 'catalog.message_icon.cleared', ['label' => $icon->label]),
            ['inline_keyboard' => [[$this->backRow($customer, 'messageicon:list', 'catalog.message_icon.button_back')]]],
            $messageId,
        );
    }

    public function promptMenuIcon(Customer $customer, string $chatId, TelegramUiIcon $icon, ?int $messageId): void
    {
        $this->captures->startMenu($customer, (int) $icon->getKey(), $messageId);

        $this->bot->editMessageTextOrSend(
            $chatId,
            $this->locale->translate($customer, 'catalog.ui_icon.prompt', ['label' => $icon->label]),
            ['inline_keyboard' => [[$this->backRow($customer, 'menuicon:list', 'catalog.ui_icon.button_back')]]],
            $messageId,
        );
    }

    public function clearMenuIcon(Customer $customer, string $chatId, TelegramUiIcon $icon, ?int $messageId): void
    {
        $icon->update(['custom_emoji_id' => null]);

        $this->bot->editMessageTextOrSend(
            $chatId,
            $this->locale->translate($customer, 'catalog.ui_icon.cleared', ['label' => $icon->label]),
            ['inline_keyboard' => [[$this->backRow($customer, 'menuicon:list', 'catalog.ui_icon.button_back')]]],
            $messageId,
        );
    }

    /**
     * Consume the next private message while a capture is pending. Returns true
     * when the message belonged to the capture flow and must not fall through
     * to the normal command routing.
     *
     * For each slot, a Telegram custom emoji stores its identifier and
     * API-provided Unicode fallback; a plain Unicode emoji can set the fallback
     * directly.
     */
    public function captureFromMessage(Customer $customer, string $chatId, array $message): bool
    {
        $pending = $this->captures->get($customer);
        if ($pending === null) {
            return false;
        }

        $rawText = trim((string) ($message['text'] ?? $message['caption'] ?? ''));
        if (in_array(mb_strtolower($rawText), self::CANCEL_COMMANDS, true)) {
            $this->captures->forget($customer);
            $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'catalog.product_icon.cancelled'));

            return true;
        }

        $target = $this->target($pending);
        if ($target === null) {
            $this->captures->forget($customer);
            $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'catalog.product_icon.expired'));

            return true;
        }

        $ids = $this->customEmojiIds($message);
        if ($ids !== []) {
            if (count($ids) > 1) {
                $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'catalog.product_icon.too_many'));

                return true;
            }

            $sticker = $this->customEmojiSticker($ids[0]);
            if ($sticker === null) {
                $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'catalog.product_icon.unknown_id'));

                return true;
            }

            ($target['save_emoji'])($ids[0], $sticker['emoji']);
            $this->captures->forget($customer);
            $this->confirmCapture($customer, $chatId, $target, $pending, $target['saved_key'], true);

            return true;
        }

        $glyph = $target['save_glyph'] === null ? null : $this->plainEmoji($rawText);
        if ($glyph !== null) {
            ($target['save_glyph'])($glyph);
            $this->captures->forget($customer);
            $this->confirmCapture($customer, $chatId, $target, $pending, $target['saved_glyph_key'], false);

            return true;
        }

        $this->bot->sendMessage($chatId, $this->locale->translate($customer, $target['not_an_emoji_key']));

        return true;
    }

    /**
     * @param  array{label: string, save_emoji: Closure(string, ?string): void, save_glyph: Closure(string): void|null, clear_callback: string, back_callback: string, back_key: string, saved_key: string, saved_glyph_key: string, not_an_emoji_key: string}  $target
     * @param  array{kind: string, target_id: int, message_id: int|null, expires_at: int}  $pending
     */
    private function confirmCapture(Customer $customer, string $chatId, array $target, array $pending, string $messageKey, bool $offerClear): void
    {
        $rows = [];
        if ($offerClear) {
            $rows[] = [[
                'text' => $this->locale->translate($customer, 'catalog.product_icon.button_clear'),
                'callback_data' => $target['clear_callback'],
            ]];
        }
        $rows[] = [$this->backRow($customer, $target['back_callback'], $target['back_key'])];

        $this->bot->editMessageTextOrSend(
            $chatId,
            $this->locale->translate($customer, $messageKey, ['label' => $target['label']]),
            ['inline_keyboard' => $rows],
            $pending['message_id'],
        );
    }

    /**
     * A single plain Unicode emoji sent while a capture is pending. Letters and
     * words are rejected so an ordinary message never overwrites the icon.
     */
    private function plainEmoji(string $text): ?string
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 32) {
            return null;
        }
        if (preg_match('/\p{L}/u', $text) === 1) {
            return null;
        }
        if (preg_match('/[\x{1F000}-\x{1FAFF}\x{1F1E6}-\x{1F1FF}\x{2190}-\x{21FF}\x{2300}-\x{27BF}\x{2900}-\x{2BFF}\x{FE0F}\x{200D}\x{20E3}]/u', $text) !== 1) {
            return null;
        }

        return $text;
    }

    /**
     * @param  array{kind: string, target_id: int, message_id: int|null, expires_at: int}  $pending
     * @return array{label: string, save_emoji: Closure(string, ?string): void, save_glyph: Closure(string): void, clear_callback: string, back_callback: string, back_key: string, saved_key: string, saved_glyph_key: string, not_an_emoji_key: string}|null
     */
    private function target(array $pending): ?array
    {
        if (in_array($pending['kind'], [TelegramIconCaptureSession::KIND_MENU, TelegramIconCaptureSession::KIND_MESSAGE], true)) {
            $icon = TelegramUiIcon::query()
                ->when($pending['kind'] === TelegramIconCaptureSession::KIND_MESSAGE, static fn ($query) => $query->where('key', 'like', 'message.%'))
                ->when($pending['kind'] === TelegramIconCaptureSession::KIND_MENU, static fn ($query) => $query->where('key', 'like', 'menu.%'))
                ->find($pending['target_id']);
            if (! $icon instanceof TelegramUiIcon) {
                return null;
            }

            $isMessageIcon = $pending['kind'] === TelegramIconCaptureSession::KIND_MESSAGE;

            return [
                'label' => $icon->label,
                'save_emoji' => static function (string $customEmojiId, ?string $glyph) use ($icon): void {
                    $values = ['custom_emoji_id' => $customEmojiId];
                    if ($glyph !== null) {
                        $values['fallback_emoji'] = $glyph;
                    }
                    $icon->update($values);
                },
                'save_glyph' => static fn (string $glyph) => $icon->update([
                    'custom_emoji_id' => null,
                    'fallback_emoji' => $glyph,
                ]),
                'clear_callback' => ($isMessageIcon ? 'messageicon:clear:' : 'menuicon:clear:').$icon->id,
                'back_callback' => $isMessageIcon ? 'messageicon:list' : 'menuicon:list',
                'back_key' => $isMessageIcon ? 'catalog.message_icon.button_back' : 'catalog.ui_icon.button_back',
                'saved_key' => $isMessageIcon ? 'catalog.message_icon.saved' : 'catalog.ui_icon.saved',
                'saved_glyph_key' => $isMessageIcon ? 'catalog.message_icon.saved_glyph' : 'catalog.ui_icon.saved_glyph',
                'not_an_emoji_key' => 'catalog.ui_icon.not_an_emoji',
            ];
        }

        if ($pending['kind'] === TelegramIconCaptureSession::KIND_FAMILY) {
            $family = ProductFamily::query()->find($pending['target_id']);
            if (! $family instanceof ProductFamily) {
                return null;
            }

            return [
                'label' => (string) $family->name,
                'save_emoji' => static function (string $customEmojiId, ?string $emojiGlyph) use ($family): void {
                    $values = ['custom_emoji_id' => $customEmojiId];
                    if ($emojiGlyph !== null) {
                        $values['icon'] = $emojiGlyph;
                    }

                    // One icon per family: every variant follows the parent.
                    $family->products()->update($values);
                },
                // A plain emoji sent in the bot can set the catalog glyph directly.
                'save_glyph' => static fn (string $glyph) => $family->products()->update(['icon' => $glyph]),
                'clear_callback' => 'familyicon:clear:'.$family->getKey(),
                'back_callback' => 'producticon:list',
                'back_key' => 'catalog.product_icon.button_back',
                'saved_key' => 'catalog.product_icon.saved',
                'saved_glyph_key' => 'catalog.product_icon.saved_glyph',
                'not_an_emoji_key' => 'catalog.product_icon.not_an_emoji',
            ];
        }

        if ($pending['kind'] !== TelegramIconCaptureSession::KIND_PRODUCT) {
            return null;
        }
        $product = Product::query()->find($pending['target_id']);
        if (! $product instanceof Product) {
            return null;
        }

        return [
            'label' => $product->name,
            'save_emoji' => static function (string $customEmojiId, ?string $emojiGlyph) use ($product): void {
                $values = ['custom_emoji_id' => $customEmojiId];
                if ($emojiGlyph !== null) {
                    $values['icon'] = $emojiGlyph;
                }

                $product->update($values);
            },
            // A plain emoji sent in the bot can set the catalog glyph directly.
            'save_glyph' => static fn (string $glyph) => $product->update(['icon' => $glyph]),
            'clear_callback' => 'producticon:clear:'.$product->getKey(),
            'back_callback' => 'producticon:list',
            'back_key' => 'catalog.product_icon.button_back',
            'saved_key' => 'catalog.product_icon.saved',
            'saved_glyph_key' => 'catalog.product_icon.saved_glyph',
            'not_an_emoji_key' => 'catalog.product_icon.not_an_emoji',
        ];
    }

    /**
     * @return list<string>
     */
    private function customEmojiIds(array $message): array
    {
        $ids = [];
        foreach ((array) ($message['entities'] ?? []) as $entity) {
            if (! is_array($entity) || ($entity['type'] ?? null) !== 'custom_emoji') {
                continue;
            }

            $id = trim((string) ($entity['custom_emoji_id'] ?? ''));
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Resolve both the captured identifier and Telegram's plain-text fallback.
     *
     * @return array{emoji: ?string}|null
     */
    private function customEmojiSticker(string $customEmojiId): ?array
    {
        try {
            $response = $this->bot->getCustomEmojiStickers([$customEmojiId]);
        } catch (Throwable) {
            return null;
        }

        foreach ((array) ($response['result'] ?? []) as $sticker) {
            if (! is_array($sticker) || (string) ($sticker['custom_emoji_id'] ?? '') !== $customEmojiId) {
                continue;
            }

            $emoji = $sticker['emoji'] ?? null;

            return ['emoji' => is_string($emoji) && trim($emoji) !== '' ? $emoji : null];
        }

        return null;
    }

    private function statusLabel(Customer $customer, string $label, bool $ready): string
    {
        return $this->locale->translate($customer, $ready
            ? 'catalog.product_icon.status_ready'
            : 'catalog.product_icon.status_empty', ['label' => $label]);
    }

    /**
     * One icon-list button that previews how the slot renders: a captured
     * premium emoji becomes the button icon (the same shape catalog buttons
     * use), while a Unicode-only slot keeps its glyph in the text. The status
     * marker reflects the stored value, not the preview.
     *
     * @return array{text: string, callback_data: string}|array{text: string, callback_data: string, icon_custom_emoji_id: string}
     */
    private function slotButton(
        Customer $customer,
        string $label,
        string $glyph,
        ?string $customEmojiId,
        bool $captured,
        string $callbackData,
    ): array {
        $previewId = trim((string) $customEmojiId);
        $text = $this->statusLabel($customer, $previewId === '' ? trim($glyph.' '.$label) : $label, $captured);

        return [
            'text' => $text,
            ...($previewId === '' ? [] : ['icon_custom_emoji_id' => $previewId]),
            'callback_data' => $callbackData,
        ];
    }

    private function hasCapturedEmoji(Product $product): bool
    {
        return trim((string) $product->custom_emoji_id) !== '';
    }

    /**
     * @return array{text: string, callback_data: string}
     */
    private function backRow(Customer $customer, string $callbackData, string $localeKey): array
    {
        return [
            'text' => $this->locale->translate($customer, $localeKey),
            'callback_data' => $callbackData,
        ];
    }

    /**
     * @return array{text: string, callback_data: string}
     */
    private function homeRow(Customer $customer): array
    {
        return [
            'text' => $this->locale->translate($customer, 'menu.home'),
            'callback_data' => 'menu:home',
        ];
    }
}
