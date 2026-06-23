<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['dana_account_name', 'dana_account_number', 'dana_qr_image_path', 'dana_qr_image_url', 'admin_contact_url', 'admin_username', 'admin_telegram_chat_id', 'instructions', 'is_active'])]
class ManualPaymentSetting extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public static function current(): ?self
    {
        return self::query()->where('is_active', true)->oldest('id')->first();
    }
}
