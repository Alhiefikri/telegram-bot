<?php

namespace App\Services\Sakurupiah;

use App\Enums\SakurupiahEnvironment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
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
                $channels[] = SakurupiahChannel::fromPayload(SakurupiahPayloadReader::object($item));
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
     */
    private function successfulPayload(
        string $operation,
        string $path,
        array $body,
        bool $lookup,
        bool $outcomeUnknown = false,
    ): array {
        $response = $this->post($operation, $path, $body, $lookup);
        $payload = $this->decode($response, $operation, $outcomeUnknown);
        $this->assertApplicationSuccess($payload, $operation, $outcomeUnknown);

        return $payload;
    }

    /**
     * @param  array<string, int|string>  $body
     */
    private function post(string $operation, string $path, array $body, bool $lookup): Response
    {
        $this->assertReady();

        $maxAttempts = $lookup ? $this->lookupMaxAttempts() : 1;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = $this->request()->post($this->endpoint($path), $body);
            } catch (ConnectionException $exception) {
                if ($lookup && $attempt < $maxAttempts) {
                    $this->sleepBeforeRetry($attempt);

                    continue;
                }

                throw new SakurupiahTransportException($operation, outcomeUnknown: ! $lookup, previous: $exception);
            } catch (Throwable $exception) {
                throw new SakurupiahTransportException($operation, outcomeUnknown: ! $lookup, previous: $exception);
            }

            if ($response->successful()) {
                return $response;
            }

            $exception = new SakurupiahHttpException(
                operation: $operation,
                status: $response->status(),
                outcomeUnknown: ! $lookup,
            );
            if ($lookup && $attempt < $maxAttempts && $exception->isRetryable()) {
                $this->sleepBeforeRetry($attempt);

                continue;
            }

            throw $exception;
        }

        throw new SakurupiahTransportException($operation, outcomeUnknown: ! $lookup);
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

    /** @param array<mixed> $payload */
    private function assertApplicationSuccess(array $payload, string $operation, bool $outcomeUnknown): void
    {
        if (! array_key_exists('status', $payload)) {
            throw new SakurupiahSchemaException($operation, outcomeUnknown: $outcomeUnknown);
        }

        $status = $payload['status'];
        if (is_int($status)) {
            if ($status === 200) {
                return;
            }

            throw new SakurupiahApplicationException($operation, (string) $status, outcomeUnknown: $outcomeUnknown);
        }

        if (! is_string($status) || preg_match('/^\d+$/', $status) !== 1) {
            throw new SakurupiahSchemaException($operation, outcomeUnknown: $outcomeUnknown);
        }

        $canonicalStatus = ltrim($status, '0') ?: '0';
        $maximumStatus = (string) PHP_INT_MAX;
        if (strlen($canonicalStatus) > strlen($maximumStatus)
            || (strlen($canonicalStatus) === strlen($maximumStatus) && strcmp($canonicalStatus, $maximumStatus) > 0)
        ) {
            throw new SakurupiahSchemaException($operation, outcomeUnknown: $outcomeUnknown);
        }

        if ($status !== $canonicalStatus) {
            throw new SakurupiahSchemaException($operation, outcomeUnknown: $outcomeUnknown);
        }

        if ($canonicalStatus === '200') {
            return;
        }

        throw new SakurupiahApplicationException($operation, $status, outcomeUnknown: $outcomeUnknown);
    }

    private function assertReady(): void
    {
        $this->readiness->forEnvironment($this->environment)->assertReady(
            'Sakurupiah '.$this->environment->label().' environment is not ready.',
        );
    }

    private function request(): PendingRequest
    {
        return Http::asForm()
            ->acceptJson()
            ->withToken($this->apiKey())
            ->timeout($this->positiveFloatConfig('services.sakurupiah.http_timeout_seconds', 10.0))
            ->connectTimeout($this->positiveFloatConfig('services.sakurupiah.http_connect_timeout_seconds', 3.0));
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
        usleep($delay * 1000);
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
