<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
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
            'webhook_fast_callback_ack' => false,
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
        $wildcardFake = $extraFakes['https://api.telegram.org/*'] ?? Http::response(['ok' => true]);
        $defaults = [
            'https://api.telegram.org/*/sendPhoto' => Http::response(['ok' => true, 'result' => ['message_id' => 123456, 'photo' => [['file_id' => 'fake-photo-file-id']]]]),
        ];
        if (! array_key_exists('https://api.telegram.org/*', $extraFakes)) {
            $defaults['https://api.telegram.org/*/sendMessage'] = Http::response(['ok' => true, 'result' => ['message_id' => 123457]]);
        }
        unset($extraFakes['https://api.telegram.org/*']);

        Http::fake(array_merge($defaults, $extraFakes, [
            'https://api.telegram.org/*' => $wildcardFake,
        ]));
    }

    protected function postTelegramWebhook(array $payload, string $secret = 'secret'): TestResponse
    {
        return $this->postJson('/api/webhook/telegram', $payload, [
            'X-Telegram-Bot-Api-Secret-Token' => $secret,
        ]);
    }

    protected function telegramRequestField(Request $request, string $field): mixed
    {
        $data = $request->data();
        if (array_key_exists($field, $data)) {
            return $data[$field];
        }

        foreach ($data as $part) {
            if (is_array($part) && ($part['name'] ?? null) === $field) {
                return $part['contents'] ?? null;
            }
        }

        return null;
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

    protected function callbackUpdate(int $id, string $callbackId, string $data, array $from = [], int $chatId = 12345, string $chatType = 'private', ?int $messageId = null): array
    {
        $fromId = $from['id'] ?? $chatId;

        return [
            'update_id' => $id,
            'callback_query' => [
                'id' => $callbackId,
                'from' => array_merge(['id' => $fromId, 'first_name' => 'Tester'], $from),
                'message' => array_filter([
                    'message_id' => $messageId,
                    'chat' => ['id' => $chatId, 'type' => $chatType],
                ], fn ($value) => $value !== null),
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
