<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use Throwable;

class TelegramBroadcastService
{
    public function __construct(private TelegramBotClient $bot) {}

    /**
     * @return array{sent:int, failed:int, skipped:bool}
     */
    public function sendToActiveUsers(string $message, string $title = '📣 Info dari Admin', array $replyMarkup = []): array
    {
        if (blank(config('services.telegram.token'))) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => true];
        }

        $text = implode("\n", [
            $title,
            '━━━━━━━━━━━━━━',
            trim($message),
            '',
            'Butuh bantuan? Balas /support ya.',
        ]);

        $sent = 0;
        $failed = 0;
        $seen = [];

        Customer::query()
            ->whereNotNull('telegram_id')
            ->orderBy('id')
            ->select(['id', 'telegram_id'])
            ->chunkById(100, function ($customers) use (&$sent, &$failed, &$seen, $text, $replyMarkup): void {
                foreach ($customers as $customer) {
                    $chatId = (string) $customer->telegram_id;
                    if ($chatId === '' || isset($seen[$chatId])) {
                        continue;
                    }

                    $seen[$chatId] = true;

                    try {
                        $this->bot->sendMessage($chatId, $text, $replyMarkup);
                        $sent++;
                    } catch (Throwable $e) {
                        report($e);
                        $failed++;
                    }
                }
            });

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => false];
    }
}
