<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class SupplierHealthEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];
}
