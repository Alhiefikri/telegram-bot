<?php

namespace App\Services\Telegram;

use App\Support\DogfoodTiming;

class FakeTelegramBotClient extends TelegramBotClient
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function getChat(int|string $chatId): array
    {
        $this->record('getChat');

        return ['ok' => true, 'result' => ['id' => $chatId, 'type' => 'channel', 'title' => 'Dogfood Channel']];
    }

    public function getChatMember(int|string $chatId, int|string $userId): array
    {
        $this->record('getChatMember');

        return ['ok' => true, 'result' => ['status' => 'member', 'user' => ['id' => $userId, 'is_bot' => false]]];
    }

    public function sendMessage(int|string $chatId, string $text, array $replyMarkup = [], array $options = []): array
    {
        $this->record('sendMessage');

        return [
            'ok' => true,
            'result' => [
                'message_id' => random_int(100000, 999999),
                'chat' => ['id' => $chatId],
                'text' => $text,
                'reply_markup' => $replyMarkup,
                ...$options,
            ],
        ];
    }

    public function editMessageText(int|string $chatId, int $messageId, string $text, array $replyMarkup = [], array $options = []): array
    {
        $this->record('editMessageText');

        return [
            'ok' => true,
            'result' => [
                'message_id' => $messageId,
                'chat' => ['id' => $chatId],
                'text' => $text,
                'reply_markup' => $replyMarkup,
                ...$options,
            ],
        ];
    }

    public function editMessageCaption(int|string $chatId, int $messageId, string $caption, array $replyMarkup = [], array $options = []): array
    {
        $this->record('editMessageCaption');

        return [
            'ok' => true,
            'result' => [
                'message_id' => $messageId,
                'chat' => ['id' => $chatId],
                'caption' => $caption,
                'reply_markup' => $replyMarkup,
                ...$options,
            ],
        ];
    }

    public function editMessageReplyMarkup(
        int|string $chatId,
        int $messageId,
        array $replyMarkup = ['inline_keyboard' => []],
        array $options = [],
    ): array {
        $this->record('editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => $replyMarkup,
            'options' => $options,
        ]);

        return [
            'ok' => true,
            'result' => [
                'message_id' => $messageId,
                'chat' => ['id' => $chatId],
                'reply_markup' => $replyMarkup,
                ...$options,
            ],
        ];
    }

    public function sendPhoto(int|string $chatId, string $photoContents, string $filename, ?string $caption = null, array $replyMarkup = [], array $options = []): array
    {
        $this->record('sendPhoto');

        return [
            'ok' => true,
            'result' => [
                'message_id' => random_int(100000, 999999),
                'chat' => ['id' => $chatId],
                'photo' => [['file_id' => 'fake-photo-file-id']],
                'caption' => $caption,
                'reply_markup' => $replyMarkup,
                ...$options,
            ],
        ];
    }

    public function sendDocument(
        int|string $chatId,
        string $documentContents,
        string $filename,
        ?string $caption = null,
        array $replyMarkup = [],
        array $options = [],
    ): array {
        $filename = $this->sanitizeDocumentFilename($filename);
        $response = [
            'ok' => true,
            'result' => [
                ...$options,
                'message_id' => random_int(100000, 999999),
                'chat' => ['id' => $chatId],
                'document' => ['file_id' => 'fake-document-file-id', 'file_name' => $filename],
                'caption' => $caption,
                'reply_markup' => $replyMarkup,
            ],
        ];

        $this->record('sendDocument', [
            'chat_id' => $chatId,
            'filename' => $filename,
            'caption' => $caption,
            'reply_markup' => $replyMarkup,
            'options' => $options,
            'response' => $response,
        ]);

        return $response;
    }

    public function sendPhotoReference(int|string $chatId, string $photoFileId, ?string $caption = null, array $replyMarkup = [], array $options = []): array
    {
        $this->record('sendPhotoReference');

        return [
            'ok' => true,
            'result' => [
                'message_id' => random_int(100000, 999999),
                'chat' => ['id' => $chatId],
                'caption' => $caption,
                'photo' => $photoFileId,
                'reply_markup' => $replyMarkup,
                ...$options,
            ],
        ];
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        if ($text === null && app(TelegramCallbackAckState::class)->isAcknowledged($callbackQueryId)) {
            return;
        }

        $this->record('answerCallbackQuery');
    }

    public function deleteMessage(int|string $chatId, int|string $messageId): void
    {
        $this->record('deleteMessage');
    }

    private function record(string $operation, array $metadata = []): void
    {
        $callIndex = count($this->calls);
        $this->calls[] = ['method' => $operation, ...$metadata];
        $startedAt = DogfoodTiming::now();
        usleep((int) config('services.dogfood_timing.fake_telegram_latency_us', 1000));
        $this->calls[$callIndex]['duration_ms'] = DogfoodTiming::elapsedMs($startedAt);

        DogfoodTiming::log('dogfood.outbound_http', [
            'provider' => 'telegram',
            'operation' => $operation,
            'status' => 200,
            'fake' => true,
            'duration_ms' => DogfoodTiming::elapsedMs($startedAt),
        ]);
    }
}
