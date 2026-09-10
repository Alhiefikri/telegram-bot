<?php

namespace App\Services\KlikQris;

use App\Enums\KlikQrisEnvironment;
use App\Models\Payment;
use App\Models\StoreBalanceTopUp;
use App\Services\ProviderVerification\ProviderVerificationProfiles;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

final readonly class KlikQrisClient implements KlikQrisProviderAdapter, KlikQrisTopUpProviderAdapter
{
    private readonly KlikQrisEnvironment $environment;

    public function __construct(
        private KlikQrisProviderReadiness $readiness,
        ?KlikQrisEnvironment $environment = null,
    ) {
        $this->environment = $environment ?? KlikQrisEnvironment::runtime();
    }

    public function createTransaction(Payment $payment): KlikQrisTransaction
    {
        try {
            $this->assertReady();
        } catch (Throwable $exception) {
            throw new KlikQrisCreatePreRequestException('KlikQRIS create request was not sent: readiness failed.', $exception);
        }

        try {
            $body = [
                'order_id' => $payment->merchant_ref,
                'id_merchant' => $this->merchantId(),
                'amount' => (int) $payment->amount,
                'keterangan' => 'Payment '.$payment->merchant_ref,
            ];
            $callbackUrl = config($this->environment->configKey('callback_url'));
            if (is_string($callbackUrl) && filled($callbackUrl)) {
                $body['callback_url'] = $callbackUrl;
            }

            $response = $this->request()->post($this->endpoint('/qris/create'), $body);
        } catch (ConnectionException $exception) {
            throw new KlikQrisCreateOutcomeUnknownException('KlikQRIS create outcome is unknown: connection error.', $exception);
        } catch (Throwable $exception) {
            throw new KlikQrisCreateOutcomeUnknownException('KlikQRIS create outcome is unknown: '.class_basename($exception).'.', $exception);
        }

        $this->throwIfCreateHttpFailed($response);

        return $this->parseCreateResponse($response, $payment);
    }

    public function transactionStatus(Payment $payment): KlikQrisTransaction
    {
        $this->assertReady();

        $attempt = 0;
        $maxAttempts = ProviderVerificationProfiles::budget()['max_attempts'];

        while (true) {
            $attempt++;

            try {
                $response = $this->request()->get($this->endpoint('/qris/status/'.rawurlencode((string) $payment->merchant_ref)));
                $this->throwIfHttpFailed($response, 'status');

                return $this->parseStatusResponse($response, $payment);
            } catch (Throwable $exception) {
                if ($attempt >= $maxAttempts || ! $this->shouldRetryStatus($exception)) {
                    if ($this->shouldRetryStatus($exception)) {
                        throw new KlikQrisStatusUnavailableException;
                    }

                    if ($exception instanceof RuntimeException) {
                        throw $exception;
                    }

                    throw new RuntimeException('KlikQRIS status request failed: '.class_basename($exception).'.', previous: $exception);
                }

                $this->sleepBeforeStatusRetry($attempt);
            }
        }
    }

    public function createTopUpTransaction(StoreBalanceTopUp $topUp): KlikQrisTransaction
    {
        try {
            $this->assertReady();
        } catch (Throwable $exception) {
            throw new KlikQrisCreatePreRequestException('KlikQRIS top-up create request was not sent: readiness failed.', $exception);
        }

        try {
            $body = [
                'order_id' => $topUp->merchant_ref,
                'id_merchant' => $this->merchantId(),
                'amount' => (int) $topUp->amount,
                'keterangan' => 'Balance top-up '.$topUp->merchant_ref,
            ];
            $callbackUrl = config($this->environment->configKey('callback_url'));
            if (is_string($callbackUrl) && filled($callbackUrl)) {
                $body['callback_url'] = $callbackUrl;
            }

            $response = $this->request()->post($this->endpoint('/qris/create'), $body);
        } catch (ConnectionException $exception) {
            throw new KlikQrisCreateOutcomeUnknownException('KlikQRIS top-up create outcome is unknown: connection error.', $exception);
        } catch (Throwable $exception) {
            throw new KlikQrisCreateOutcomeUnknownException('KlikQRIS top-up create outcome is unknown: '.class_basename($exception).'.', $exception);
        }

        $this->throwIfCreateHttpFailed($response);

        return $this->parseCreateTopUpResponse($response, $topUp);
    }

    public function topUpTransactionStatus(StoreBalanceTopUp $topUp): KlikQrisTransaction
    {
        $this->assertReady();

        $attempt = 0;
        $maxAttempts = ProviderVerificationProfiles::budget()['max_attempts'];

        while (true) {
            $attempt++;

            try {
                $response = $this->request()->get($this->endpoint('/qris/status/'.rawurlencode((string) $topUp->merchant_ref)));
                $this->throwIfHttpFailed($response, 'status');

                return $this->parseTopUpStatusResponse($response, $topUp);
            } catch (Throwable $exception) {
                if ($attempt >= $maxAttempts || ! $this->shouldRetryStatus($exception)) {
                    if ($this->shouldRetryStatus($exception)) {
                        throw new KlikQrisStatusUnavailableException;
                    }

                    if ($exception instanceof RuntimeException) {
                        throw $exception;
                    }

                    throw new RuntimeException('KlikQRIS top-up status request failed: '.class_basename($exception).'.', previous: $exception);
                }

                $this->sleepBeforeStatusRetry($attempt);
            }
        }
    }

    public function historyPage(int $page = 1): KlikQrisHistoryPage
    {
        if ($page < 1) {
            throw KlikQrisReportingException::validation();
        }

        $this->assertReportingReady();
        $parameters = ['page' => $page];
        $result = $this->rememberReporting(
            $this->reportingCacheKey('history', $parameters),
            fn (): KlikQrisHistoryPage => $this->fetchHistoryPage($page),
        );

        if (! $result instanceof KlikQrisHistoryPage) {
            throw KlikQrisReportingException::schema();
        }

        return $result;
    }

    public function withdrawalOrdersPage(KlikQrisWithdrawalQuery $query): KlikQrisWithdrawalPage
    {
        $this->assertReportingReady();

        try {
            $parameters = $query->toArray();
        } catch (Throwable) {
            throw KlikQrisReportingException::validation();
        }

        $result = $this->rememberReporting(
            $this->reportingCacheKey('withdrawal-orders', $parameters),
            fn (): KlikQrisWithdrawalPage => $this->fetchWithdrawalOrdersPage($parameters),
        );

        if (! $result instanceof KlikQrisWithdrawalPage) {
            throw KlikQrisReportingException::schema();
        }

        return $result;
    }

    private function fetchHistoryPage(int $page): KlikQrisHistoryPage
    {
        $payload = $this->reportingPayload(
            $this->reportingResponse('/qris/history', ['page' => $page]),
        );

        try {
            return KlikQrisHistoryPage::fromPayload($payload);
        } catch (Throwable) {
            throw KlikQrisReportingException::schema();
        }
    }

    /** @param array<string, int|string> $parameters */
    private function fetchWithdrawalOrdersPage(array $parameters): KlikQrisWithdrawalPage
    {
        $payload = $this->reportingPayload(
            $this->reportingResponse('/withdraw/orders', $parameters),
        );

        try {
            return KlikQrisWithdrawalPage::fromPayload($payload, $this->environment);
        } catch (Throwable) {
            throw KlikQrisReportingException::schema();
        }
    }

    private function assertReportingReady(): void
    {
        try {
            $this->assertReady();
        } catch (Throwable) {
            throw KlikQrisReportingException::readiness();
        }
    }

    /**
     * @param  array<string, int|string>  $parameters
     */
    private function reportingResponse(string $path, array $parameters): Response
    {
        try {
            $budget = ProviderVerificationProfiles::budget();
            $response = $this->request()
                ->retry(
                    $budget['max_attempts'],
                    fn (int $attempt): int => $this->reportingRetryDelay($attempt),
                    fn (Throwable $exception): bool => $this->shouldRetryReporting($exception),
                )
                ->get($this->endpoint($path), $parameters);

            if ($response->failed()) {
                throw KlikQrisReportingException::http($response->status());
            }

            return $response;
        } catch (KlikQrisReportingException $exception) {
            throw $exception;
        } catch (ConnectionException) {
            throw KlikQrisReportingException::connection();
        } catch (RequestException $exception) {
            $status = $exception->response?->status();
            if ($status === null) {
                throw KlikQrisReportingException::transport();
            }

            throw KlikQrisReportingException::http($status);
        } catch (Throwable) {
            throw KlikQrisReportingException::transport();
        }
    }

    /** @return array<mixed> */
    private function reportingPayload(Response $response): array
    {
        try {
            $payload = $response->json();
        } catch (Throwable) {
            throw KlikQrisReportingException::json();
        }

        if (! is_array($payload)) {
            throw KlikQrisReportingException::json();
        }

        return KlikQrisPayloadSanitizer::sanitize($payload);
    }

    private function shouldRetryReporting(Throwable $exception): bool
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

    private function reportingRetryDelay(int $attempt): int
    {
        $budget = ProviderVerificationProfiles::budget();

        return min(
            $budget['retry_delay_ms'] * (2 ** max(0, $attempt - 1)),
            $budget['retry_max_delay_ms'],
        );
    }

    private function reportingCacheSeconds(): int
    {
        $value = config('services.klikqris.reporting_cache_seconds', 15);
        if (! is_numeric($value)) {
            return 15;
        }

        return min(300, max(0, (int) $value));
    }

    /** @param array<string, int|string> $parameters */
    private function reportingCacheKey(string $operation, array $parameters): string
    {
        try {
            $hash = hash('sha256', json_encode($parameters, JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            throw KlikQrisReportingException::validation();
        }

        return 'klikqris:reporting:'.$operation.':'.$this->environment->value.':'.$hash;
    }

    private function rememberReporting(string $key, Closure $callback): mixed
    {
        $seconds = $this->reportingCacheSeconds();
        if ($seconds === 0) {
            return $callback();
        }

        return Cache::remember($key, $seconds, $callback);
    }

    private function assertReady(): void
    {
        try {
            $this->readiness->forEnvironment($this->environment)->assertReady(
                'KlikQRIS '.$this->environment->label().' environment is not ready.',
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('KlikQRIS '.$this->environment->label().' environment is not ready: configuration unavailable.', previous: $exception);
        }
    }

    private function request(): PendingRequest
    {
        return Http::asJson()
            ->acceptJson()
            ->timeout(ProviderVerificationProfiles::budget()['timeout_seconds'])
            ->connectTimeout(ProviderVerificationProfiles::budget()['connect_timeout_seconds'])
            ->withHeaders([
                'x-api-key' => $this->apiKey(),
                'id_merchant' => $this->merchantId(),
            ]);
    }

    private function throwIfHttpFailed(Response $response, string $operation): void
    {
        if (! $response->failed()) {
            return;
        }

        if ($operation === 'status') {
            throw new KlikQrisStatusResponseException(
                sprintf('KlikQRIS %s request failed with HTTP %d.', $operation, $response->status()),
                $response->status(),
            );
        }

        throw new RuntimeException(sprintf('KlikQRIS %s request failed with HTTP %d.', $operation, $response->status()));
    }

    private function throwIfCreateHttpFailed(Response $response): void
    {
        if (! $response->failed()) {
            return;
        }

        throw new KlikQrisCreateOutcomeUnknownException(sprintf('KlikQRIS create outcome is unknown after HTTP %d.', $response->status()));
    }

    private function parseCreateResponse(Response $response, Payment $payment): KlikQrisTransaction
    {
        $payload = $this->jsonPayload($response, 'create');
        try {
            $transaction = KlikQrisTransaction::fromCreatePayload($payload, $this->environment);
            $transaction->assertMatches($payment);

            return $transaction;
        } catch (UnexpectedValueException $exception) {
            throw new KlikQrisCreateOutcomeUnknownException('KlikQRIS create outcome is unknown: response validation failed.', $exception);
        }
    }

    private function parseCreateTopUpResponse(Response $response, StoreBalanceTopUp $topUp): KlikQrisTransaction
    {
        $payload = $this->jsonPayload($response, 'create');
        try {
            $transaction = KlikQrisTransaction::fromCreatePayload($payload, $this->environment);
            $transaction->assertMatchesTopUp($topUp);

            return $transaction;
        } catch (UnexpectedValueException $exception) {
            throw new KlikQrisCreateOutcomeUnknownException('KlikQRIS top-up create outcome is unknown: response validation failed.', $exception);
        }
    }

    private function parseStatusResponse(Response $response, Payment $payment): KlikQrisTransaction
    {
        $payload = $this->jsonPayload($response, 'status');

        try {
            $transaction = KlikQrisTransaction::fromStatusPayload($payload, $this->environment);
            $transaction->assertMatches($payment);

            return $transaction;
        } catch (Throwable $exception) {
            throw new KlikQrisStatusResponseException(
                'KlikQRIS status response validation failed: '.class_basename($exception).'.',
                previous: $exception,
            );
        }
    }

    private function parseTopUpStatusResponse(Response $response, StoreBalanceTopUp $topUp): KlikQrisTransaction
    {
        $payload = $this->jsonPayload($response, 'status');

        try {
            $transaction = KlikQrisTransaction::fromStatusPayload($payload, $this->environment);
            $transaction->assertMatchesTopUp($topUp);

            return $transaction;
        } catch (Throwable $exception) {
            throw new KlikQrisStatusResponseException(
                'KlikQRIS top-up status response validation failed: '.class_basename($exception).'.',
                previous: $exception,
            );
        }
    }

    /** @return array<mixed> */
    private function jsonPayload(Response $response, string $operation): array
    {
        try {
            $payload = $response->json();
        } catch (Throwable $exception) {
            if ($operation === 'create') {
                throw new KlikQrisCreateOutcomeUnknownException('KlikQRIS create outcome is unknown: invalid JSON.', $exception);
            }

            throw new KlikQrisStatusResponseException(
                'KlikQRIS '.$operation.' response parse failed: invalid JSON.',
                previous: $exception,
            );
        }

        if (! is_array($payload)) {
            if ($operation === 'create') {
                throw new KlikQrisCreateOutcomeUnknownException('KlikQRIS create outcome is unknown: invalid JSON.');
            }

            throw new KlikQrisStatusResponseException('KlikQRIS '.$operation.' response parse failed: invalid JSON.');
        }

        return $payload;
    }

    private function shouldRetryStatus(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof RuntimeException) {
            return false;
        }

        if (! preg_match('/HTTP (\d{3})/', $exception->getMessage(), $matches)) {
            return false;
        }

        $status = (int) $matches[1];

        return $status === 408 || $status === 429 || $status >= 500;
    }

    private function sleepBeforeStatusRetry(int $attempt): void
    {
        $budget = ProviderVerificationProfiles::budget();
        $baseDelayMs = $budget['retry_delay_ms'];
        if ($baseDelayMs === 0) {
            return;
        }

        $delayMs = min($baseDelayMs * (2 ** max(0, $attempt - 1)), $budget['retry_max_delay_ms']);
        usleep($delayMs * 1000);
    }

    private function endpoint(string $path): string
    {
        return rtrim((string) config($this->environment->configKey('base_url')), '/').$path;
    }

    private function apiKey(): string
    {
        return (string) config($this->environment->configKey('api_key'));
    }

    private function merchantId(): string
    {
        return (string) config($this->environment->configKey('merchant_id'));
    }
}
