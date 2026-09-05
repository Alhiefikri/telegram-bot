<?php

namespace Tests\Unit\Services\Telegram;

use App\Exceptions\TelegramApiException;
use App\Exceptions\TelegramTransportException;
use App\Services\Telegram\FakeTelegramBotClient;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackAckState;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Throwable;

class TelegramBotClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.telegram', [
            'token' => 'issue-114-token',
            'webhook_secret' => 'issue-114-webhook-secret',
            'api_base_url' => 'https://api.telegram.org',
            'http_timeout_seconds' => 7.25,
            'http_connect_timeout_seconds' => 2.5,
        ]);
    }

    public function test_runtime_methods_apply_configured_http_timeouts_to_json_and_multipart_requests(): void
    {
        $requests = [];
        $optionsByOperation = [];

        Http::fake(function ($request, array $options) use (&$requests, &$optionsByOperation) {
            $operation = $this->operationForRequest($request);

            $requests[$operation] = $request;
            $optionsByOperation[$operation] = $options;

            return Http::response(['ok' => true, 'result' => ['id' => 12345, 'message_id' => 12345]], 200);
        });

        $client = app(TelegramBotClient::class);

        $client->getChat(12345);
        $client->getChatMember(12345, 67890);
        $client->sendMessage(12345, 'Timeout regression coverage');
        $client->editMessageText(12345, 777, 'Edited message', ['inline_keyboard' => [[['text' => 'Next', 'callback_data' => 'next']]]]);
        $client->sendPhoto(12345, 'binary-photo', 'receipt.png', 'Receipt');
        $client->sendPhotoReference(12345, 'telegram-file-id', 'Receipt reference');
        $client->sendDocument(12345, 'binary-document', 'download.txt');
        $client->answerCallbackQuery('callback-query-id', 'Done');
        $client->deleteMessage(12345, 777);

        $this->assertSame([
            'getChat',
            'getChatMember',
            'sendMessage',
            'editMessageText',
            'sendPhoto',
            'sendPhotoReference',
            'sendDocument',
            'answerCallbackQuery',
            'deleteMessage',
        ], array_keys($optionsByOperation));
        Http::assertSentCount(9);

        foreach ($optionsByOperation as $operation => $options) {
            $this->assertArrayHasKey('timeout', $options, "{$operation} did not configure an HTTP timeout.");
            $this->assertSame(7.25, $options['timeout'], "{$operation} used the wrong HTTP timeout.");
            $this->assertArrayHasKey('connect_timeout', $options, "{$operation} did not configure an HTTP connect timeout.");
            $this->assertSame(2.5, $options['connect_timeout'], "{$operation} used the wrong HTTP connect timeout.");
        }

        $this->assertTrue($requests['sendMessage']->isJson(), 'sendMessage should use the JSON post request path.');
        $this->assertSame('Timeout regression coverage', $requests['sendMessage']['text']);
        $this->assertTrue($requests['editMessageText']->isJson(), 'editMessageText should use the JSON post request path.');
        $this->assertSame(777, $requests['editMessageText']['message_id']);
        $this->assertSame('Edited message', $requests['editMessageText']['text']);
        $this->assertTrue($requests['sendPhoto']->isMultipart(), 'sendPhoto should use the multipart attach request path.');
        $this->assertTrue($requests['sendPhoto']->hasFile('photo', 'binary-photo', 'receipt.png'));
        $this->assertTrue($requests['sendDocument']->isMultipart(), 'sendDocument should use the multipart attach request path.');
        $this->assertTrue($requests['sendDocument']->hasFile('document', 'binary-document', 'download.txt'));
    }

    public function test_not_modified_edit_is_idempotent_without_a_duplicate_send(): void
    {
        Http::fake([
            'https://api.telegram.org/*/editMessageText' => Http::response([
                'ok' => false,
                'description' => 'Bad Request: message is not modified',
            ], 400),
        ]);

        app(TelegramBotClient::class)->editMessageTextOrSend(
            12345,
            'Already rendered',
            ['inline_keyboard' => []],
            777,
        );

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/editMessageText'));
    }

    public function test_definite_edit_failure_falls_back_to_one_new_message(): void
    {
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/editMessageText')) {
                return Http::response([
                    'ok' => false,
                    'description' => 'Bad Request: message to edit not found',
                ], 400);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 778]], 200);
        });

        app(TelegramBotClient::class)->editMessageTextOrSend(12345, 'Fallback text', [], 777);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request['text'] === 'Fallback text');
    }

    public function test_edit_transport_ambiguity_never_falls_back_to_send(): void
    {
        Http::fake([
            'https://api.telegram.org/*/editMessageText' => Http::failedConnection('timed out'),
        ]);

        try {
            app(TelegramBotClient::class)->editMessageTextOrSend(12345, 'Ambiguous text', [], 777);
            $this->fail('Expected the edit transport failure to remain ambiguous.');
        } catch (TelegramTransportException $exception) {
            $this->assertStringContainsString('editMessageText', $exception->getMessage());
        }

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/editMessageText'));
    }

    public function test_unsuccessful_json_edit_response_is_rejected(): void
    {
        Http::fake([
            'https://api.telegram.org/*/editMessageText' => Http::response([
                'ok' => false,
                'description' => 'Unexpected Telegram failure',
            ]),
        ]);

        $this->expectException(TelegramApiException::class);
        app(TelegramBotClient::class)->editMessageText(12345, 777, 'Invalid response');
    }

    public function test_fake_telegram_client_matches_send_photo_contract(): void
    {
        $response = (new FakeTelegramBotClient)->sendPhoto(12345, 'binary-photo', 'receipt.png', 'Receipt');

        $this->assertTrue($response['ok']);
        $this->assertIsInt($response['result']['message_id']);
        $this->assertSame(12345, $response['result']['chat']['id']);
    }

    public function test_send_document_uses_multipart_payload_and_download_options(): void
    {
        $contents = "email@example.test\npassword=secret-value\n";
        $replyMarkup = ['inline_keyboard' => [[['text' => 'Download', 'callback_data' => 'download']]]];
        $captionEntities = [['type' => 'bold', 'offset' => 0, 'length' => 8]];

        Http::fake(fn () => Http::response([
            'ok' => true,
            'result' => [
                'message_id' => 987654,
                'chat' => ['id' => 12345],
            ],
        ], 200));

        $response = app(TelegramBotClient::class)->sendDocument(
            12345,
            $contents,
            '../lhie store/ORDER:42.txt',
            'Download',
            $replyMarkup,
            [
                'caption_entities' => $captionEntities,
                'protect_content' => false,
            ],
        );

        $this->assertSame(987654, $response['result']['message_id']);

        $requests = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendDocument'));
        $this->assertCount(1, $requests);
        $request = $requests->sole()[0];

        $this->assertTrue($request->isMultipart());
        $this->assertTrue($request->hasFile('document', $contents, 'lhie-store-ORDER-42.txt'));
        $this->assertSame('12345', (string) $this->multipartField($request, 'chat_id'));
        $this->assertSame('Download', $this->multipartField($request, 'caption'));
        $this->assertSame(
            $replyMarkup,
            json_decode((string) $this->multipartField($request, 'reply_markup'), true, 512, JSON_THROW_ON_ERROR),
        );
        $this->assertSame(
            $captionEntities,
            json_decode((string) $this->multipartField($request, 'caption_entities'), true, 512, JSON_THROW_ON_ERROR),
        );
        $this->assertFalse(filter_var($this->multipartField($request, 'protect_content'), FILTER_VALIDATE_BOOLEAN));
    }

    public function test_send_document_requires_a_positive_message_id_for_successful_response(): void
    {
        Http::fake(fn () => Http::response([
            'ok' => true,
            'result' => ['message_id' => 0],
        ], 200));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('positive integer');

        app(TelegramBotClient::class)->sendDocument(12345, 'document-body', 'download.txt');
    }

    public function test_send_document_transport_failures_preserve_ambiguous_classification_without_body_output(): void
    {
        $contents = 'credential-body-must-not-appear';

        Http::fake([
            'https://api.telegram.org/*/sendDocument' => Http::failedConnection('socket reset'),
        ]);

        try {
            app(TelegramBotClient::class)->sendDocument(12345, $contents, 'download.txt');
            $this->fail('Expected the Telegram document transport failure.');
        } catch (TelegramTransportException $exception) {
            $this->assertStringContainsString('sendDocument', $exception->getMessage());
            $this->assertStringNotContainsString($contents, $exception->getMessage());
        }
    }

    public function test_fake_telegram_client_records_send_document_without_document_contents(): void
    {
        $contents = 'credential-body-must-not-appear';
        $replyMarkup = ['inline_keyboard' => [[['text' => 'Download', 'callback_data' => 'download']]]];
        $options = ['protect_content' => false];
        $client = new FakeTelegramBotClient;

        $response = $client->sendDocument(
            12345,
            $contents,
            '../lhie store/ORDER:42.txt',
            'Download',
            $replyMarkup,
            $options,
        );

        $this->assertTrue($response['ok']);
        $this->assertIsInt($response['result']['message_id']);
        $this->assertGreaterThan(0, $response['result']['message_id']);
        $this->assertSame(12345, $response['result']['chat']['id']);
        $this->assertSame('lhie-store-ORDER-42.txt', $response['result']['document']['file_name']);

        $call = $client->calls[0];
        $this->assertSame('sendDocument', $call['method']);
        $this->assertSame(12345, $call['chat_id']);
        $this->assertSame('lhie-store-ORDER-42.txt', $call['filename']);
        $this->assertSame($options, $call['options']);
        $this->assertSame($response, $call['response']);
        $this->assertStringNotContainsString($contents, json_encode($client->calls, JSON_THROW_ON_ERROR));
    }

    public function test_send_document_rejects_unsuccessful_api_response(): void
    {
        Http::fake(fn () => Http::response([
            'ok' => false,
            'description' => 'Document rejected',
        ], 200));

        $this->expectException(TelegramApiException::class);

        app(TelegramBotClient::class)->sendDocument(12345, 'document-body', 'download.txt');
    }

    public function test_fast_webhook_ack_skips_empty_worker_ack_but_preserves_callback_notification(): void
    {
        Http::fake();
        app(TelegramCallbackAckState::class)->acknowledge('callback-query-id');

        $client = app(TelegramBotClient::class);
        $client->answerCallbackQuery('callback-query-id');
        $client->answerCallbackQuery('callback-query-id', 'Done');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && $request['text'] === 'Done');
    }

    public function test_transport_failures_surface_sanitized_client_visible_errors(): void
    {
        config()->set('services.telegram.token', '123456:ABC-issue-114-secret-token');
        config()->set('services.telegram.api_base_url', 'https://api.telegram.org');

        $token = (string) config('services.telegram.token');
        $botApiUrl = "https://api.telegram.org/bot{$token}/sendMessage";
        $botApiPath = "/bot{$token}/";

        Http::fake([
            $botApiUrl => Http::failedConnection(
                "cURL error 28: Operation timed out while requesting {$botApiUrl}; path={$botApiPath}sendMessage"
            ),
        ]);

        try {
            app(TelegramBotClient::class)->sendMessage(12345, 'This will time out');
            $this->fail('Expected the Telegram client to surface the transport failure.');
        } catch (Throwable $exception) {
            $messages = $this->exceptionChainMessages($exception);

            $this->assertNotSame('', $messages, 'The Telegram transport failure should include a safe actionable message.');
            $this->assertStringNotContainsString($token, $messages, 'Exception chain leaked the raw Telegram bot token.');
            $this->assertStringNotContainsString(rawurlencode($token), $messages, 'Exception chain leaked the URL-encoded Telegram bot token.');
            $this->assertStringNotContainsString($botApiUrl, $messages, 'Exception chain leaked the full Telegram Bot API URL.');
            $this->assertStringNotContainsString($botApiPath, $messages, 'Exception chain leaked the raw Telegram bot URL path segment.');
        }
    }

    private function operationForRequest($request): string
    {
        $method = basename(parse_url($request->url(), PHP_URL_PATH) ?: '');

        if ($method === 'sendPhoto' && ! $request->isMultipart()) {
            return 'sendPhotoReference';
        }

        return $method;
    }

    private function multipartField(mixed $request, string $field): mixed
    {
        foreach ($request->data() as $part) {
            if (is_array($part) && ($part['name'] ?? null) === $field) {
                return $part['contents'] ?? null;
            }
        }

        return $request[$field] ?? null;
    }

    private function exceptionChainMessages(Throwable $exception): string
    {
        $messages = [];

        do {
            $messages[] = $exception->getMessage();
            $exception = $exception->getPrevious();
        } while ($exception !== null);

        return implode("\n", $messages);
    }
}
