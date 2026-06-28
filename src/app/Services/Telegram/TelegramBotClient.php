<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;

class TelegramBotClient
{
    public function sendMessage(int|string $chatId, string $text, array $replyMarkup = []): void
    {
        $payload = ['chat_id' => $chatId, 'text' => $text];
        if ($replyMarkup !== []) {
            $payload['reply_markup'] = $replyMarkup;
        }

        Http::post($this->url('sendMessage'), $payload)->throw();
    }

    public function sendPhoto(int|string $chatId, string $photoContents, string $filename, ?string $caption = null, array $replyMarkup = []): void
    {
        $request = Http::attach('photo', $photoContents, $filename);
        $payload = ['chat_id' => $chatId];

        if ($caption !== null && $caption !== '') {
            $payload['caption'] = $caption;
        }

        if ($replyMarkup !== []) {
            $payload['reply_markup'] = json_encode($replyMarkup, JSON_THROW_ON_ERROR);
        }

        $request->post($this->url('sendPhoto'), $payload)->throw();
    }

    public function sendPhotoReference(int|string $chatId, string $photoFileId, ?string $caption = null, array $replyMarkup = []): void
    {
        $payload = ['chat_id' => $chatId, 'photo' => $photoFileId];

        if ($caption !== null && $caption !== '') {
            $payload['caption'] = $caption;
        }

        if ($replyMarkup !== []) {
            $payload['reply_markup'] = $replyMarkup;
        }

        Http::post($this->url('sendPhoto'), $payload)->throw();
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        Http::post($this->url('answerCallbackQuery'), array_filter([
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
            'show_alert' => $text !== null,
        ], fn ($value) => $value !== null))->throw();
    }

    private function url(string $method): string
    {
        return rtrim((string) config('services.telegram.api_base_url', 'https://api.telegram.org'), '/')
            .'/bot'.config('services.telegram.token').'/'.$method;
    }
}
