<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['customer_id', 'chat_id', 'message_id'])]
class TelegramCommunityGatePrompt extends Model
{
    protected function casts(): array
    {
        return [
            'message_id' => 'integer',
        ];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
