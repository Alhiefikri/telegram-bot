<?php

namespace App\Services;

use App\Models\StoreChannelSetting;
use App\Services\Telegram\TelegramBotClient;
use Illuminate\Http\Client\RequestException;

class StoreChannelSetupVerifier
{
    public function __construct(private readonly TelegramBotClient $telegram) {}

    /** @return array{success: bool, chat_id: ?string, message_id: ?string, error: ?string} */
    public function test(StoreChannelSetting $setting): array
    {
        $target = $this->target($setting);
        if ($target === '') {
            return ['success' => false, 'chat_id' => null, 'message_id' => null, 'error' => 'Channel belum dikonfigurasi. Isi username/link channel publik.'];
        }

        try {
            $chat = $this->telegram->getChat($target);
            if (data_get($chat, 'ok') !== true || ! data_get($chat, 'result.id')) {
                return ['success' => false, 'chat_id' => null, 'message_id' => null, 'error' => 'Bot cannot access channel: Telegram tidak mengembalikan chat ID.'];
            }

            $chatId = (string) data_get($chat, 'result.id', $target);
            $message = $this->telegram->sendMessage($chatId, "✅ Test channel LHIE STORE berhasil.\n\nChannel sudah bisa menerima pesan test. Fitur belum otomatis diaktifkan.");
            if (data_get($message, 'ok') !== true || ! data_get($message, 'result.message_id')) {
                return ['success' => false, 'chat_id' => $chatId, 'message_id' => null, 'error' => 'Bot cannot post to channel: Telegram tidak mengembalikan message ID.'];
            }

            return [
                'success' => true,
                'chat_id' => $chatId,
                'message_id' => (string) data_get($message, 'result.message_id', ''),
                'error' => null,
            ];
        } catch (RequestException $e) {
            $description = (string) data_get($e->response?->json(), 'description', $e->getMessage());
            $lowerDescription = strtolower($description);
            $error = str_contains($lowerDescription, 'not enough rights') || str_contains($lowerDescription, 'can\'t post')
                ? 'Bot cannot post to channel: '.$description
                : (str_contains($lowerDescription, 'forbidden') || str_contains($lowerDescription, 'not a member')
                    ? 'Bot cannot access channel: '.$description
                    : 'Test failed: '.$description);

            return ['success' => false, 'chat_id' => null, 'message_id' => null, 'error' => $error];
        } catch (\Throwable $e) {
            return ['success' => false, 'chat_id' => null, 'message_id' => null, 'error' => 'Test failed: '.$e->getMessage()];
        }
    }

    private function target(StoreChannelSetting $setting): string
    {
        $value = trim((string) ($setting->channel_username ?: $setting->channel_link));
        if ($value === '') {
            return '';
        }
        if (preg_match('~(?:https?://)?t\.me/([^/?#]+)~i', $value, $matches)) {
            $value = $matches[1];
        }

        return str_starts_with($value, '@') || str_starts_with($value, '-') ? $value : '@'.$value;
    }
}
