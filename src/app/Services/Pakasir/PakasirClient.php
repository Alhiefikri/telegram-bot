<?php

namespace App\Services\Pakasir;

use App\Models\Payment;
use App\Support\DogfoodTiming;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use UnexpectedValueException;

class PakasirClient implements PakasirProviderAdapter
{
    public function __construct(private readonly PakasirProviderReadiness $readiness) {}

    public function hostedPaymentUrl(Payment $payment): string
    {
        $this->readiness->verification()->assertReady('Pakasir hosted payment URL cannot be created.');

        $query = [
            'order_id' => $payment->merchant_ref,
            'redirect' => config('services.pakasir.redirect_url'),
        ];

        if ((bool) config('services.pakasir.qris_only')) {
            $query['qris_only'] = 1;
        }

        $query = array_filter($query, fn ($value) => $value !== null && $value !== '');

        return sprintf(
            '%s/pay/%s/%d?%s',
            $this->baseUrl(),
            rawurlencode((string) config('services.pakasir.slug')),
            $payment->amount,
            http_build_query($query, '', '&', PHP_QUERY_RFC3986),
        );
    }

    public function transactionDetail(Payment $payment): array
    {
        $this->readiness->verification()->assertReady('Pakasir transaction detail cannot be requested.');

        return $this->timed('transactionDetail', fn () => Http::get($this->baseUrl().'/api/transactiondetail', [
            'project' => config('services.pakasir.slug'),
            'amount' => $payment->amount,
            'order_id' => $payment->merchant_ref,
            'api_key' => config('services.pakasir.api_key'),
        ])->throw()->json());
    }

    public function createQrisTransaction(Payment $payment): PakasirQrisTransaction
    {
        $this->readiness->verification()->assertReady('Pakasir QRIS transaction cannot be created.');

        $project = (string) config('services.pakasir.slug');
        $response = $this->timed('createQrisTransaction', fn () => Http::asJson()->post($this->baseUrl().'/api/transactioncreate/qris', [
            'project' => $project,
            'order_id' => $payment->merchant_ref,
            'amount' => $payment->amount,
            'api_key' => config('services.pakasir.api_key'),
        ])->throw()->json());

        if (! is_array($response)) {
            throw new UnexpectedValueException('Pakasir QRIS create response is not valid JSON.');
        }

        $transaction = PakasirQrisTransaction::fromPayload($response);
        $transaction->assertMatches($payment, $project);

        return $transaction;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.pakasir.base_url', 'https://app.pakasir.com'), '/');
    }

    private function timed(string $operation, callable $callback): mixed
    {
        $startedAt = DogfoodTiming::now();
        $status = null;

        try {
            $result = $callback();
            $status = is_object($result) && method_exists($result, 'status') ? $result->status() : 200;

            return $result;
        } catch (RequestException $e) {
            $status = $e->response?->status();
            throw $e;
        } finally {
            DogfoodTiming::log('dogfood.outbound_http', [
                'provider' => 'pakasir',
                'operation' => $operation,
                'status' => $status,
                'duration_ms' => DogfoodTiming::elapsedMs($startedAt),
            ]);
        }
    }
}
