<?php

namespace App\Models;

use App\Support\PakasirCheckoutFlow;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['payment_mode', 'pakasir_checkout_flow', 'admin_contact_url', 'admin_username', 'admin_telegram_chat_id', 'instructions', 'is_active', 'binance_pay_enabled', 'qris_payment_ttl_minutes', 'binance_payment_ttl_minutes'])]
class ManualPaymentSetting extends Model
{
    public const DEFAULT_QRIS_PAYMENT_TTL_MINUTES = 10;

    public const DEFAULT_BINANCE_PAYMENT_TTL_MINUTES = 20;

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'binance_pay_enabled' => 'boolean'];
    }

    public function pakasirCheckoutFlow(): string
    {
        return PakasirCheckoutFlow::isAllowed($this->pakasir_checkout_flow)
            ? $this->pakasir_checkout_flow
            : PakasirCheckoutFlow::HOSTED;
    }

    public function qrisPaymentTtlMinutes(): int
    {
        return max(1, (int) ($this->qris_payment_ttl_minutes ?: self::DEFAULT_QRIS_PAYMENT_TTL_MINUTES));
    }

    public function binancePaymentTtlMinutes(): int
    {
        return max(1, (int) ($this->binance_payment_ttl_minutes ?: self::DEFAULT_BINANCE_PAYMENT_TTL_MINUTES));
    }

    public static function current(): ?self
    {
        return self::query()->where('is_active', true)->oldest('id')->first();
    }
}
