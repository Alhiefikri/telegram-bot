<?php

namespace App\Storefront;

use App\Models\Order;
use Illuminate\Support\Facades\URL;

class StorefrontInvoiceUrl
{
    public function show(Order $order): string
    {
        return URL::temporarySignedRoute('storefront.shop.orders.show', $this->expiresAt($order), $order, false);
    }

    public function status(Order $order): string
    {
        return URL::temporarySignedRoute('storefront.shop.orders.status', $this->expiresAt($order), $order, false);
    }

    public function cancel(Order $order): string
    {
        return URL::temporarySignedRoute('storefront.shop.orders.cancel', $this->expiresAt($order), $order, false);
    }

    private function expiresAt(Order $order): \DateTimeInterface
    {
        $days = max(1, (int) config('services.storefront.invoice_url_ttl_days', 7));

        return ($order->created_at?->copy() ?? now())->addDays($days);
    }
}
