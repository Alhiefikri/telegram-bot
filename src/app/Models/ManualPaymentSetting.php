<?php

namespace App\Models;

use App\Support\PakasirCheckoutFlow;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['payment_mode', 'pakasir_checkout_flow', 'admin_contact_url', 'admin_username', 'admin_telegram_chat_id', 'instructions', 'is_active', 'binance_pay_enabled'])]
class ManualPaymentSetting extends Model
{
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

    public static function current(): ?self
    {
        return self::query()->where('is_active', true)->oldest('id')->first();
    }
}
