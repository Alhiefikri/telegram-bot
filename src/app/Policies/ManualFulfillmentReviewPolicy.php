<?php

namespace App\Policies;

use App\Models\ManualFulfillmentReview;
use App\Models\User;

class ManualFulfillmentReviewPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role?->canViewOrdersAndPayments() ?? false;
    }

    public function view(User $user, ManualFulfillmentReview $review): bool
    {
        return $this->viewAny($user);
    }

    public function resolve(User $user, ManualFulfillmentReview $review): bool
    {
        return $user->role?->canManagePaymentSettings() ?? false;
    }
}
