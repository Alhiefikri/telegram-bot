<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Cache;

/**
 * Custom emoji in bot messages and in inline keyboard buttons require the owner
 * of the bot to hold a Telegram Premium subscription, and Telegram exposes no
 * API to query that capability. The first rejection therefore marks it
 * unavailable for a while, so every later send falls back to plain Unicode
 * icons instead of paying for another rejected request.
 */
class CustomEmojiCapability
{
    public const CACHE_KEY = 'telegram.custom-emoji-unavailable';

    private const RETRY_AFTER_MINUTES = 30;

    public function isAvailable(): bool
    {
        return ! Cache::has(self::CACHE_KEY);
    }

    public function unavailableReason(): ?string
    {
        $reason = Cache::get(self::CACHE_KEY);

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    public function markUnavailable(?string $reason): void
    {
        Cache::put(self::CACHE_KEY, (string) $reason, now()->addMinutes(self::RETRY_AFTER_MINUTES));
    }

    public function markAvailable(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Remove every custom emoji icon from a reply markup, keeping the rest of
     * the button definition untouched.
     *
     * @param  array<array-key, mixed>  $replyMarkup
     * @return array<array-key, mixed>
     */
    public function stripIcons(array $replyMarkup): array
    {
        foreach ($replyMarkup as $key => $value) {
            if ($key === 'icon_custom_emoji_id') {
                unset($replyMarkup[$key]);

                continue;
            }

            if (is_array($value)) {
                $replyMarkup[$key] = $this->stripIcons($value);
            }
        }

        return $replyMarkup;
    }
}
