<?php

namespace App\Policies;

use App\Models\TelegramOutboundEffect;
use App\Models\User;

class TelegramOutboundEffectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role?->canViewOrdersAndPayments() ?? false;
    }

    public function view(User $user, TelegramOutboundEffect $effect): bool
    {
        return $this->viewAny($user);
    }

    public function markSent(User $user, TelegramOutboundEffect $effect): bool
    {
        return $user->role?->canManagePaymentSettings() ?? false;
    }

    public function resend(User $user, TelegramOutboundEffect $effect): bool
    {
        return $user->role?->canManagePaymentSettings() ?? false;
    }

    public function resolve(User $user, TelegramOutboundEffect $effect): bool
    {
        return $this->markSent($user, $effect);
    }
}
