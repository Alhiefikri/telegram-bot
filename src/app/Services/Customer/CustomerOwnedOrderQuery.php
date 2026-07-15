<?php

namespace App\Services\Customer;

use App\Enums\PaymentStatus;
use App\Models\Customer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CustomerOwnedOrderQuery
{
    public function paginatePaid(Customer $customer, int $perPage = 20): LengthAwarePaginator
    {
        return $customer->orders()
            ->where('payment_status', PaymentStatus::Paid)
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }
}
