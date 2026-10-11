<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CustomerTelegramAuthClaim extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['customer_id', 'auth_version', 'authenticated_at', 'session_binding_hash', 'capability_hash', 'actor_id', 'proof_customer_id', 'proof_auth_version', 'confirmation_hash'];

    protected function casts(): array
    {
        return [
            'auth_version' => 'integer',
            'proof_auth_version' => 'integer',
            'authenticated_at' => 'datetime',
            'prepared_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'consumed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
