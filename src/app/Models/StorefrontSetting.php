<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'singleton_key',
    'product_placeholder_image_path',
    'telegram_welcome_banner_path',
    'telegram_welcome_banner_file_id',
    'telegram_welcome_banner_version',
])]
class StorefrontSetting extends Model
{
    public const SINGLETON_KEY = 'storefront';

    public static function current(): self
    {
        return self::query()
            ->where('singleton_key', self::SINGLETON_KEY)
            ->first() ?? new self(['singleton_key' => self::SINGLETON_KEY]);
    }
}
