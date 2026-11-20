<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use App\Models\Order;

final class TelegramManualFulfillmentCommand
{
    public function __construct(
        private readonly TelegramAdminAccess $access,
        private readonly TelegramBotClient $bot,
        private readonly TelegramLocalFulfillmentLink $fulfillmentLink,
    ) {}

    /** Keep only the command and a single invoice token, never trailing account data. */
    public static function normalize(#[\SensitiveParameter] string $text): ?string
    {
        $text = trim($text);
        if (preg_match('/\A\/fulfill(?:@[A-Za-z0-9_]+)?(?=\s|\z)/i', $text) !== 1) {
            return null;
        }

        return preg_match('/\A\/fulfill(?:@[A-Za-z0-9_]+)?[ \t]+(INV-[A-Z0-9-]{1,76})\z/i', $text, $match) === 1
            ? '/fulfill '.strtoupper($match[1])
            : '/fulfill';
    }

    public function handle(Customer $actor, string $chatId, string $chatType, string $command): void
    {
        if ($chatType !== 'private' || $chatId !== (string) $actor->telegram_id || ! $this->access->isAdmin($actor, $chatId)) {
            return;
        }

        if ($command === '/fulfill') {
            $this->bot->sendMessage($chatId, "Gunakan /fulfill INV-...\nJangan kirim password atau data akun lewat chat.");

            return;
        }

        $invoice = substr($command, strlen('/fulfill '));
        $order = Order::query()->where('invoice_number', $invoice)->first();
        $review = $order === null ? null : $this->fulfillmentLink->reviewFor($order);

        if ($review === null) {
            $this->bot->sendMessage($chatId, 'Invoice tidak ditemukan atau tidak sedang menunggu pengisian akun lokal.');

            return;
        }

        $url = $this->fulfillmentLink->url($review);
        if ($url === null) {
            $this->bot->sendMessage($chatId, 'URL HTTPS formulir fulfillment belum tersedia.');

            return;
        }
        $this->bot->sendMessage($chatId, "Invoice {$invoice} menunggu pengisian akun.\nCek invoice, pembeli, dan jumlah sebelum menyimpan. Jangan kirim data akun lewat chat.", [
            'inline_keyboard' => [[['text' => '🔐 Isi akun pesanan', 'url' => $url, 'style' => 'primary']]],
        ]);
    }
}
