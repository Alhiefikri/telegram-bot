<?php

namespace App\Services\WijayaPay;

use App\Services\ProviderVerification\ProviderVerificationProfiles;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use JsonException;
use Throwable;

final readonly class WijayaPayClient implements WijayaPayProviderAdapter
{
    private WijayaPayProviderReadiness $readiness;

    public function __construct(
        ?WijayaPayProviderReadiness $readiness = null,
        private ?WijayaPayRequestGate $gate = null,
    ) {
        $this->readiness = $readiness ?? new WijayaPayProviderReadiness;
    }

    /** @return list<WijayaPayChannel> */
    public function listChannels(): array
    {
        $this->assertReady('listChannels');

        $payload = $this->get(
            operation: 'listChannels',
            path: '/api/get-payment',
            query: [
                'code_merchant' => $this->codeMerchant(),
                'api_key' => $this->apiKey(),
            ],
        );

        if (($payload['success'] ?? null) !== true) {
            throw new WijayaPayApplicationException(
                'listChannels',
                providerMessage: $this->sanitizedMessage($payload['message'] ?? null),
            );
        }

        try {
            $channels = [];
            foreach (WijayaPayPayloadReader::dataItems($payload) as $item) {
                $data = WijayaPayPayloadReader::object($item);
                try {
                    $channels[] = WijayaPayChannel::fromPayload($data);
                } catch (WijayaPaySchemaException $exception) {
                    $code = WijayaPayPayloadReader::optionalString($data, 'code', 64);
                    if ($code !== null && strcasecmp($code, 'QRIS') !== 0) {
                        // An unrelated VA/retail row cannot invalidate the
                        // QRIS selection this release depends on.
                        continue;
                    }

                    throw $exception;
                }
            }

            return $channels;
        } catch (WijayaPaySchemaException $exception) {
            throw new WijayaPaySchemaException('listChannels', previous: $exception);
        } catch (Throwable $exception) {
            throw new WijayaPaySchemaException('listChannels', previous: $exception);
        }
    }

    public function createInvoice(WijayaPayCreateInvoice $invoice): WijayaPayInvoice
    {
        $this->assertReady('createInvoice', create: true);

        $signature = $invoice->signature();
        $response = $this->post(
            operation: 'createInvoice',
            path: '/api/transaction/create',
            body: $invoice->toProviderPayload(),
            signature: $signature,
            create: true,
        );

        $payload = $this->decode($response, 'createInvoice', outcomeUnknown: true);
        $this->assertCreateSuccess($payload);

        try {
            return WijayaPayInvoice::fromPayload($payload, $invoice);
        } catch (WijayaPaySchemaException $exception) {
            throw new WijayaPaySchemaException('createInvoice', outcomeUnknown: true, previous: $exception);
        } catch (Throwable $exception) {
            throw new WijayaPaySchemaException('createInvoice', outcomeUnknown: true, previous: $exception);
        }
    }

    public function transactionStatus(string $refId): WijayaPayStatusResponse
    {
        $this->assertReady('transactionStatus');
        self::assertIdentifier($refId, 'merchant reference');

        $payload = $this->get(
            operation: 'transactionStatus',
            path: '/api/get-status',
            query: [
                'code_merchant' => $this->codeMerchant(),
                'api_key' => $this->apiKey(),
                'ref_id' => $refId,
            ],
        );

        try {
            return WijayaPayStatusResponse::fromPayload($payload, $refId);
        } catch (WijayaPaySchemaException $exception) {
            throw new WijayaPaySchemaException('transactionStatus', previous: $exception);
        } catch (Throwable $exception) {
            throw new WijayaPaySchemaException('transactionStatus', previous: $exception);
        }
    }

    /**
     * @param  array<string, string>  $query
     * @return array<mixed>
     */
    private function get(string $operation, string $path, array $query): array
    {
        $maxAttempts = $this->lookupMaxAttempts();
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = $this->dispatch(
                    operation: $operation,
                    create: false,
                    callback: fn (): Response => $this->request()->get($this->endpoint($path), $query),
                );
            } catch (WijayaPayTransportException $exception) {
                if ($attempt < $maxAttempts) {
                    $this->sleepBeforeRetry($attempt);

                    continue;
                }

                throw $exception;
            }

            if ($response->successful()) {
                $payload = $this->decode($response, $operation);
                if (($payload['success'] ?? null) === false) {
                    throw new WijayaPayApplicationException(
                        $operation,
                        providerMessage: $this->sanitizedMessage($payload['message'] ?? null),
                    );
                }

                return $payload;
            }

            if ($this->isRetryable($response) && $attempt < $maxAttempts) {
                $this->sleepBeforeRetry($attempt);

                continue;
            }

            if ($response->status() === 404) {
                $message = null;
                try {
                    $payload = json_decode($response->body(), true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                    if (is_array($payload) && ! array_is_list($payload)) {
                        $message = $this->sanitizedMessage($payload['message'] ?? null);
                    }
                } catch (JsonException) {
                    // The HTTP status remains the failure fact.
                }

                if ($message !== null) {
                    throw new WijayaPayApplicationException(
                        $operation,
                        providerStatus: '404',
                        providerMessage: $message,
                    );
                }
            }

            throw $this->httpFailure($operation, $response);
        }

        throw new WijayaPayTransportException($operation);
    }

    /**
     * @param  array<string, int|string>  $body
     */
    private function post(
        string $operation,
        string $path,
        array $body,
        string $signature,
        bool $create,
    ): Response {
        $response = $this->dispatch(
            operation: $operation,
            create: $create,
            callback: fn (): Response => $this->request()
                ->withHeaders(['X-Signature' => $signature])
                ->post($this->endpoint($path), $body),
        );

        if (! $response->successful()) {
            if (! WijayaPayHttpException::isRetryableStatus($response->status())
                && $response->status() >= 400
                && $response->status() < 500
            ) {
                $message = null;
                try {
                    $payload = json_decode($response->body(), true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                    if (is_array($payload) && ! array_is_list($payload)) {
                        $message = $this->sanitizedMessage($payload['message'] ?? null);
                    }
                } catch (JsonException) {
                    // The HTTP status remains the rejection fact.
                }

                throw new WijayaPayApplicationException(
                    $operation,
                    providerStatus: (string) $response->status(),
                    providerMessage: $message,
                );
            }

            throw $this->httpFailure($operation, $response, outcomeUnknown: $create);
        }

        return $response;
    }

    /**
     * @param  callable(): Response  $callback
     */
    private function dispatch(string $operation, bool $create, callable $callback): Response
    {
        $gate = $this->gate ?? new WijayaPayRequestGate;
        $lock = $gate->acquire($operation, create: $create);

        try {
            $response = $callback();
            $gate->observe($response);

            return $response;
        } catch (ConnectionException $exception) {
            // Never attach the raw transport exception: its message can include
            // the GET URL with the merchant API key in the query string.
            throw new WijayaPayTransportException($operation, outcomeUnknown: $create);
        } catch (WijayaPayCreatePreRequestException|WijayaPayProviderException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new WijayaPayTransportException($operation, outcomeUnknown: $create);
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // The owner-fenced lease expires without changing a dispatched request.
            }
        }
    }

    /** @param array<mixed> $payload */
    private function assertCreateSuccess(array $payload): void
    {
        $data = $payload['data'] ?? null;
        if (is_array($data)
            && ! array_is_list($data)
            && isset($data['ref_id'], $data['trx_reference'])
            && (array_key_exists('status_pembayaran', $payload) || array_key_exists('status_pembayaran', $data))
        ) {
            // A duplicate ref_id is documented to return the existing
            // transaction status instead of a create envelope. Validate the
            // returned identity in WijayaPayInvoice::fromPayload.
            return;
        }

        if (($payload['success'] ?? null) === true) {
            return;
        }

        throw new WijayaPayApplicationException(
            'createInvoice',
            providerMessage: $this->sanitizedMessage($payload['message'] ?? null),
        );
    }

    /** @return array<mixed> */
    private function decode(Response $response, string $operation, bool $outcomeUnknown = false): array
    {
        try {
            $payload = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $exception) {
            throw new WijayaPayJsonException($operation, outcomeUnknown: $outcomeUnknown, previous: $exception);
        }

        if (! is_array($payload) || array_is_list($payload)) {
            throw new WijayaPaySchemaException($operation, outcomeUnknown: $outcomeUnknown);
        }

        return $payload;
    }

    private function httpFailure(string $operation, Response $response, bool $outcomeUnknown = false): WijayaPayProviderException
    {
        $message = null;
        try {
            $payload = json_decode($response->body(), true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (is_array($payload) && ! array_is_list($payload)) {
                $message = $this->sanitizedMessage($payload['message'] ?? null);
            }
        } catch (JsonException) {
            // The HTTP status remains the authoritative failure fact.
        }

        $exception = new WijayaPayHttpException($operation, $response->status(), outcomeUnknown: $outcomeUnknown);
        if ($message === null) {
            return $exception;
        }

        return new WijayaPayProviderException(
            $exception->getMessage(),
            operation: $operation,
            httpStatus: $response->status(),
            providerMessage: $message,
            outcomeUnknown: $outcomeUnknown,
            retryable: $exception->retryable,
            reason: $exception->reason,
            previous: $exception,
        );
    }

    private function isRetryable(Response $response): bool
    {
        return WijayaPayHttpException::isRetryableStatus($response->status())
            && $response->status() !== 429;
    }

    private function assertReady(string $operation, bool $create = false): void
    {
        try {
            $this->readiness->configurationReport()->assertReady();
        } catch (WijayaPayReadinessException $exception) {
            if ($create) {
                throw new WijayaPayCreatePreRequestException('provider_not_ready', $exception);
            }

            throw new WijayaPayProviderException(
                'WijayaPay is not configured for this environment.',
                operation: $operation,
                reason: 'provider_not_ready',
                previous: $exception,
            );
        }
    }

    private function request(): PendingRequest
    {
        return ProviderVerificationProfiles::prepareRequest(
            Http::asJson()->acceptJson(),
            $this->positiveFloatConfig('services.wijayapay.http_timeout_seconds', 10.0),
            $this->positiveFloatConfig('services.wijayapay.http_connect_timeout_seconds', 3.0),
        );
    }

    private function endpoint(string $path): string
    {
        $baseUrl = (string) config('services.wijayapay.base_url', 'https://gateway.wijayapay.com');

        return rtrim($baseUrl, '/').'/'.ltrim($path, '/');
    }

    private function codeMerchant(): string
    {
        return (string) config('services.wijayapay.code_merchant');
    }

    private function apiKey(): string
    {
        return (string) config('services.wijayapay.api_key');
    }

    private function lookupMaxAttempts(): int
    {
        $value = config('services.wijayapay.lookup_max_attempts', 3);
        if (! is_numeric($value)) {
            return 3;
        }

        return min(5, max(1, (int) $value));
    }

    private function sleepBeforeRetry(int $attempt): void
    {
        $baseDelay = $this->nonNegativeIntConfig('services.wijayapay.lookup_retry_delay_ms', 250);
        $maxDelay = $this->nonNegativeIntConfig('services.wijayapay.lookup_retry_max_delay_ms', 1000);
        if ($baseDelay === 0 || $maxDelay === 0) {
            return;
        }

        $delay = min($baseDelay * (2 ** max(0, $attempt - 1)), $maxDelay);
        ProviderVerificationProfiles::pause($delay);
    }

    private function positiveFloatConfig(string $key, float $default): float
    {
        $value = config($key, $default);
        if (! is_numeric($value) || (float) $value <= 0) {
            return $default;
        }

        return (float) $value;
    }

    private function nonNegativeIntConfig(string $key, int $default): int
    {
        $value = config($key, $default);
        if (! is_numeric($value) || (int) $value < 0) {
            return $default;
        }

        return min(60_000, (int) $value);
    }

    private function sanitizedMessage(mixed $message): ?string
    {
        if (! is_string($message) || trim($message) === '') {
            return null;
        }

        $sanitized = preg_replace('/\s+/', ' ', trim($message)) ?? '';

        $secrets = array_filter([
            $this->apiKey(),
            $this->codeMerchant(),
        ], static fn (string $value): bool => $value !== '');

        foreach ($secrets as $secret) {
            $sanitized = str_replace($secret, '[redacted]', $sanitized);
        }

        return mb_substr($sanitized, 0, 200);
    }

    private static function assertIdentifier(string $value, string $label): void
    {
        if ($value === '' || trim($value) !== $value || strlen($value) > 255) {
            throw new InvalidArgumentException('WijayaPay '.$label.' is invalid.');
        }
    }
}
