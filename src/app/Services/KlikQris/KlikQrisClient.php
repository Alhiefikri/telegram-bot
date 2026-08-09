<?php

namespace App\Services\KlikQris;

use App\Enums\KlikQrisEnvironment;
use App\Models\Payment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

final readonly class KlikQrisClient implements KlikQrisProviderAdapter
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
            $response = $this->request()->post($this->endpoint('/qris/create'), [
                'order_id' => $payment->merchant_ref,
                'id_merchant' => $this->merchantId(),
                'amount' => (int) $payment->amount,
                'keterangan' => 'Payment '.$payment->merchant_ref,
            ]);
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
        $maxAttempts = max(1, (int) config('services.klikqris.status_max_attempts', 3));

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
            ->timeout((float) config('services.klikqris.http_timeout_seconds', 10))
            ->connectTimeout((float) config('services.klikqris.http_connect_timeout_seconds', 3))
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

    private function parseStatusResponse(Response $response, Payment $payment): KlikQrisTransaction
    {
        $payload = $this->jsonPayload($response, 'status');

        try {
            $transaction = KlikQrisTransaction::fromStatusPayload($payload, $this->environment);
            $transaction->assertMatches($payment);

            return $transaction;
        } catch (UnexpectedValueException $exception) {
            throw new RuntimeException('KlikQRIS status response validation failed: '.class_basename($exception).'.', previous: $exception);
        }
    }

    /** @return array<mixed> */
    private function jsonPayload(Response $response, string $operation): array
    {
        $payload = $response->json();
        if (! is_array($payload)) {
            if ($operation === 'create') {
                throw new KlikQrisCreateOutcomeUnknownException('KlikQRIS create outcome is unknown: invalid JSON.');
            }

            throw new RuntimeException('KlikQRIS '.$operation.' response parse failed: invalid JSON.');
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
        $baseDelayMs = max(0, (int) config('services.klikqris.status_retry_delay_ms', 250));
        if ($baseDelayMs === 0) {
            return;
        }

        $delayMs = min($baseDelayMs * (2 ** max(0, $attempt - 1)), 1000);
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
