<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['singleton_key', 'enabled'])]
final class CustomerAuthSetting extends Model
{
    public const SINGLETON_KEY = 'customer_auth';

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
