<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class CustomerGoogleAuthClaim extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['state_hash', 'session_binding_hash', 'configuration_fingerprint', 'customer_id', 'auth_version', 'authenticated_at'];

    protected function casts(): array
    {
        return [
            'auth_version' => 'integer',
            'authenticated_at' => 'datetime',
            'consumed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (self $claim): void {
            if ($claim->isDirty(['operation', 'customer_id', 'auth_version', 'authenticated_at', 'state_hash', 'session_binding_hash', 'configuration_fingerprint', 'expires_at', 'created_at'])
                || ($claim->getRawOriginal('consumed_at') !== null && $claim->isDirty('consumed_at'))) {
                throw new LogicException('Google claim binding and consumption are immutable.');
            }
        });
    }
}
