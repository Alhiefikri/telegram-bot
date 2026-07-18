<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

#[Fillable(['telegram_id', 'first_name', 'username', 'phone_number', 'locale', 'email', 'email_verified_at'])]
class Customer extends Model implements Authenticatable
{
    /** @use HasFactory<CustomerFactory> */
    use AuthenticatableTrait, HasFactory, Notifiable;

    protected $hidden = ['telegram_id', 'username', 'phone_number', 'email', 'email_verified_at'];

    protected function casts(): array
    {
        return ['telegram_id' => 'integer', 'email_verified_at' => 'datetime'];
    }

    public function routeNotificationForMail(): ?string
    {
        return $this->email;
    }

    public function carts()
    {
        return $this->hasMany(Cart::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function telegramUpdates()
    {
        return $this->hasMany(TelegramUpdate::class);
    }
}
