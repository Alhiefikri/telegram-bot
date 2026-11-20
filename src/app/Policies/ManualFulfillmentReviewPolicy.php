<?php

namespace App\Policies;

use App\Enums\UserRole;
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

    public function provideCredentials(User $user, ManualFulfillmentReview $review): bool
    {
        return $this->resolve($user, $review)
            && ($review->reason !== ManualFulfillmentReview::REASON_LOCAL_CREDENTIALS_MISSING || $user->role === UserRole::Owner);
    }
}
