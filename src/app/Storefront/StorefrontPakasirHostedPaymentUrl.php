<?php

namespace App\Storefront;

use App\Models\Payment;

class StorefrontPakasirHostedPaymentUrl
{
    public function for(Payment $payment): ?string
    {
        $slug = trim((string) config('services.pakasir.slug'));

        if ($slug === '') {
            return null;
        }

        $query = array_filter([
            'order_id' => $payment->merchant_ref,
            'redirect' => config('services.pakasir.redirect_url'),
        ], fn ($value) => $value !== null && $value !== '');

        return sprintf(
            '%s/pay/%s/%d?%s',
            rtrim((string) config('services.pakasir.base_url', 'https://app.pakasir.com'), '/'),
            rawurlencode($slug),
            $payment->amount,
            http_build_query($query, '', '&', PHP_QUERY_RFC3986),
        );
    }
}
