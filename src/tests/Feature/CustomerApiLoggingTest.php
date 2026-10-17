<?php

namespace Tests\Feature;

use App\Logging\RedactCustomerApiSecrets;
use App\Services\Telegram\TelegramBotApiRedactor;
use Illuminate\Http\Request;
use Illuminate\Log\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Logger as Monolog;
use Tests\TestCase;

class CustomerApiLoggingTest extends TestCase
{
    public function test_synthetic_keys_headers_nested_payloads_and_exception_arguments_do_not_enter_logs(): void
    {
        $token = 'capi_'.bin2hex(random_bytes(16)).'.'.bin2hex(random_bytes(32));
        $stream = fopen('php://temp', 'w+');
        $logger = new Logger(new Monolog('s04', [new StreamHandler($stream)]));
        (new RedactCustomerApiSecrets)($logger);
        $logger->warning('synthetic-marker '.$token, ['Authorization' => 'Bearer '.$token, 'nested' => ['payload' => $token],
            'exception' => new \RuntimeException('provider echoed '.$token)]);
        rewind($stream);
        $output = stream_get_contents($stream);
        $this->assertStringContainsString('synthetic-marker', $output);
        $this->assertStringNotContainsString($token, $output);
        $this->assertStringNotContainsString(substr($token, -64), $output);
        $this->assertStringNotContainsString($token, TelegramBotApiRedactor::redactText('provider echoed '.$token));
        $this->app->instance('request', Request::create('/api/v1/orders/123/delivery'));
        $logger->error('purchased-private-value', ['arbitrary' => 'purchased-private-value']);
        rewind($stream);
        $output = stream_get_contents($stream);
        $this->assertStringContainsString('Customer API event (details suppressed).', $output);
        $this->assertStringNotContainsString('purchased-private-value', $output);
        fclose($stream);
    }

    public function test_encoded_keys_and_serializer_objects_are_redacted_outside_private_requests(): void
    {
        $token = 'capi_'.bin2hex(random_bytes(16)).'.'.bin2hex(random_bytes(32));
        $encoded = str_replace('.', '%2e', $token);
        $privateValue = 'serializer-private-'.bin2hex(random_bytes(16));
        $object = new class($privateValue) implements \JsonSerializable
        {
            public bool $serialized = false;

            public function __construct(private string $value) {}

            public function jsonSerialize(): mixed
            {
                $this->serialized = true;

                return $this->value;
            }
        };
        $stream = fopen('php://temp', 'w+');
        $logger = new Logger(new Monolog('security', [new StreamHandler($stream)]));
        (new RedactCustomerApiSecrets)($logger);
        $logger->warning('encoded-marker '.$encoded, [
            'nested' => ['value' => $encoded],
            'response' => $privateValue,
            'object' => $object,
        ]);
        rewind($stream);
        $output = stream_get_contents($stream);
        $this->assertStringContainsString('encoded-marker', $output);
        $this->assertStringContainsString('[redacted-customer-api-key]', $output);
        $this->assertStringNotContainsString($encoded, $output);
        $this->assertStringNotContainsString(substr($token, -64), $output);
        $this->assertStringNotContainsString($privateValue, $output);
        $this->assertFalse($object->serialized);
        fclose($stream);
    }
}
