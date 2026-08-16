<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use App\Models\StoreChannelSetting;
use App\Models\TelegramCommunityGatePrompt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramCommunityGate
{
    public const VERIFY_CALLBACK = 'community_gate:verify';

    public function __construct(private TelegramBotClient $bot, private TelegramMenuIntents $menu, private TelegramLocale $locale) {}

    public function allows(Customer $customer, bool $forceRefresh = false): bool
    {
        $setting = StoreChannelSetting::current();
        if (! $setting->community_gate_enabled) {
            return true;
        }

        if (! $this->hasValidatedSetup($setting)) {
            return false;
        }

        return $this->isMember((string) $setting->telegram_chat_id, (int) $customer->telegram_id, $forceRefresh);
    }

    public function ensureAllowedOrPrompt(Customer $customer, string $chatId): bool
    {
        if ($this->allows($customer)) {
            return true;
        }

        $this->sendOrReusePrompt($customer, $chatId);

        return false;
    }

    public function verify(Customer $customer, string $chatId): bool
    {
        if (! $this->allows($customer, forceRefresh: true)) {
            $this->sendOrReusePrompt($customer, $chatId);

            return false;
        }

        $this->clearPrompt($customer);
        $this->menu->sendWelcome($customer, $chatId);
        $this->menu->sendMainMenu($customer, $chatId);

        return true;
    }

    public function clearPrompt(Customer $customer): void
    {
        $prompt = TelegramCommunityGatePrompt::query()
            ->where('customer_id', $customer->id)
            ->first();
        if ($prompt?->message_id) {
            try {
                $this->bot->deleteMessage($prompt->chat_id, $prompt->message_id);
            } catch (Throwable) {
                // Prompt cleanup is best-effort; the stored prompt must still be removed.
            }
        }

        TelegramCommunityGatePrompt::query()
            ->where('customer_id', $customer->id)
            ->delete();
    }

    private function hasValidatedSetup(StoreChannelSetting $setting): bool
    {
        return filled($setting->telegram_chat_id)
            && $setting->last_test_success === true;
    }

    private function isMember(string $channelChatId, int $telegramUserId, bool $forceRefresh = false): bool
    {
        $cacheSeconds = max(0, (int) config('services.telegram.community_gate_cache_seconds', 15));
        $cacheKey = 'telegram:community-gate:member:'.sha1($channelChatId.'|'.$telegramUserId);

        // Cache only a positive result. A newly joined user can retry immediately,
        // while the explicit "I have joined" callback always bypasses this lease.
        if (! $forceRefresh && $cacheSeconds > 0 && Cache::get($cacheKey) === true) {
            return true;
        }

        try {
            $member = $this->bot->getChatMember($channelChatId, $telegramUserId);
        } catch (Throwable $e) {
            Cache::forget($cacheKey);
            Log::warning('telegram.community_gate_membership_check_failed', [
                'exception' => $e::class,
                'error' => TelegramBotApiRedactor::redactText($e->getMessage()),
            ]);

            // Community gate is an access-control boundary: when Telegram cannot prove membership, fail closed.
            return false;
        }

        $status = (string) ($member['result']['status'] ?? '');
        $allowed = in_array($status, ['creator', 'administrator', 'member'], true)
            || ($status === 'restricted' && ($member['result']['is_member'] ?? false) === true);

        if ($allowed && $cacheSeconds > 0) {
            Cache::put($cacheKey, true, now()->addSeconds($cacheSeconds));
        } else {
            Cache::forget($cacheKey);
        }

        return $allowed;
    }

    private function sendOrReusePrompt(Customer $customer, string $chatId): void
    {
        Cache::lock('telegram:community-gate-prompt:'.$customer->id, 30)->get(function () use ($customer, $chatId) {
            $existing = TelegramCommunityGatePrompt::query()->where('customer_id', $customer->id)->first();
            if ($existing?->message_id) {
                try {
                    $this->bot->deleteMessage($existing->chat_id, $existing->message_id);
                } catch (Throwable) {
                    // The old prompt may be missing or too old to delete; a fresh prompt must still be sent.
                }
            }

            $setting = StoreChannelSetting::current();
            $url = $setting->channel_link ?: ($setting->channel_username ? 'https://t.me/'.ltrim($setting->channel_username, '@') : null);
            $keyboard = [[]];
            if ($url) {
                $keyboard[0][] = ['text' => $this->locale->translate($customer, 'community_gate.join_button'), 'url' => $url];
            }
            $keyboard[0][] = ['text' => $this->locale->translate($customer, 'community_gate.verify_button'), 'callback_data' => self::VERIFY_CALLBACK];

            $message = $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'community_gate.message'), [
                'inline_keyboard' => $keyboard,
            ]);

            TelegramCommunityGatePrompt::query()->updateOrCreate(
                ['customer_id' => $customer->id],
                [
                    'chat_id' => $chatId,
                    'message_id' => data_get($message, 'result.message_id'),
                ],
            );
        });
    }
}
