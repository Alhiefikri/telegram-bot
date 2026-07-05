<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramBotApiRequestFactory
{
    public function request(): PendingRequest
    {
        return Http::timeout($this->positiveFloatConfig('services.telegram.http_timeout_seconds', 10.0))
            ->connectTimeout($this->positiveFloatConfig('services.telegram.http_connect_timeout_seconds', 3.0));
    }

    public function url(string $method): string
    {
        return rtrim((string) config('services.telegram.api_base_url', 'https://api.telegram.org'), '/')
            .'/bot'.config('services.telegram.token').'/'.$method;
    }

    public function transportException(string $operation, ConnectionException $exception): RuntimeException
    {
        return new RuntimeException(sprintf(
            'Telegram Bot API %s request failed: %s.',
            $operation,
            class_basename($exception),
        ));
    }

    private function positiveFloatConfig(string $key, float $default): float
    {
        $value = config($key, $default);

        if (! is_numeric($value) || (float) $value <= 0) {
            return $default;
        }

        return (float) $value;
    }
}
