<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class UsdtReorgIncident extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'exposure_micro' => 'integer', 'available_exposure_micro' => 'integer', 'spent_exposure_micro' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('USDT reorg history is immutable.'));
        static::deleting(fn () => throw new LogicException('USDT reorg history is immutable.'));
    }
}
