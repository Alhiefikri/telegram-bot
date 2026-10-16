<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CustomerApiCredential extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $visible = ['created_at', 'revoked_at', 'last_used_at'];

    protected static function booted(): void
    {
        self::updating(function (self $credential): void {
            if ($credential->isDirty(['customer_id', 'selector', 'secret_digest', 'created_at'])
                || ($credential->getRawOriginal('revoked_at') !== null && $credential->isDirty('revoked_at'))) {
                throw new \LogicException('Customer API credential binding is immutable.');
            }
        });
        self::deleting(fn () => throw new \LogicException('Customer API credential history is immutable.'));
    }

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'last_used_at' => 'immutable_datetime', 'rotation_expires_at' => 'immutable_datetime'];
    }

    public function maskedIdentifier(): string
    {
        return 'capi_'.substr($this->selector, 0, 8).'…';
    }
}
