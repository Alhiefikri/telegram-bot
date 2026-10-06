<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Hidden(['credential_binding', 'session_binding'])]
class UsdtReorgRecoveryRequest extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('USDT reorg history is immutable.'));
        static::deleting(fn () => throw new LogicException('USDT reorg history is immutable.'));
    }
}
