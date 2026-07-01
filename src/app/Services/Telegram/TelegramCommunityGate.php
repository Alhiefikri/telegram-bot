<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use App\Models\StoreChannelSetting;
use App\Models\TelegramCommunityGatePrompt;
use Throwable;

class TelegramCommunityGate
{
    public const VERIFY_CALLBACK = 'community_gate:verify';

    public function __construct(private TelegramBotClient $bot, private TelegramMenuIntents $menu) {}

    public function allows(Customer $customer, string $chatId): bool
    {
        $setting = StoreChannelSetting::current();
        if (! $this->enabledWithValidSetup($setting)) {
            return true;
        }

        return $this->isMember((string) $setting->telegram_chat_id, (int) $customer->telegram_id);
    }

    public function ensureAllowedOrPrompt(Customer $customer, string $chatId): bool
    {
        if ($this->allows($customer, $chatId)) {
            return true;
        }

        $this->sendOrReusePrompt($customer, $chatId);

        return false;
    }

    public function verify(Customer $customer, string $chatId): bool
    {
        if (! $this->allows($customer, $chatId)) {
            $this->sendOrReusePrompt($customer, $chatId);

            return false;
        }

        $prompt = TelegramCommunityGatePrompt::query()
            ->where('customer_id', $customer->id)
            ->first();
        if ($prompt?->message_id) {
            try {
                $this->bot->deleteMessage($prompt->chat_id, $prompt->message_id);
            } catch (Throwable) {
                // Telegram may reject deletion (old/already deleted); gate verification still succeeds.
            }
        }
        TelegramCommunityGatePrompt::query()
            ->where('customer_id', $customer->id)
            ->delete();
        $this->menu->sendWelcome($customer, $chatId);
        $this->menu->sendMainMenu($customer, $chatId);

        return true;
    }

    private function enabledWithValidSetup(StoreChannelSetting $setting): bool
    {
        return $setting->community_gate_enabled
            && filled($setting->telegram_chat_id)
            && $setting->last_test_success === true;
    }

    private function isMember(string $channelChatId, int $telegramUserId): bool
    {
        try {
            $member = $this->bot->getChatMember($channelChatId, $telegramUserId);
        } catch (Throwable) {
            // Treat membership lookup failures as invalid Store Channel setup and fail open.
            return true;
        }

        $status = (string) ($member['result']['status'] ?? '');
        if (in_array($status, ['creator', 'administrator', 'member'], true)) {
            return true;
        }

        return $status === 'restricted' && ($member['result']['is_member'] ?? false) === true;
    }

    private function sendOrReusePrompt(Customer $customer, string $chatId): void
    {
        $existing = TelegramCommunityGatePrompt::query()->where('customer_id', $customer->id)->first();
        if ($existing) {
            return;
        }

        $setting = StoreChannelSetting::current();
        $url = $setting->channel_link ?: ($setting->channel_username ? 'https://t.me/'.ltrim($setting->channel_username, '@') : null);
        $keyboard = [[]];
        if ($url) {
            $keyboard[0][] = ['text' => '📢 Join Channel', 'url' => $url];
        }
        $keyboard[0][] = ['text' => '✅ Saya Sudah Join', 'callback_data' => self::VERIFY_CALLBACK];

        $message = $this->bot->sendMessage($chatId, "🔒 Join channel dulu ya\n━━━━━━━━━━━━━━\nBiar bisa lanjut belanja dan dapat info stok terbaru, kamu perlu gabung ke Store Channel dulu.\n\nKalau sudah join, tekan tombol “Saya sudah join”.", [
            'inline_keyboard' => $keyboard,
        ]);

        TelegramCommunityGatePrompt::create([
            'customer_id' => $customer->id,
            'chat_id' => $chatId,
            'message_id' => data_get($message, 'result.message_id'),
        ]);
    }
}
