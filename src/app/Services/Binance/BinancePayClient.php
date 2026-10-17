<?php

namespace App\Services\Binance;

use App\Support\DogfoodTiming;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class BinancePayClient
{
    private const HISTORY_CACHE_KEY = 'binance:pay-history:v2:window';

    public function configured(): bool
    {
        return filled(config('services.binance.api_key')) && filled(config('services.binance.api_secret'));
    }

    /**
     * Fetch the account's Binance Pay (C2C) trade history.
     *
     * @return list<array<string, mixed>> raw transaction payloads, newest first
     */
    public function payTransactions(?int $startTimeMs = null, ?int $endTimeMs = null, int $limit = 100, bool $fresh = false): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Binance Pay API credentials are not configured.');
        }

        $limit = max(1, min(100, $limit));
        $cacheSeconds = max(0, (int) config('services.binance.history_cache_seconds', 20));
        if ($fresh || $cacheSeconds === 0) {
            return $this->requestPayTransactions($startTimeMs, $endTimeMs, $limit);
        }

        $cacheKey = $this->historyCacheKey($startTimeMs, $endTimeMs, $limit);
        if (($cached = $this->cachedTransactions($cacheKey)) !== null) {
            return $cached;
        }

        // The reader may fan a window out into several bounded requests. Keep
        // the lease and wait window longer than that bound so a second worker
        // coalesces behind the in-flight read instead of surfacing a synthetic
        // lock timeout while the first worker is still querying Binance.
        $configuredLockSeconds = max(1, (int) config('services.binance.history_lock_seconds', 90));
        $readerBoundSeconds = max(1, (int) config('services.binance.history_max_duration_seconds', 45));
        $httpTimeoutSeconds = max(1, (int) ceil((float) config('services.binance.http_timeout_seconds', 10)));
        $maxAttempts = max(1, (int) config('services.binance.history_max_attempts', 3));
        $inFlightRequestSeconds = ($httpTimeoutSeconds + 5) * $maxAttempts;
        $lockSeconds = max($configuredLockSeconds, $readerBoundSeconds + $inFlightRequestSeconds);

        return Cache::lock($cacheKey.':lock', $lockSeconds)->block($lockSeconds, function () use ($startTimeMs, $endTimeMs, $limit, $cacheSeconds, $cacheKey): array {
            if (($cached = $this->cachedTransactions($cacheKey)) !== null) {
                return $cached;
            }

            $transactions = $this->cacheableTransactions($this->requestPayTransactions($startTimeMs, $endTimeMs, $limit));
            Cache::put($cacheKey, $transactions, now()->addSeconds($cacheSeconds));

            return $transactions;
        });
    }

    /** @return list<array<string, mixed>> */
    private function requestPayTransactions(?int $startTimeMs, ?int $endTimeMs, int $limit): array
    {

        $params = array_filter([
            'startTime' => $startTimeMs,
            'endTime' => $endTimeMs,
            'limit' => $limit,
        ], fn ($value) => $value !== null);
        $params['recvWindow'] = max(1000, (int) config('services.binance.recv_window_ms', 10000));
        $params['timestamp'] = (int) round(microtime(true) * 1000);

        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $query .= '&signature='.hash_hmac('sha256', $query, (string) config('services.binance.api_secret'));

        try {
            $response = $this->timed('payTransactions', fn () => $this->http()
                ->retry(
                    max(1, (int) config('services.binance.history_max_attempts', 3)),
                    fn (int $attempt, Throwable $exception): int => $this->retryDelayMs($attempt, $exception),
                    fn (Throwable $exception): bool => $this->shouldRetry($exception),
                )
                ->withHeaders(['X-MBX-APIKEY' => (string) config('services.binance.api_key')])
                ->get($this->baseUrl().'/sapi/v1/pay/transactions?'.$query)
                ->throw()
                ->json());
        } catch (ConnectionException|RequestException $exception) {
            throw $this->providerRequestException($exception);
        }

        if (! is_array($response)) {
            throw new BinanceProviderException('Binance Pay returned an invalid history response.', 200, false);
        }

        if ((string) ($response['code'] ?? '') !== '000000' || ($response['success'] ?? false) !== true) {
            $code = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($response['code'] ?? 'unknown')) ?: 'unknown';

            throw new BinanceProviderException('Binance Pay rejected the history request (code '.$code.').', 200, false);
        }

        $data = $response['data'] ?? [];

        return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
    }

    private function historyCacheKey(?int $startTimeMs, ?int $endTimeMs, int $limit): string
    {
        return self::HISTORY_CACHE_KEY.':'.hash('sha256', json_encode([
            'start_time_ms' => $startTimeMs,
            'end_time_ms' => $endTimeMs,
            'limit' => $limit,
        ], JSON_THROW_ON_ERROR));
    }

    /** @param list<array<string, mixed>> $transactions */
    private function cacheableTransactions(array $transactions): array
    {
        return array_values(array_filter(array_map(function (array $payload): ?array {
            $receiver = is_array($payload['receiverInfo'] ?? null) ? $payload['receiverInfo'] : [];
            $minimal = [
                'orderId' => $payload['orderId'] ?? null,
                'transactionId' => $payload['transactionId'] ?? null,
                'orderType' => $payload['orderType'] ?? null,
                'amount' => $payload['amount'] ?? null,
                'currency' => $payload['currency'] ?? null,
                'status' => $payload['status'] ?? null,
                'transactionStatus' => $payload['transactionStatus'] ?? null,
                'transactionTime' => $payload['transactionTime'] ?? null,
                'receiverInfo' => [
                    'accountId' => $receiver['accountId'] ?? null,
                    'binanceId' => $receiver['binanceId'] ?? null,
                ],
            ];

            if (! is_scalar($minimal['transactionId']) || trim((string) $minimal['transactionId']) === '') {
                return null;
            }

            return $minimal;
        }, $transactions), 'is_array'));
    }

    /** @return null|list<array<string, mixed>> */
    private function cachedTransactions(string $cacheKey): ?array
    {
        $cached = Cache::get($cacheKey);

        return is_array($cached) ? array_values(array_filter($cached, 'is_array')) : null;
    }

    private function providerRequestException(ConnectionException|RequestException $exception): BinanceProviderException
    {
        $status = $exception instanceof RequestException ? $exception->response?->status() : null;
        $detail = $status === null ? $exception::class : 'HTTP '.$status;
        $retryable = $status === null || $status === 408 || $status === 429 || ($status >= 500 && $status <= 599);

        return new BinanceProviderException('Binance Pay history request failed: '.$detail.'.', $status, $retryable);
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

    private function retryDelayMs(int $attempt, Throwable $exception): int
    {
        if ($exception instanceof RequestException) {
            $retryAfter = $exception->response?->header('Retry-After');
            if (is_numeric($retryAfter)) {
                return min(5_000, max(0, (int) $retryAfter * 1_000));
            }
        }

        $base = max(0, (int) config('services.binance.history_retry_delay_ms', 250));
        $exponential = min(5_000, $base * (2 ** max(0, $attempt - 1)));

        return $exponential + random_int(0, min(250, max(0, $base)));
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
