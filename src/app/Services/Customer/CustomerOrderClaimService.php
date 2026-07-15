<?php

namespace App\Services\Customer;

use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Order;
use App\Storefront\StorefrontOrderAccess;
use Illuminate\Support\Facades\DB;

class CustomerOrderClaimService
{
    public function __construct(private readonly StorefrontOrderAccess $access) {}

    public function claim(Customer $customer, string $invoice, string $key): ?Order
    {
        return DB::transaction(function () use ($customer, $invoice, $key): ?Order {
            $customer = Customer::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();
            $order = Order::query()->where('invoice_number', strtoupper(trim($invoice)))->lockForUpdate()->first();

            if (! $order || $order->payment_status !== PaymentStatus::Paid || ! $this->access->verifyKey($order, $key)) {
                return null;
            }

            if ((int) $order->customer_id === (int) $customer->getKey()) {
                return $order;
            }

            // A guest order is initially owned by a web-only customer. Never steal an account-owned order.
            $owner = $order->customer;
            if ($owner?->email !== null || $owner?->telegram_id !== null) {
                return null;
            }

            $order->forceFill(['customer_id' => $customer->getKey()])->save();
            AuditLog::create([
                'action' => 'order.customer_claimed',
                'subject_type' => Order::class,
                'subject_id' => (string) $order->getKey(),
                'new_values' => ['customer_id' => $customer->getKey()],
                'ip_address' => request()?->ip(),
                'created_at' => now(),
            ]);

            return $order;
        });
    }
}
