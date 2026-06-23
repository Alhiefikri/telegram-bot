<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role?->canViewOrdersAndPayments() ?? false;
    }

    public function view(User $user, Order $order): bool
    {
        return $user->role?->canViewOrdersAndPayments() ?? false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Order $order): bool
    {
        return false;
    }

    public function delete(User $user, Order $order): bool
    {
        return false;
    }

    public function resendReceipt(User $user, Order $order): bool
    {
        return $user->role?->canResendReceipts() ?? false;
    }

    public function completeManual(User $user, Order $order): bool
    {
        return $user->role?->canCompleteManualOrders() ?? false;
    }
}
