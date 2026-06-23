<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role !== null;
    }

    public function view(User $user, Category $category): bool
    {
        return $user->role !== null;
    }

    public function create(User $user): bool
    {
        return (bool) $user->role?->canMutateProducts();
    }

    public function update(User $user, Category $category): bool
    {
        return (bool) $user->role?->canMutateProducts();
    }

    public function delete(User $user, Category $category): bool
    {
        return (bool) $user->role?->canMutateProducts();
    }

    public function deleteAny(User $user): bool
    {
        return (bool) $user->role?->canMutateProducts();
    }

    public function restore(User $user, Category $category): bool
    {
        return (bool) $user->role?->canMutateProducts();
    }

    public function restoreAny(User $user): bool
    {
        return (bool) $user->role?->canMutateProducts();
    }

    public function forceDelete(User $user, Category $category): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
