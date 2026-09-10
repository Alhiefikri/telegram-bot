<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The atomically maintained Store Balance projection for one Customer.
 * The balance column is deliberately not mass assignable: only
 * StoreBalanceLedger may move money, always with an immutable entry.
 */
#[Fillable(['customer_id'])]
class CustomerBalanceAccount extends Model
{
    protected function casts(): array
    {
        return ['balance' => 'integer'];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function entries()
    {
        return $this->hasMany(StoreBalanceEntry::class);
    }
}
