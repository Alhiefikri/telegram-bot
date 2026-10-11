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
            $invoice = strtoupper(trim($invoice));
            $ownerId = Order::query()->where('invoice_number', $invoice)->value('customer_id');
            if ($ownerId === null) {
                return null;
            }

            // Enrollment and provider linking lock Customer first. Use the same order,
            // with both Customer IDs sorted, before serializing this Order's claim.
            $customers = Customer::query()->whereIn('id', [$customer->getKey(), $ownerId])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $customer = $customers->get($customer->getKey());
            $owner = $customers->get($ownerId);
            $order = Order::query()->where('invoice_number', $invoice)->lockForUpdate()->first();

            if (! $customer || ! $owner || ! $order
                || $order->payment_status !== PaymentStatus::Paid || ! $this->access->verifyKey($order, $key)) {
                return null;
            }

            if ((int) $order->customer_id === (int) $customer->getKey()) {
                return $order;
            }

            if ((int) $order->customer_id !== (int) $ownerId) {
                return null;
            }

            // Nullable email does not make a provider-authenticated Customer a guest.
            if ($owner->email !== null || $owner->telegram_id !== null || $owner->password !== null
                || $owner->email_verified_at !== null || $owner->identities()->exists()) {
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
