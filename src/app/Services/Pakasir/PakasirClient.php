<?php

namespace App\Services\Pakasir;

use App\Models\Payment;
use Illuminate\Support\Facades\Http;

class PakasirClient implements PakasirProviderAdapter
{
    public function hostedPaymentUrl(Payment $payment): string
    {
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
        return Http::get($this->baseUrl().'/api/transactiondetail', [
            'project' => config('services.pakasir.slug'),
            'amount' => $payment->amount,
            'order_id' => $payment->merchant_ref,
            'api_key' => config('services.pakasir.api_key'),
        ])->throw()->json();
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.pakasir.base_url', 'https://app.pakasir.com'), '/');
    }
}
