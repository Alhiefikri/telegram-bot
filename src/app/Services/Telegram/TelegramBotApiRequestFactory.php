<?php

namespace App\Services\Telegram;

use App\Exceptions\TelegramTransportException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class TelegramBotApiRequestFactory
{
    public function request(): PendingRequest
    {
        return Http::timeout($this->positiveFloatConfig('services.telegram.http_timeout_seconds', 10.0))
            ->connectTimeout($this->positiveFloatConfig('services.telegram.http_connect_timeout_seconds', 3.0));
    }

    public function poolRequest(Pool $pool, string $key): PendingRequest
    {
        return $pool->as($key)
            ->timeout($this->positiveFloatConfig('services.telegram.http_timeout_seconds', 10.0))
            ->connectTimeout($this->positiveFloatConfig('services.telegram.http_connect_timeout_seconds', 3.0));
    }

    public function url(string $method): string
    {
        return rtrim((string) config('services.telegram.api_base_url', 'https://api.telegram.org'), '/')
            .'/bot'.config('services.telegram.token').'/'.$method;
    }

    public function fileUrl(string $filePath): string
    {
        $base = (string) config('services.telegram.api_base_url', 'https://api.telegram.org');
        $parts = parse_url($base);
        $token = (string) config('services.telegram.token');
        $segments = explode('/', $filePath);

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'api.telegram.org'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '')
            || $token === ''
            || $filePath === ''
            || strlen($filePath) > 512
            || count($segments) > 8) {
            throw new InvalidArgumentException('Telegram proof path is unavailable.');
        }

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..'
                || strlen($segment) > 128
                || preg_match('/\\A[A-Za-z0-9][A-Za-z0-9._-]*\\z/', $segment) !== 1) {
                throw new InvalidArgumentException('Telegram proof path is unavailable.');
            }
        }

        return rtrim($base, '/').'/file/bot'.$token.'/'.$filePath;
    }

    public function transportException(string $operation, ConnectionException $exception): RuntimeException
    {
        return new TelegramTransportException($operation, $exception);
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
