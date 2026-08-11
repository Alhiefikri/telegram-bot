<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['singleton_key', 'is_active', 'public_note', 'version', 'activated_at'])]
class CheckoutMaintenanceSetting extends Model
{
    public const SINGLETON_KEY = 'checkout_maintenance';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'version' => 'integer',
            'activated_at' => 'datetime',
        ];
    }
}
