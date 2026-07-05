<?php

namespace Tests\Unit\Services\Telegram;

use App\Services\Telegram\TelegramBotClient;
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
        $client->sendPhoto(12345, 'binary-photo', 'receipt.png', 'Receipt');
        $client->sendPhotoReference(12345, 'telegram-file-id', 'Receipt reference');
        $client->answerCallbackQuery('callback-query-id', 'Done');
        $client->deleteMessage(12345, 777);

        $this->assertSame([
            'getChat',
            'getChatMember',
            'sendMessage',
            'sendPhoto',
            'sendPhotoReference',
            'answerCallbackQuery',
            'deleteMessage',
        ], array_keys($optionsByOperation));
        Http::assertSentCount(7);

        foreach ($optionsByOperation as $operation => $options) {
            $this->assertArrayHasKey('timeout', $options, "{$operation} did not configure an HTTP timeout.");
            $this->assertSame(7.25, $options['timeout'], "{$operation} used the wrong HTTP timeout.");
            $this->assertArrayHasKey('connect_timeout', $options, "{$operation} did not configure an HTTP connect timeout.");
            $this->assertSame(2.5, $options['connect_timeout'], "{$operation} used the wrong HTTP connect timeout.");
        }

        $this->assertTrue($requests['sendMessage']->isJson(), 'sendMessage should use the JSON post request path.');
        $this->assertSame('Timeout regression coverage', $requests['sendMessage']['text']);
        $this->assertTrue($requests['sendPhoto']->isMultipart(), 'sendPhoto should use the multipart attach request path.');
        $this->assertTrue($requests['sendPhoto']->hasFile('photo', 'binary-photo', 'receipt.png'));
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
