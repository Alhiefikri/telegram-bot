<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class UsdtTransferRecoveryRequest extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_micro' => 'integer', 'evidence' => 'array', 'expires_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Recovery requests are immutable.'));
        self::deleting(fn () => throw new LogicException('Recovery requests cannot be deleted.'));
    }
}
