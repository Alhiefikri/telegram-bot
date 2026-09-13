<?php

namespace App\Models;

use App\Enums\TelegramNavigationMediaKind;
use App\Enums\TelegramNavigationScreen;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'customer_id',
    'chat_id',
    'message_id',
    'media_kind',
    'screen',
    'locale',
    'banner_reference',
    'banner_version',
    'generation',
    'render_token',
    'render_started_at',
    'rendered_at',
])]
class TelegramNavigationRoot extends Model
{
    protected function casts(): array
    {
        return [
            'message_id' => 'integer',
            'media_kind' => TelegramNavigationMediaKind::class,
            'screen' => TelegramNavigationScreen::class,
            'generation' => 'integer',
            'banner_version' => 'integer',
            'render_started_at' => 'datetime',
            'rendered_at' => 'datetime',
        ];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
