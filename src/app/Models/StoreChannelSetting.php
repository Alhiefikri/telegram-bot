<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['singleton_key', 'channel_username', 'channel_link', 'telegram_chat_id', 'sales_proof_enabled', 'last_tested_at', 'last_test_success', 'last_test_message_id', 'last_test_error'])]
class StoreChannelSetting extends Model
{
    public const SINGLETON_KEY = 'store_channel';

    protected function casts(): array
    {
        return [
            'sales_proof_enabled' => 'boolean',
            'last_tested_at' => 'datetime',
            'last_test_success' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return self::query()->where('singleton_key', self::SINGLETON_KEY)->first() ?? new self(['singleton_key' => self::SINGLETON_KEY]);
    }

    public function publicUrl(): ?string
    {
        $link = trim((string) $this->channel_link);
        if ($link !== '') {
            return $link;
        }

        $username = ltrim(trim((string) $this->channel_username), '@');

        return $username === '' ? null : 'https://t.me/'.$username;
    }
}
