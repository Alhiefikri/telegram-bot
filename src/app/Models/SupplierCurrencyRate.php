<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['currency', 'exchange_rate_idr', 'version'])]
class SupplierCurrencyRate extends Model
{
    protected $primaryKey = 'currency';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'exchange_rate_idr' => 'decimal:6',
            'version' => 'integer',
        ];
    }
}
