<?php

namespace App\Services\Sakurupiah;

use App\Enums\SakurupiahEnvironment;
use App\Services\ProviderVerification\ProviderVerificationProfiles;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Throwable;

final readonly class SakurupiahClient implements SakurupiahProviderAdapter
{
    private SakurupiahEnvironment $environment;

    private SakurupiahProviderReadiness $readiness;

    public function __construct(
        ?SakurupiahEnvironment $environment = null,
        ?SakurupiahProviderReadiness $readiness = null,
    ) {
        $this->environment = $environment ?? SakurupiahEnvironment::runtime();
        $this->readiness = $readiness ?? new SakurupiahProviderReadiness($this->environment);
    }

    /** @return list<SakurupiahChannel> */
    public function listChannels(): array
    {
        $this->assertReady();
        $payload = $this->successfulPayload(
            operation: 'listChannels',
            path: 'list-payment.php',
            body: ['api_id' => $this->apiId(), 'method' => 'list'],
            lookup: true,
        );

        try {
            $channels = [];
            foreach (SakurupiahPayloadReader::dataItems($payload) as $item) {
                $data = SakurupiahPayloadReader::object($item);
                try {
                    $channels[] = SakurupiahChannel::fromPayload($data);
                } catch (Throwable $exception) {
                    $code = SakurupiahPayloadReader::requiredString($data, 'kode', 64);
                    if (preg_match('/\A[A-Za-z0-9_]+\z/', $code) !== 1 || SakurupiahChannelPolicy::supports(strtoupper($code))) {
                        throw $exception;
                    }
                    // An identifiable non-QRIS method cannot authorize or invalidate a QRIS selection.
                }
            }

            return $channels;
        } catch (SakurupiahSchemaException $exception) {
            throw new SakurupiahSchemaException('listChannels', previous: $exception);
        } catch (Throwable $exception) {
            throw new SakurupiahSchemaException('listChannels', previous: $exception);
        }
    }

    public function checkBalance(): SakurupiahBalance
    {
        $this->assertReady();
        $payload = $this->successfulPayload(
            operation: 'checkBalance',
            path: 'check_balance.php',
            body: ['api_id' => $this->apiId(), 'method' => 'balance'],
            lookup: true,
        );

        try {
            return SakurupiahBalance::fromPayload($payload);
        } catch (SakurupiahSchemaException $exception) {
            throw new SakurupiahSchemaException('checkBalance', previous: $exception);
        } catch (Throwable $exception) {
            throw new SakurupiahSchemaException('checkBalance', previous: $exception);
        }
    }

    public function createInvoice(SakurupiahCreateInvoice $invoice): SakurupiahInvoice
    {
        $this->assertReady();

        $apiId = $this->apiId();
        $apiKey = $this->apiKey();
        $signature = SakurupiahSignature::invoice(
            apiId: $apiId,
            method: $invoice->method,
            merchantRef: $invoice->merchantRef,
            amountSubmitted: $invoice->amount->submitted(),
            apiKey: $apiKey,
        );

        $payload = $this->successfulPayload(
            operation: 'createInvoice',
            path: 'create.php',
            body: $invoice->toProviderPayload($apiId, $signature),
            lookup: false,
            outcomeUnknown: true,
            redactions: [$invoice->merchantRef],
        );

        try {
            return SakurupiahInvoice::fromPayload($payload, $invoice);
        } catch (SakurupiahSchemaException $exception) {
            throw new SakurupiahSchemaException('createInvoice', outcomeUnknown: true, previous: $exception);
        } catch (Throwable $exception) {
            throw new SakurupiahSchemaException('createInvoice', outcomeUnknown: true, previous: $exception);
        }
    }

    public function transactionStatus(string $trxId): SakurupiahStatus
    {
        $this->assertReady();
        $this->assertIdentifier($trxId, 'transaction ID');
        $payload = $this->successfulPayload(
            operation: 'transactionStatus',
            path: 'status-transaction.php',
            body: [
                'api_id' => $this->apiId(),
                'method' => 'status',
                'trx_id' => $trxId,
            ],
            lookup: true,
            redactions: [$trxId],
        );

        try {
            return SakurupiahStatus::fromPayload($payload, $trxId);
        } catch (SakurupiahSchemaException $exception) {
            throw new SakurupiahSchemaException('transactionStatus', previous: $exception);
        } catch (Throwable $exception) {
            throw new SakurupiahSchemaException('transactionStatus', previous: $exception);
        }
    }

    /** @return list<SakurupiahHistoryItem> */
    public function transactionHistory(?SakurupiahHistoryFilter $filter = null): array
    {
        $this->assertReady();
        $filter ??= new SakurupiahHistoryFilter;
        $body = array_merge(
            ['api_id' => $this->apiId(), 'method' => 'transaction'],
            $filter->toProviderPayload(),
        );
        $payload = $this->successfulPayload(
            operation: 'transactionHistory',
            path: 'transaction.php',
            body: $body,
            lookup: true,
            redactions: [$filter->trxId, $filter->merchantRef],
        );

        try {
            $history = [];
            foreach (SakurupiahPayloadReader::dataItems($payload) as $item) {
                $history[] = SakurupiahHistoryItem::fromPayload(SakurupiahPayloadReader::object($item));
            }

            return $history;
        } catch (SakurupiahSchemaException $exception) {
            throw new SakurupiahSchemaException('transactionHistory', previous: $exception);
        } catch (Throwable $exception) {
            throw new SakurupiahSchemaException('transactionHistory', previous: $exception);
        }
    }

    public function environment(): SakurupiahEnvironment
    {
        return $this->environment;
    }

    /**
     * @param  array<string, int|string>  $body
     * @param  list<string|null>  $redactions
     */
    private function successfulPayload(
        string $operation,
        string $path,
        array $body,
        bool $lookup,
        bool $outcomeUnknown = false,
        array $redactions = [],
    ): array {
        $response = $this->post($operation, $path, $body, $lookup, $redactions);
        $payload = $this->decode($response, $operation, $outcomeUnknown);
        $this->assertApplicationSuccess($payload, $operation, $outcomeUnknown, $redactions);

        return $payload;
    }

    /**
     * @param  array<string, int|string>  $body
     * @param  list<string|null>  $redactions
     */
    private function post(string $operation, string $path, array $body, bool $lookup, array $redactions = []): Response
    {
        $this->assertReady();

        $maxAttempts = $lookup ? $this->lookupMaxAttempts() : 1;
        $context = [
            'request_id' => (string) Str::uuid(),
            'environment' => $this->environment->value,
            'merchant_fingerprint' => SakurupiahRequestEvidence::merchantFingerprint($this->environment, $this->apiId()),
            'caller' => SakurupiahRequestEvidence::caller(),
            'operation' => $operation,
            'max_attempts' => $maxAttempts,
        ];
        $backpressure = new SakurupiahRequestBackpressure($this->environment);
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $requestLock = $backpressure->acquireRequest($operation, $lookup, $context['caller']);
            } catch (SakurupiahProviderException|SakurupiahCreatePreRequestException $exception) {
                SakurupiahRequestEvidence::deferred($context, $exception);
                throw $exception;
            }
            $startedAt = hrtime(true);
            $attemptContext = array_merge($context, [
                'attempt' => $attempt,
                'started_at' => now()->format('Y-m-d\TH:i:s.uP'),
            ]);
            try {
                $response = $this->request()->post($this->endpoint($path), $body);
                $backpressure->observe($response);
            } catch (ConnectionException $exception) {
                SakurupiahRequestEvidence::record($attemptContext, $startedAt, exception: $exception);
                if ($lookup && $attempt < $maxAttempts) {
                    $this->sleepBeforeRetry($attempt);

                    continue;
                }

                throw new SakurupiahTransportException($operation, outcomeUnknown: ! $lookup, previous: $exception);
            } catch (Throwable $exception) {
                SakurupiahRequestEvidence::record($attemptContext, $startedAt, exception: $exception);
                throw new SakurupiahTransportException($operation, outcomeUnknown: ! $lookup, previous: $exception);
            } finally {
                try {
                    $requestLock->release();
                } catch (Throwable) {
                    // The owner-fenced lease expires without changing a dispatched request's outcome.
                }
            }

            SakurupiahRequestEvidence::record($attemptContext, $startedAt, response: $response);

            if ($response->successful()) {
                return $response;
            }

            // The provider documents its own failure contract: an application
            // status plus the reason in `message`. When that contract answers a
            // non-retryable HTTP failure the request was processed and
            // rejected, so no transport ambiguity remains. Retryable transport
            // statuses keep the HTTP failure so a lookup can retry them.
            $rejection = SakurupiahHttpException::isRetryableStatus($response->status())
                ? null
                : $this->providerFailureEnvelope($response);
            if ($rejection !== null) {
                throw new SakurupiahApplicationException(
                    operation: $operation,
                    providerStatus: $rejection['status'],
                    providerMessage: SakurupiahProviderMessage::sanitize($rejection['message'], $redactions),
                );
            }

            $exception = new SakurupiahHttpException(
                operation: $operation,
                status: $response->status(),
                outcomeUnknown: ! $lookup,
            );
            if ($lookup && $attempt < $maxAttempts && $exception->isRetryable() && $response->status() !== 429) {
                $this->sleepBeforeRetry($attempt);

                continue;
            }

            throw $exception;
        }

        throw new SakurupiahTransportException($operation, outcomeUnknown: ! $lookup);
    }

    /**
     * Read the provider's documented failure contract from a response body.
     *
     * @return array{status: string, message: ?string}|null
     */
    private function providerFailureEnvelope(Response $response): ?array
    {
        try {
            $payload = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($payload) || array_is_list($payload) || ! array_key_exists('status', $payload)) {
            return null;
        }

        $status = $this->canonicalApplicationStatus($payload['status']);
        if ($status === null || $status === '200') {
            return null;
        }

        return [
            'status' => $status,
            'message' => is_string($payload['message'] ?? null) ? $payload['message'] : null,
        ];
    }

    /** @return array<mixed> */
    private function decode(Response $response, string $operation, bool $outcomeUnknown): array
    {
        try {
            $payload = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $exception) {
            throw new SakurupiahJsonException($operation, outcomeUnknown: $outcomeUnknown, previous: $exception);
        }

        if (! is_array($payload) || array_is_list($payload)) {
            throw new SakurupiahSchemaException($operation, outcomeUnknown: $outcomeUnknown);
        }

        return $payload;
    }

    /**
     * @param  array<mixed>  $payload
     * @param  list<string|null>  $redactions
     */
    private function assertApplicationSuccess(array $payload, string $operation, bool $outcomeUnknown, array $redactions = []): void
    {
        if (! array_key_exists('status', $payload)) {
            throw new SakurupiahSchemaException($operation, outcomeUnknown: $outcomeUnknown);
        }

        $status = $this->canonicalApplicationStatus($payload['status']);
        if ($status === null) {
            throw new SakurupiahSchemaException($operation, outcomeUnknown: $outcomeUnknown);
        }

        if ($status === '200') {
            return;
        }

        throw new SakurupiahApplicationException(
            operation: $operation,
            providerStatus: $status,
            providerMessage: SakurupiahProviderMessage::sanitize($payload['message'] ?? null, $redactions),
            outcomeUnknown: $outcomeUnknown,
        );
    }

    /**
     * Canonicalize the provider application status without casting it.
     *
     * @return string|null Null when the value is not a documented status.
     */
    private function canonicalApplicationStatus(mixed $status): ?string
    {
        if (is_int($status)) {
            return $status === 200 ? '200' : (string) $status;
        }

        if (! is_string($status) || preg_match('/^\d+$/', $status) !== 1) {
            return null;
        }

        $canonicalStatus = ltrim($status, '0') ?: '0';
        $maximumStatus = (string) PHP_INT_MAX;
        if (strlen($canonicalStatus) > strlen($maximumStatus)
            || (strlen($canonicalStatus) === strlen($maximumStatus) && strcmp($canonicalStatus, $maximumStatus) > 0)
        ) {
            return null;
        }

        return $status === $canonicalStatus ? $canonicalStatus : null;
    }

    private function assertReady(): void
    {
        $this->readiness->configurationReport($this->environment)->assertReady(
            'Sakurupiah '.$this->environment->label().' environment is not ready.',
        );
    }

    private function request(): PendingRequest
    {
        return ProviderVerificationProfiles::prepareRequest(
            Http::asForm()->acceptJson()->withToken($this->apiKey()),
            $this->positiveFloatConfig('services.sakurupiah.http_timeout_seconds', 10.0),
            $this->positiveFloatConfig('services.sakurupiah.http_connect_timeout_seconds', 3.0),
        );
    }

    private function endpoint(string $path): string
    {
        $baseUrl = config($this->environment->configKey('base_url'), $this->environment->documentedBaseUrl());

        return rtrim((string) $baseUrl, '/').'/'.ltrim($path, '/');
    }

    private function apiId(): string
    {
        return (string) config($this->environment->configKey('api_id'));
    }

    private function apiKey(): string
    {
        return (string) config($this->environment->configKey('api_key'));
    }

    private function lookupMaxAttempts(): int
    {
        $value = config('services.sakurupiah.lookup_max_attempts', 3);
        if (! is_numeric($value)) {
            return 3;
        }

        return min(5, max(1, (int) $value));
    }

    private function sleepBeforeRetry(int $attempt): void
    {
        $baseDelay = $this->nonNegativeIntConfig('services.sakurupiah.lookup_retry_delay_ms', 250);
        $maxDelay = $this->nonNegativeIntConfig('services.sakurupiah.lookup_retry_max_delay_ms', 1000);
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

    private function assertIdentifier(string $value, string $label): void
    {
        if ($value === '' || trim($value) !== $value || strlen($value) > 255) {
            throw new InvalidArgumentException('Sakurupiah '.$label.' is invalid.');
        }
    }
}
