<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerLoginLink extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }
}
