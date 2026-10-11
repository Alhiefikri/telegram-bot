<?php

namespace App\Models;

use App\Enums\CustomerIdentityProvider;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class CustomerIdentity extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['provider_subject', 'provider_email', 'customer'];

    protected function casts(): array
    {
        return ['provider' => CustomerIdentityProvider::class, 'last_used_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $identity): void {
            if ($identity->isDirty(['customer_id', 'provider', 'provider_subject'])) {
                throw new LogicException('Customer identity ownership is immutable.');
            }
        });
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
