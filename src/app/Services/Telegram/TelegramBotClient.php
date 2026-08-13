<?php

namespace App\Services\Telegram;

use App\Support\DogfoodTiming;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

class TelegramBotClient
{
    private readonly TelegramBotApiRequestFactory $requests;

    public function __construct(?TelegramBotApiRequestFactory $requests = null)
    {
        $this->requests = $requests ?? new TelegramBotApiRequestFactory;
    }

    public function getChat(int|string $chatId): array
    {
        return $this->timed('getChat', fn () => $this->requests->request()->post($this->requests->url('getChat'), ['chat_id' => $chatId])->throw()->json());
    }

    public function getChatMember(int|string $chatId, int|string $userId): array
    {
        return $this->timed('getChatMember', fn () => $this->requests->request()->post($this->requests->url('getChatMember'), ['chat_id' => $chatId, 'user_id' => $userId])->throw()->json());
    }

    public function sendMessage(int|string $chatId, string $text, array $replyMarkup = [], array $options = []): array
    {
        $payload = array_merge(['chat_id' => $chatId, 'text' => $text], $options);
        if ($replyMarkup !== []) {
            $payload['reply_markup'] = $replyMarkup;
        }

        return $this->timed('sendMessage', fn () => $this->requests->request()->post($this->requests->url('sendMessage'), $payload)->throw()->json());
    }

    public function sendPhoto(int|string $chatId, string $photoContents, string $filename, ?string $caption = null, array $replyMarkup = [], array $options = []): array
    {
        $request = $this->requests->request()->attach('photo', $photoContents, $filename);
        $payload = array_merge(['chat_id' => $chatId], $options);

        if ($caption !== null && $caption !== '') {
            $payload['caption'] = $caption;
        }
        if (isset($payload['caption_entities']) && is_array($payload['caption_entities'])) {
            $payload['caption_entities'] = json_encode($payload['caption_entities'], JSON_THROW_ON_ERROR);
        }

        if ($replyMarkup !== []) {
            $payload['reply_markup'] = json_encode($replyMarkup, JSON_THROW_ON_ERROR);
        }

        $response = $this->timed('sendPhoto', fn () => $request->post($this->requests->url('sendPhoto'), $payload)->throw()->json());
        if (($response['ok'] ?? false) !== true) {
            throw new \RuntimeException('Telegram Bot API sendPhoto response was not successful.');
        }

        return $response;
    }

    public function sendPhotoReference(int|string $chatId, string $photoFileId, ?string $caption = null, array $replyMarkup = [], array $options = []): void
    {
        $payload = array_merge(['chat_id' => $chatId, 'photo' => $photoFileId], $options);

        if ($caption !== null && $caption !== '') {
            $payload['caption'] = $caption;
        }

        if ($replyMarkup !== []) {
            $payload['reply_markup'] = $replyMarkup;
        }

        $this->timed('sendPhotoReference', fn () => $this->requests->request()->post($this->requests->url('sendPhoto'), $payload)->throw());
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        // The webhook response already stops Telegram's spinner. Keep a later
        // text/alert answer because handlers use it for user-visible feedback.
        if ($text === null && app(TelegramCallbackAckState::class)->isAcknowledged($callbackQueryId)) {
            return;
        }

        try {
            $this->timed('answerCallbackQuery', fn () => $this->requests->request()->post($this->requests->url('answerCallbackQuery'), array_filter([
                'callback_query_id' => $callbackQueryId,
                'text' => $text,
                'show_alert' => $text !== null,
            ], fn ($value) => $value !== null))->throw());
        } catch (RequestException $e) {
            Log::warning('telegram.answer_callback_query_failed', [
                'callback_query_correlation_id' => DogfoodTiming::correlation($callbackQueryId),
                'status' => $e->response?->status(),
                'description' => data_get($e->response?->json(), 'description'),
            ]);
        }
    }

    public function deleteMessage(int|string $chatId, int|string $messageId): void
    {
        $this->timed('deleteMessage', fn () => $this->requests->request()->post($this->requests->url('deleteMessage'), ['chat_id' => $chatId, 'message_id' => $messageId])->throw());
    }

    private function timed(string $operation, callable $callback): mixed
    {
        $startedAt = DogfoodTiming::now();
        $status = null;

        try {
            $result = $callback();
            $status = is_object($result) && method_exists($result, 'status') ? $result->status() : 200;

            return $result;
        } catch (ConnectionException $e) {
            throw $this->requests->transportException($operation, $e);
        } catch (RequestException $e) {
            $status = $e->response?->status();
            throw $e;
        } finally {
            DogfoodTiming::log('dogfood.outbound_http', [
                'provider' => 'telegram',
                'operation' => $operation,
                'status' => $status,
                'duration_ms' => DogfoodTiming::elapsedMs($startedAt),
            ]);
        }
    }
}
