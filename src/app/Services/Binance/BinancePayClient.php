<?php

namespace App\Services\Binance;

use App\Support\DogfoodTiming;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class BinancePayClient
{
    public function configured(): bool
    {
        return filled(config('services.binance.api_key')) && filled(config('services.binance.api_secret'));
    }

    /**
     * Fetch the account's Binance Pay (C2C) trade history.
     *
     * @return list<array<string, mixed>> raw transaction payloads, newest first
     */
    public function payTransactions(?int $startTimeMs = null, ?int $endTimeMs = null, int $limit = 100): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Binance Pay API credentials are not configured.');
        }

        $params = array_filter([
            'startTime' => $startTimeMs,
            'endTime' => $endTimeMs,
            'limit' => max(1, min(100, $limit)),
        ], fn ($value) => $value !== null);
        $params['recvWindow'] = max(1000, (int) config('services.binance.recv_window_ms', 10000));
        $params['timestamp'] = (int) round(microtime(true) * 1000);

        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $query .= '&signature='.hash_hmac('sha256', $query, (string) config('services.binance.api_secret'));

        try {
            $response = $this->timed('payTransactions', fn () => $this->http()
                ->retry(
                    max(1, (int) config('services.binance.history_max_attempts', 3)),
                    max(0, (int) config('services.binance.history_retry_delay_ms', 250)),
                    fn (Throwable $exception): bool => $this->shouldRetry($exception),
                )
                ->withHeaders(['X-MBX-APIKEY' => (string) config('services.binance.api_key')])
                ->get($this->baseUrl().'/sapi/v1/pay/transactions?'.$query)
                ->throw()
                ->json());
        } catch (ConnectionException|RequestException $exception) {
            throw $this->providerRequestException($exception);
        }

        if (! is_array($response) || (string) ($response['code'] ?? '') !== '000000' || ($response['success'] ?? false) !== true) {
            throw new UnexpectedValueException('Binance Pay history response is not a successful payload.');
        }

        $data = $response['data'] ?? [];

        return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
    }

    private function providerRequestException(ConnectionException|RequestException $exception): RuntimeException
    {
        $status = $exception instanceof RequestException ? $exception->response?->status() : null;
        $detail = $status === null ? $exception::class : 'HTTP '.$status;

        return new RuntimeException('Binance Pay history request failed: '.$detail.'.');
    }

    private function shouldRetry(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof RequestException) {
            return false;
        }

        $status = $exception->response?->status();

        return $status === 408
            || $status === 429
            || ($status !== null && $status >= 500 && $status <= 599);
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.binance.base_url', 'https://api.binance.com'), '/');
    }

    private function http(): PendingRequest
    {
        $timeout = (float) config('services.binance.http_timeout_seconds', 10.0);
        $connectTimeout = (float) config('services.binance.http_connect_timeout_seconds', 3.0);

        return Http::timeout($timeout > 0 ? $timeout : 10.0)
            ->connectTimeout($connectTimeout > 0 ? $connectTimeout : 3.0);
    }

    private function timed(string $operation, callable $callback): mixed
    {
        $startedAt = DogfoodTiming::now();
        $status = null;

        try {
            $result = $callback();
            $status = 200;

            return $result;
        } catch (RequestException $e) {
            $status = $e->response?->status();
            throw $e;
        } finally {
            DogfoodTiming::log('dogfood.outbound_http', [
                'provider' => 'binance',
                'operation' => $operation,
                'status' => $status,
                'duration_ms' => DogfoodTiming::elapsedMs($startedAt),
            ]);
        }
    }
}
