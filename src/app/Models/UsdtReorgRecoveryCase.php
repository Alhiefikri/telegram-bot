<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class UsdtReorgRecoveryCase extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['authorized_by' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('USDT reorg history is immutable.'));
        static::deleting(fn () => throw new LogicException('USDT reorg history is immutable.'));
    }
}
