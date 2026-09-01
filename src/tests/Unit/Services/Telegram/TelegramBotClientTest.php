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

            return Http::response(['ok' => true, 'result' => ['id' => 12345]], 200);
        });

        $client = app(TelegramBotClient::class);

        $client->getChat(12345);
        $client->getChatMember(12345, 67890);
        $client->sendMessage(12345, 'Timeout regression coverage');
        $client->editMessageText(12345, 777, 'Edited message', ['inline_keyboard' => [[['text' => 'Next', 'callback_data' => 'next']]]]);
        $client->sendPhoto(12345, 'binary-photo', 'receipt.png', 'Receipt');
        $client->sendPhotoReference(12345, 'telegram-file-id', 'Receipt reference');
        $client->answerCallbackQuery('callback-query-id', 'Done');
        $client->deleteMessage(12345, 777);

        $this->assertSame([
            'getChat',
            'getChatMember',
            'sendMessage',
            'editMessageText',
            'sendPhoto',
            'sendPhotoReference',
            'answerCallbackQuery',
            'deleteMessage',
        ], array_keys($optionsByOperation));
        Http::assertSentCount(8);

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
