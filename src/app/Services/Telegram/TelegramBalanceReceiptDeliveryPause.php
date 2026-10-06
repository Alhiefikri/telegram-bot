<?php

namespace App\Services\Telegram;

use App\Enums\CustomerUsdtFundingPauseScope;
use App\Enums\TelegramOutboundEffectType;
use App\Models\CustomerUsdtFundingPause;

/**
 * Delivery pause for balance receipt messages (#558).
 *
 * While the Owner pause scope `delivery` exists, balance receipt effects stay
 * durable and pending: they are never claimed, dispatched or resent, and they
 * are delivered unchanged (with their original claim history untouched) once
 * the pause is resumed. Money is never affected: settlement, refunds and
 * reconciliation keep running, and the receipt effect row is still created
 * inside the money transaction.
 *
 * The check reads the pause row on every call instead of memoizing, so a pause
 * activated while a queue worker is running takes effect on the next claim.
 */
final class TelegramBalanceReceiptDeliveryPause
{
    /** @var list<string> */
    public const EFFECT_TYPES = [
        TelegramOutboundEffectType::BalanceTopUpReceipt->value,
        TelegramOutboundEffectType::UsdtTopUpReceipt->value,
        TelegramOutboundEffectType::UsdtRefundReceipt->value,
    ];

    public function active(): bool
    {
        return CustomerUsdtFundingPause::query()
            ->where('scope', CustomerUsdtFundingPauseScope::Delivery->value)
            ->exists();
    }

    public function pausesEffect(string $effectType): bool
    {
        return in_array($effectType, self::EFFECT_TYPES, true) && $this->active();
    }
}
