<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role !== null;
    }

    public function view(User $user, Product $product): bool
    {
        return $user->role !== null;
    }

    public function create(User $user): bool
    {
        return (bool) $user->role?->canMutateProducts();
    }

    public function update(User $user, Product $product): bool
    {
        return (bool) $user->role?->canMutateProducts();
    }

    public function delete(User $user, Product $product): bool
    {
        return (bool) $user->role?->canMutateProducts();
    }

    public function deleteAny(User $user): bool
    {
        return (bool) $user->role?->canMutateProducts();
    }

    public function restore(User $user, Product $product): bool
    {
        return (bool) $user->role?->canMutateProducts();
    }

    public function restoreAny(User $user): bool
    {
        return (bool) $user->role?->canMutateProducts();
    }

    public function forceDelete(User $user, Product $product): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function adjustStock(User $user, Product $product): bool
    {
        return (bool) $user->role?->canMutateProducts();
    }
}
