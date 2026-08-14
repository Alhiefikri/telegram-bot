<?php

namespace App\Services\Customer;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class CustomerOwnedOrderQuery
{
    private const FILTERS = ['all', 'waiting', 'processing', 'completed', 'issue'];

    public function paginateHistory(Customer $customer, string $filter = 'all', int $perPage = 20): LengthAwarePaginator
    {
        $statuses = $this->statusesForFilter($this->normalizeFilter($filter));

        return Order::query()
            ->where('customer_id', $customer->getKey())
            ->select(['id', 'customer_id', 'invoice_number', 'order_status', 'payment_status', 'total_amount', 'currency', 'created_at'])
            ->when($statuses !== null, fn ($query) => $query->whereIn('order_status', array_map(
                static fn (OrderStatus $status): string => $status->value,
                $statuses,
            )))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function normalizeFilter(mixed $filter): string
    {
        return is_string($filter) && in_array($filter, self::FILTERS, true) ? $filter : 'all';
    }

    /**
     * @return list<OrderStatus>|null
     */
    private function statusesForFilter(string $filter): ?array
    {
        return match ($filter) {
            'waiting' => [OrderStatus::Draft, OrderStatus::PendingPayment],
            'processing' => [OrderStatus::Processing],
            'completed' => [OrderStatus::Completed],
            'issue' => [OrderStatus::Cancelled, OrderStatus::Expired],
            default => null,
        };
    }
}
