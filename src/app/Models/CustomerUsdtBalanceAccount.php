<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['customer_id'])]
class CustomerUsdtBalanceAccount extends Model
{
    protected function casts(): array
    {
        return [
            'balance_micro' => 'integer',
            'version' => 'integer',
            'last_entry_id' => 'integer',
        ];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function entries()
    {
        return $this->hasMany(CustomerUsdtBalanceEntry::class, 'customer_usdt_balance_account_id');
    }
}
