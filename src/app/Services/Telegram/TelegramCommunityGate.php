<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use App\Models\StoreChannelSetting;
use App\Models\TelegramCommunityGatePrompt;
use Throwable;

class TelegramCommunityGate
{
    public const VERIFY_CALLBACK = 'community_gate:verify';

    public function __construct(private TelegramBotClient $bot, private TelegramMenuIntents $menu, private TelegramLocale $locale) {}

    public function allows(Customer $customer): bool
    {
        $setting = StoreChannelSetting::current();
        if (! $setting->community_gate_enabled) {
            return true;
        }

        if (! $this->hasValidatedSetup($setting)) {
            return false;
        }

        return $this->isMember((string) $setting->telegram_chat_id, (int) $customer->telegram_id);
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
        if (! $this->allows($customer)) {
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

    private function hasValidatedSetup(StoreChannelSetting $setting): bool
    {
        return filled($setting->telegram_chat_id)
            && $setting->last_test_success === true;
    }

    private function isMember(string $channelChatId, int $telegramUserId): bool
    {
        try {
            $member = $this->bot->getChatMember($channelChatId, $telegramUserId);
        } catch (Throwable) {
            // Community gate is an access-control boundary: when Telegram cannot prove membership, fail closed.
            return false;
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
            $keyboard[0][] = ['text' => $this->locale->text($customer, '📢 Gabung Channel', '📢 Join Channel'), 'url' => $url];
        }
        $keyboard[0][] = ['text' => $this->locale->text($customer, '✅ Saya Sudah Join', '✅ I Have Joined'), 'callback_data' => self::VERIFY_CALLBACK];

        $message = $this->bot->sendMessage($chatId, $this->locale->text(
            $customer,
            '🔒 Join channel dulu ya' . "\n━━━━━━━━━━━━━━\n" . 'Biar bisa lanjut belanja dan dapat info stok terbaru, kamu perlu join channel Store Channel dulu.' . "\n\n" . 'Kalau sudah join tapi status kamu belum bisa dicek, tekan tombol “Saya sudah join” untuk coba lagi. Jika Telegram sedang gangguan, coba lagi nanti.',
            '🔒 Join the channel first' . "\n━━━━━━━━━━━━━━\n" . 'Before you can continue shopping, please join the Store Channel. Then press “I Have Joined” to verify.',
        ), [
            'inline_keyboard' => $keyboard,
        ]);

        TelegramCommunityGatePrompt::create([
            'customer_id' => $customer->id,
            'chat_id' => $chatId,
            'message_id' => data_get($message, 'result.message_id'),
        ]);
    }
}
