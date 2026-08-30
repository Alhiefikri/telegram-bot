<?php

namespace App\Policies;

use App\Models\ProductFamily;
use App\Models\User;

class ProductFamilyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role !== null;
    }

    public function view(User $user, ProductFamily $family): bool
    {
        return $user->role !== null;
    }

    public function create(User $user): bool
    {
        return (bool) $user->role?->canMutateProducts();
    }

    public function update(User $user, ProductFamily $family): bool
    {
        return (bool) $user->role?->canMutateProducts();
    }

    public function delete(User $user, ProductFamily $family): bool
    {
        return (bool) $user->role?->canMutateProducts();
    }

    public function deleteAny(User $user): bool
    {
        return (bool) $user->role?->canMutateProducts();
    }
}
