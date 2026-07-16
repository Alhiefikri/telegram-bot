<?php

namespace App\Models;

use Database\Factories\TelegramUpdateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['update_id', 'customer_id', 'payload', 'processed_at', 'processing_started_at', 'created_at'])]
class TelegramUpdate extends Model
{
    /** @use HasFactory<TelegramUpdateFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['update_id' => 'integer', 'payload' => 'array', 'processed_at' => 'datetime', 'processing_started_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function hasActiveClaim(): bool
    {
        return $this->processing_started_at !== null
            && $this->processing_started_at->gt(now()->subSeconds($this->claimLeaseSeconds()));
    }

    private function claimLeaseSeconds(): int
    {
        return max(60, (int) config('services.telegram.claim_lease_seconds', 600));
    }
}
