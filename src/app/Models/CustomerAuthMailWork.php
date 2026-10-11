<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CustomerAuthMailWork extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['payload', 'auth_token', 'login_link'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function authToken()
    {
        return $this->belongsTo(CustomerAuthToken::class, 'auth_token_id');
    }

    public function loginLink()
    {
        return $this->belongsTo(CustomerLoginLink::class, 'login_link_id');
    }
}
