<?php

namespace App\Services\Tripay;

use App\Models\Payment;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TripayClient
{
    public function createTransaction(Payment $payment): array
    {
        $payment->loadMissing('order.customer', 'order.orderItems');

        $payload = [
            'method' => $payment->method,
            'merchant_ref' => $payment->merchant_ref,
            'amount' => $payment->amount,
            'customer_name' => $payment->order->customer->first_name ?: 'Telegram Customer',
            'customer_email' => 'customer-'.$payment->order->customer_id.'@telegram.local',
            'customer_phone' => $payment->order->customer->phone_number,
            'order_items' => $payment->order->orderItems->map(fn ($item) => [
                'name' => $item->product_name,
                'price' => $item->unit_price,
                'quantity' => $item->quantity,
            ])->values()->all(),
            'callback_url' => config('services.tripay.callback_url'),
            'return_url' => config('services.tripay.return_url'),
            'expired_time' => $payment->expired_at?->timestamp,
            'signature' => $this->transactionSignature($payment->merchant_ref, $payment->amount),
        ];

        $payload = array_filter($payload, fn ($value) => $value !== null);

        $response = Http::withToken((string) config('services.tripay.api_key'))
            ->asForm()
            ->post($this->baseUrl().'/transaction/create', $payload)
            ->throw()
            ->json();

        if (Arr::get($response, 'success') !== true || ! is_array(Arr::get($response, 'data'))) {
            throw new RuntimeException('Tripay transaction create response was not successful.');
        }

        $reference = trim((string) Arr::get($response, 'data.reference'));
        if ($reference === '') {
            throw new RuntimeException('Tripay transaction create response did not include a reference.');
        }

        $payment->update(['provider_reference' => $reference]);

        return $response;
    }

    public function detail(string $reference): array
    {
        return Http::withToken((string) config('services.tripay.api_key'))
            ->get($this->baseUrl().'/transaction/detail', ['reference' => $reference])
            ->throw()
            ->json();
    }

    public function callbackSignature(string $rawBody): string
    {
        return hash_hmac('sha256', $rawBody, $this->privateKey());
    }

    private function transactionSignature(string $merchantRef, int $amount): string
    {
        return hash_hmac('sha256', (string) config('services.tripay.merchant_code').$merchantRef.$amount, $this->privateKey());
    }

    private function privateKey(): string
    {
        $key = trim((string) config('services.tripay.private_key'));
        if ($key === '') {
            throw new RuntimeException('Tripay private key is not configured.');
        }

        return $key;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.tripay.base_url'), '/');
    }
}
