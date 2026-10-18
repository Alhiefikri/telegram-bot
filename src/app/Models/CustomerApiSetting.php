<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['singleton_key', 'enabled', 'version', 'changed_at'])]
class CustomerApiSetting extends Model
{
    public const SINGLETON_KEY = 'customer_api';

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'version' => 'integer',
            'changed_at' => 'immutable_datetime',
        ];
    }

    public static function current(): ?self
    {
        return self::query()->where('singleton_key', self::SINGLETON_KEY)->first();
    }
}
