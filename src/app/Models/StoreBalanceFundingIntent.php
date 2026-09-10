<?php

namespace App\Models;

use App\Enums\StoreBalanceFundingIntentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'customer_id',
    'telegram_navigation_root_id',
    'chat_id',
    'canonical_message_id',
    'policy_version',
    'amount',
    'nonce',
    'status',
    'expires_at',
    'claimed_at',
])]
class StoreBalanceFundingIntent extends Model
{
    protected function casts(): array
    {
        return [
            'telegram_navigation_root_id' => 'integer',
            'canonical_message_id' => 'integer',
            'policy_version' => 'integer',
            'amount' => 'integer',
            'status' => StoreBalanceFundingIntentStatus::class,
            'expires_at' => 'datetime',
            'claimed_at' => 'datetime',
        ];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function navigationRoot()
    {
        return $this->belongsTo(TelegramNavigationRoot::class, 'telegram_navigation_root_id');
    }

    public function isPending(): bool
    {
        return $this->status === StoreBalanceFundingIntentStatus::Pending;
    }

    public function matches(
        string $chatId,
        int $navigationRootId,
        int $canonicalMessageId,
        int $policyVersion,
        int $amount,
    ): bool {
        return $this->chat_id === $chatId
            && $this->telegram_navigation_root_id === $navigationRootId
            && $this->canonical_message_id === $canonicalMessageId
            && $this->policy_version === $policyVersion
            && $this->amount === $amount;
    }
}
