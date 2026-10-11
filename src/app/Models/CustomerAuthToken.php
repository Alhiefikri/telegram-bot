<?php

namespace App\Models;

use App\Enums\CustomerAuthTokenPurpose;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class CustomerAuthToken extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['token_hash', 'email', 'challenge_hash', 'session_binding_hash', 'proposed_password_hash', 'auth_version', 'enrollment_version', 'customer'];

    protected function casts(): array
    {
        return [
            'purpose' => CustomerAuthTokenPurpose::class,
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'attempts' => 'integer',
            'auth_version' => 'integer',
            'enrollment_version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $token): void {
            if ($token->isDirty(['customer_id', 'email', 'purpose', 'token_hash', 'challenge_hash', 'session_binding_hash', 'proposed_password_hash', 'auth_version', 'enrollment_version', 'expires_at', 'created_at'])
                || $token->attempts < $token->getOriginal('attempts')
                || ($token->getOriginal('consumed_at') !== null && $token->isDirty('consumed_at'))) {
                throw new LogicException('Customer auth token binding or terminal state is immutable.');
            }
        });
    }

    public function isActiveFor(CustomerAuthTokenPurpose $purpose): bool
    {
        return $this->purpose === $purpose
            && $this->consumed_at === null
            && $this->expires_at->isFuture()
            && ($purpose !== CustomerAuthTokenPurpose::EmailOtp || $this->attempts < $purpose->maximumAttempts());
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
