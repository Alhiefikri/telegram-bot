<?php

namespace App\Services\Telegram;

use App\Models\CatalogBrandIcon;
use App\Models\Customer;
use App\Models\TelegramUiIcon;
use Closure;
use Throwable;

/**
 * Admin-only flow that captures Telegram custom emoji identifiers for the two
 * icon slots of the Store Bot: catalog brands and named navigation buttons. The
 * admin opens a list, picks a slot, then sends that emoji in a private chat.
 */
class TelegramIconIntents
{
    private const CANCEL_COMMANDS = ['/batal', '/cancel'];

    public function __construct(
        private TelegramBotClient $bot,
        private TelegramLocale $locale,
        private TelegramIconCaptureSession $captures,
    ) {}

    public function hasPendingCapture(Customer $customer): bool
    {
        return $this->captures->get($customer) !== null;
    }

    public function forgetPendingCapture(Customer $customer): void
    {
        $this->captures->forget($customer);
    }

    public function showBrands(Customer $customer, string $chatId, ?int $messageId = null): void
    {
        $buttons = [];
        foreach (CatalogBrandIcon::query()->catalogOrder()->get() as $brand) {
            $buttons[] = [[
                'text' => $this->statusLabel($customer, $brand->label, $brand->hasCustomEmoji()),
                'callback_data' => 'brandicon:pick:'.$brand->id,
            ]];
        }
        $buttons[] = [[
            'text' => $this->locale->translate($customer, 'catalog.ui_icon.menu_label'),
            'callback_data' => 'menuicon:list',
        ]];
        $buttons[] = [$this->homeRow($customer)];

        $this->bot->editMessageTextOrSend(
            $chatId,
            $this->locale->translate($customer, 'catalog.brand_icon.list_body'),
            ['inline_keyboard' => $buttons],
            $messageId,
        );
    }

    public function promptBrand(Customer $customer, string $chatId, CatalogBrandIcon $brand, ?int $messageId): void
    {
        $this->captures->startBrand($customer, (int) $brand->getKey(), $messageId);

        $this->bot->editMessageTextOrSend(
            $chatId,
            $this->locale->translate($customer, 'catalog.brand_icon.prompt', ['label' => $brand->label]),
            ['inline_keyboard' => [[$this->backRow($customer, 'brandicon:list', 'catalog.brand_icon.button_back')]]],
            $messageId,
        );
    }

    public function clearBrand(Customer $customer, string $chatId, CatalogBrandIcon $brand, ?int $messageId): void
    {
        $brand->update(['custom_emoji_id' => null]);

        $this->bot->editMessageTextOrSend(
            $chatId,
            $this->locale->translate($customer, 'catalog.brand_icon.cleared', ['label' => $brand->label]),
            ['inline_keyboard' => [[$this->backRow($customer, 'brandicon:list', 'catalog.brand_icon.button_back')]]],
            $messageId,
        );
    }

    public function showMenuIcons(Customer $customer, string $chatId, ?int $messageId = null): void
    {
        $buttons = [];
        foreach (TelegramUiIcon::query()->catalogOrder()->get() as $icon) {
            $buttons[] = [[
                'text' => $this->statusLabel($customer, $icon->fallback_emoji.' '.$icon->label, $icon->hasCustomEmoji()),
                'callback_data' => 'menuicon:pick:'.$icon->id,
            ]];
        }
        $buttons[] = [[
            'text' => $this->locale->translate($customer, 'catalog.ui_icon.brand_label'),
            'callback_data' => 'brandicon:list',
        ]];
        $buttons[] = [$this->homeRow($customer)];

        $this->bot->editMessageTextOrSend(
            $chatId,
            $this->locale->translate($customer, 'catalog.ui_icon.list_body'),
            ['inline_keyboard' => $buttons],
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
     */
    public function captureFromMessage(Customer $customer, string $chatId, array $message): bool
    {
        $pending = $this->captures->get($customer);
        if ($pending === null) {
            return false;
        }

        $text = mb_strtolower(trim((string) ($message['text'] ?? $message['caption'] ?? '')));
        if (in_array($text, self::CANCEL_COMMANDS, true)) {
            $this->captures->forget($customer);
            $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'catalog.brand_icon.cancelled'));

            return true;
        }

        $target = $this->target($pending);
        if ($target === null) {
            $this->captures->forget($customer);
            $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'catalog.brand_icon.expired'));

            return true;
        }

        $ids = $this->customEmojiIds($message);
        if ($ids === []) {
            $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'catalog.brand_icon.not_an_emoji'));

            return true;
        }

        if (count($ids) > 1) {
            $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'catalog.brand_icon.too_many'));

            return true;
        }

        if (! $this->isKnownEmoji($ids[0])) {
            $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'catalog.brand_icon.unknown_id'));

            return true;
        }

        ($target['save'])($ids[0]);
        $this->captures->forget($customer);

        $this->bot->editMessageTextOrSend(
            $chatId,
            $this->locale->translate($customer, 'catalog.brand_icon.saved', ['label' => $target['label']]),
            ['inline_keyboard' => [
                [[
                    'text' => $this->locale->translate($customer, 'catalog.brand_icon.button_clear'),
                    'callback_data' => $target['clear_callback'],
                ]],
                [$this->backRow($customer, $target['back_callback'], $target['back_key'])],
            ]],
            $pending['message_id'],
        );

        return true;
    }

    /**
     * @param  array{kind: string, target_id: int, message_id: int|null, expires_at: int}  $pending
     * @return array{label: string, save: Closure(string): void, clear_callback: string, back_callback: string, back_key: string}|null
     */
    private function target(array $pending): ?array
    {
        if ($pending['kind'] === TelegramIconCaptureSession::KIND_MENU) {
            $icon = TelegramUiIcon::query()->find($pending['target_id']);
            if (! $icon instanceof TelegramUiIcon) {
                return null;
            }

            return [
                'label' => $icon->label,
                'save' => static fn (string $customEmojiId) => $icon->update(['custom_emoji_id' => $customEmojiId]),
                'clear_callback' => 'menuicon:clear:'.$icon->id,
                'back_callback' => 'menuicon:list',
                'back_key' => 'catalog.ui_icon.button_back',
            ];
        }

        $brand = CatalogBrandIcon::query()->find($pending['target_id']);
        if (! $brand instanceof CatalogBrandIcon) {
            return null;
        }

        return [
            'label' => $brand->label,
            'save' => static fn (string $customEmojiId) => $brand->update(['custom_emoji_id' => $customEmojiId]),
            'clear_callback' => 'brandicon:clear:'.$brand->id,
            'back_callback' => 'brandicon:list',
            'back_key' => 'catalog.brand_icon.button_back',
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
     * The identifier comes from Telegram, but confirming it resolves keeps a
     * deleted sticker set from being stored as if it were usable.
     */
    private function isKnownEmoji(string $customEmojiId): bool
    {
        try {
            $response = $this->bot->getCustomEmojiStickers([$customEmojiId]);
        } catch (Throwable) {
            return false;
        }

        foreach ((array) ($response['result'] ?? []) as $sticker) {
            if (is_array($sticker) && (string) ($sticker['custom_emoji_id'] ?? '') === $customEmojiId) {
                return true;
            }
        }

        return false;
    }

    private function statusLabel(Customer $customer, string $label, bool $ready): string
    {
        return $this->locale->translate($customer, $ready
            ? 'catalog.brand_icon.status_ready'
            : 'catalog.brand_icon.status_empty', ['label' => $label]);
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
