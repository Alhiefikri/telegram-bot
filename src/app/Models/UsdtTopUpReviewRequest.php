<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Hidden(['session_binding', 'credential_binding', 'chain_evidence', 'refund_evidence'])]
final class UsdtTopUpReviewRequest extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['amount_micro' => 'integer', 'version' => 'integer', 'work_version' => 'integer',
            'checkpoint_version' => 'integer', 'chain_evidence' => 'array', 'refund_evidence' => 'array',
            'expires_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('USDT review preview is immutable.'));
        self::deleting(fn () => throw new LogicException('USDT review history is immutable.'));
    }
}
