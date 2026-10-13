<?php

namespace App\Services\Telegram;

use App\Models\Order;
use App\Models\SupplierAccountUnit;
use App\Services\Warranty\WarrantyOrderAccess;
use InvalidArgumentException;

/**
 * Renders the non-secret action panel that follows a completed paid receipt.
 *
 * The panel deliberately carries only an Order identifier in callback data.
 * Credential presentation belongs to the TXT confirmation flow and is never
 * needed to render this message.
 */
final class TelegramReceiptActionPanel
{
    public const TXT_CALLBACK_PREFIX = 'txt:request:';

    public const BUY_AGAIN_CALLBACK_PREFIX = 'receipt:buy_again:';

    public const MAIN_MENU_CALLBACK_PREFIX = 'receipt:main_menu:';

    public function __construct(
        private readonly TelegramLocale $locale,
        private readonly TelegramSupportContact $supportContact,
    ) {}

    public function render(Order $order, string $locale, bool $txtAvailable, bool $withPrivacyGuidance = false): TelegramMessageUnit
    {
        $orderId = $this->callbackOrderId($order);
        $rows = [];

        if ($txtAvailable) {
            $rows[] = [[
                'text' => $this->locale->translateLocale($locale, 'receipt.actions.txt_button'),
                'callback_data' => self::TXT_CALLBACK_PREFIX.$orderId,
            ]];
        }

        $supportUrl = trim((string) ($this->supportContact->resolve()['url'] ?? ''));
        if ($supportUrl !== '') {
            $rows[] = [[
                'text' => $this->locale->translateLocale($locale, 'receipt.actions.support_button'),
                'url' => $supportUrl,
            ]];
        }
        if ($order->exists && $order->customer?->telegram_id !== null
            && SupplierAccountUnit::query()->where('order_id', $order->id)->exists()) {
            $rows[] = [[
                'text' => $this->locale->translateLocale($locale, 'order.warranty.title'),
                'url' => app(WarrantyOrderAccess::class)->telegramUrl($order, $locale),
            ]];
        }

        $rows[] = [[
            'text' => $this->locale->translateLocale($locale, 'receipt.actions.buy_again_button'),
            'callback_data' => self::BUY_AGAIN_CALLBACK_PREFIX.$orderId,
        ]];
        $rows[] = [[
            'text' => $this->locale->translateLocale($locale, 'receipt.actions.main_menu_button'),
            'callback_data' => self::MAIN_MENU_CALLBACK_PREFIX.$orderId,
        ]];

        $lines = [];
        if ($withPrivacyGuidance) {
            $lines[] = $this->locale->translateLocale($locale, 'receipt.save_details');
            if ($txtAvailable) {
                $lines[] = $this->locale->translateLocale($locale, 'receipt.copy_guidance');
            }
            $lines[] = $this->locale->translateLocale($locale, 'receipt.thanks');
        }
        $lines[] = $this->locale->translateLocale($locale, 'receipt.actions.message');

        return new TelegramMessageUnit(
            implode("\n\n", $lines),
            replyMarkup: ['inline_keyboard' => $rows],
        );
    }

    private function callbackOrderId(Order $order): string
    {
        $orderId = trim((string) $order->getKey());
        if (preg_match('/\A[0-9a-fA-F-]{36}\z/D', $orderId) !== 1) {
            throw new InvalidArgumentException('Receipt action panel Order identifier is invalid.');
        }

        foreach ([
            self::TXT_CALLBACK_PREFIX.$orderId,
            self::BUY_AGAIN_CALLBACK_PREFIX.$orderId,
            self::MAIN_MENU_CALLBACK_PREFIX.$orderId,
        ] as $callbackData) {
            if (strlen($callbackData) > 64) {
                throw new InvalidArgumentException('Receipt action panel callback data is too long.');
            }
        }

        return $orderId;
    }
}
