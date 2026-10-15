<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'provider', 'api_key', 'currency', 'currency_confirmed', 'exchange_rate_idr', 'is_active', 'low_balance_threshold_micros'])]
#[Hidden(['api_key', 'sync_token', 'webhook_secret', 'external_account_id', 'account_metadata'])]
class Supplier extends Model
{
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'account_metadata' => 'array',
            'purchase_paused_until' => 'immutable_datetime',
            'currency_confirmed' => 'boolean',
            'exchange_rate_idr' => 'decimal:6',
            'balance_micros' => 'integer',
            'reserved_balance_micros' => 'integer',
            'is_active' => 'boolean',
            'version' => 'integer',
            'synced_at' => 'immutable_datetime',
            'sync_expires_at' => 'immutable_datetime',
            'balance_observed_at' => 'immutable_datetime',
            'sync_attempted_at' => 'immutable_datetime',
            'sync_next_attempt_at' => 'immutable_datetime',
            'sync_failures' => 'integer',
            'sync_lease_expires_at' => 'immutable_datetime',
            'low_balance_threshold_micros' => 'integer',
            'low_balance_alerted_at' => 'immutable_datetime',
        ];
    }

    public function catalogItems(): HasMany
    {
        return $this->hasMany(SupplierCatalogItem::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(SupplierPurchase::class);
    }
}
