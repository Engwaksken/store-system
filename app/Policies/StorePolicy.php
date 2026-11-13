<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Store;
use App\Models\User;

/** Store master data. Storekeeper assignment (who may operate a store) is admin-only and handled in the controller. */
class StorePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasRole(Role::Admin, Role::OverallManager, Role::Storekeeper, Role::Accountant, Role::Auditor);
    }

    public function view(User $user, Store $store): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->hasRole(Role::Admin, Role::Storekeeper);
    }

    public function update(User $user, Store $store): bool
    {
        return $user->is_active && ($user->isAdmin() || ($user->role === Role::Storekeeper && $store->isOperableBy($user)));
    }

    public function assignUsers(User $user, Store $store): bool
    {
        return $user->is_active && $user->isAdmin();
    }
}
