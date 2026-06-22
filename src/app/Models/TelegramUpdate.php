<?php

namespace App\Models;

use Database\Factories\TelegramUpdateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['update_id', 'customer_id', 'payload', 'processed_at', 'created_at'])]
class TelegramUpdate extends Model
{
    /** @use HasFactory<TelegramUpdateFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['update_id' => 'integer', 'payload' => 'array', 'processed_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
