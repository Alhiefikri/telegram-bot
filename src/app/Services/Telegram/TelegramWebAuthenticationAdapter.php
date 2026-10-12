<?php

namespace App\Services\Telegram;

use App\Services\Customer\CustomerTelegramAuthenticationService;
use Illuminate\Support\Arr;
use RuntimeException;
use Throwable;

final class TelegramWebAuthenticationAdapter
{
    private const INVALID = 'Permintaan akun web tidak dapat digunakan. Mulai lagi dari situs toko.';

    public function __construct(
        private readonly CustomerTelegramAuthenticationService $authentication,
        private readonly TelegramBotClient $bot,
    ) {}

    public function message(#[\SensitiveParameter] array $message): bool
    {
        if (! TelegramWebAuthenticationPayload::isMessage($message)) {
            return false;
        }
        if (! $this->privateActor($message['from'] ?? [], $message['chat'] ?? [])) {
            return true;
        }

        $chatId = (string) $message['chat']['id'];
        $actorId = (string) $message['from']['id'];
        if (preg_match('/\A\/start(?:@[A-Za-z0-9_]+)?\s+wa_([A-Za-z0-9_-]{43})\z/', trim($message['text']), $matches) !== 1) {
            $this->send($chatId, self::INVALID);

            return true;
        }

        $confirmation = $this->authentication->prepare('wa_'.$matches[1], $actorId, $chatId, 'private');
        if ($confirmation === null) {
            $this->send($chatId, self::INVALID);

            return true;
        }

        $this->send(
            $chatId,
            "Konfirmasi akun web\n\nLanjutkan hanya jika Anda baru saja meminta masuk atau menghubungkan Telegram dari situs toko di browser Anda sendiri. Tombol ini membuktikan kepemilikan akun Telegram untuk permintaan tersebut.\n\nJika bukan Anda yang memulai, abaikan pesan ini. Jangan teruskan tautan atau tombol ini kepada siapa pun. Permintaan berakhir dalam 5 menit. Setelah konfirmasi, kembali ke browser yang memulai permintaan untuk menyelesaikannya.",
            ['inline_keyboard' => [[[
                'text' => 'Konfirmasi permintaan akun web',
                'style' => 'primary',
                'callback_data' => $confirmation,
            ]]]],
        );

        return true;
    }

    public function callback(#[\SensitiveParameter] array $callback): bool
    {
        if (! TelegramWebAuthenticationPayload::isCallback($callback)) {
            return false;
        }
        $chat = Arr::get($callback, 'message.chat', []);
        if (! $this->privateActor($callback['from'] ?? [], $chat)) {
            return true;
        }

        $confirmed = preg_match('/\Awa_confirm:[A-Za-z0-9_-]{43}\z/', $callback['data']) === 1
            && $this->authentication->confirm($callback['data'], (string) $callback['from']['id'], (string) $chat['id'], 'private');
        try {
            $this->bot->answerCallbackQuery(
                (string) $callback['id'],
                $confirmed ? 'Telegram dikonfirmasi. Kembali ke browser yang memulai permintaan.' : self::INVALID,
            );
        } catch (Throwable) {
            throw new RuntimeException('Telegram web authentication response is unavailable.');
        }

        if ($confirmed) {
            $this->send(
                (string) $chat['id'],
                'Telegram dikonfirmasi. Kembali ke situs toko di browser yang memulai permintaan, lalu klik "Periksa konfirmasi Telegram" untuk menyelesaikannya.',
            );
        }

        return true;
    }

    private function privateActor(array $actor, array $chat): bool
    {
        $actorId = $actor['id'] ?? null;
        $chatId = $chat['id'] ?? null;

        return ($actor['is_bot'] ?? null) === false
            && ($chat['type'] ?? null) === 'private'
            && (is_int($actorId) || is_string($actorId))
            && (is_int($chatId) || is_string($chatId))
            && preg_match('/\A[1-9][0-9]{0,18}\z/', (string) $actorId) === 1
            && (string) $actorId === (string) $chatId;
    }

    private function send(string $chatId, #[\SensitiveParameter] string $text, #[\SensitiveParameter] array $replyMarkup = []): void
    {
        try {
            $response = $this->bot->sendMessage($chatId, $text, $replyMarkup);
            if (($response['ok'] ?? false) !== true) {
                throw new RuntimeException('Telegram web authentication response is unavailable.');
            }
        } catch (Throwable) {
            // Never retain the provider exception: it may echo the confirmation capability.
            throw new RuntimeException('Telegram web authentication response is unavailable.');
        }
    }
}
