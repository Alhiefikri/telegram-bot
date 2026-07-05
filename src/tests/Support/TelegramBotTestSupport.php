<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

abstract class TelegramBotTestSupport extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.telegram', [
            'token' => 'bot-token',
            'webhook_secret' => 'secret',
            'api_base_url' => 'https://api.telegram.org',
            'default_payment_method' => 'BRIVA',
        ]);
        config()->set('services.pakasir', [
            'slug' => 'sedulur-digital',
            'api_key' => 'pakasir-key',
            'base_url' => 'https://app.pakasir.test',
            'redirect_url' => null,
            'qris_only' => false,
        ]);
        config()->set('queue.default', 'sync');
    }

    protected function fakeTelegramHttp(array $extraFakes = []): void
    {
        Http::preventStrayRequests();
        Http::fake(array_merge($extraFakes, [
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]));
    }

    protected function postTelegramWebhook(array $payload, string $secret = 'secret'): TestResponse
    {
        return $this->postJson('/api/webhook/telegram', $payload, [
            'X-Telegram-Bot-Api-Secret-Token' => $secret,
        ]);
    }

    protected function messageUpdate(int $id, string $text, array $from = [], int $chatId = 12345, string $chatType = 'private'): array
    {
        $fromId = $from['id'] ?? $chatId;

        return [
            'update_id' => $id,
            'message' => [
                'message_id' => $id,
                'chat' => ['id' => $chatId, 'type' => $chatType],
                'from' => array_merge(['id' => $fromId, 'first_name' => 'Tester'], $from),
                'text' => $text,
            ],
        ];
    }

    protected function callbackUpdate(int $id, string $callbackId, string $data, array $from = [], int $chatId = 12345, string $chatType = 'private'): array
    {
        $fromId = $from['id'] ?? $chatId;

        return [
            'update_id' => $id,
            'callback_query' => [
                'id' => $callbackId,
                'from' => array_merge(['id' => $fromId, 'first_name' => 'Tester'], $from),
                'message' => ['chat' => ['id' => $chatId, 'type' => $chatType]],
                'data' => $data,
            ],
        ];
    }

    protected function paymentPhotoUpdate(
        int $id,
        string $invoiceNumber,
        array $from = [],
        int $chatId = 12345,
        string $smallPhotoId = 'small-photo',
        string $largePhotoId = 'large-photo'
    ): array {
        $fromId = $from['id'] ?? $chatId;

        return [
            'update_id' => $id,
            'message' => [
                'message_id' => $id,
                'chat' => ['id' => $chatId],
                'from' => array_merge(['id' => $fromId, 'first_name' => 'Tester'], $from),
                'caption' => '/payment '.$invoiceNumber,
                'photo' => [
                    ['file_id' => $smallPhotoId, 'width' => 100, 'height' => 100],
                    ['file_id' => $largePhotoId, 'width' => 800, 'height' => 800],
                ],
            ],
        ];
    }
}
