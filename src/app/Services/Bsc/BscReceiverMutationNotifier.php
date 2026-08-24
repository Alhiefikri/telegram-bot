<?php

namespace App\Services\Bsc;

use App\Services\Telegram\TelegramAdminAccess;
use App\Services\Telegram\TelegramBotClient;
use Illuminate\Support\Facades\Log;
use Throwable;

final class BscReceiverMutationNotifier
{
    public function __construct(
        private readonly TelegramAdminAccess $adminAccess,
        private readonly TelegramBotClient $bot,
    ) {}

    /** @param array<string, string> $payload */
    public function notify(array $payload): void
    {
        $chatId = $this->adminAccess->adminChatId();
        if ($chatId === null) {
            Log::warning('BSC receiver mutation has no owner notification target.', [
                'action' => $payload['action'] ?? null,
                'receiver_label' => $payload['label'] ?? null,
            ]);

            return;
        }

        try {
            $this->bot->sendMessage($chatId, implode("\n", [
                '⚠️ Perubahan BSC Receiving Address',
                '━━━━━━━━━━━━━━',
                'Aksi: '.($payload['action'] ?? 'unknown'),
                'Label: '.($payload['label'] ?? '-'),
                'Address: '.($payload['masked_address'] ?? '••••'),
                'Actor: '.($payload['actor'] ?? 'Owner'),
                'Waktu: '.($payload['at'] ?? now()->toIso8601String()),
            ]));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
