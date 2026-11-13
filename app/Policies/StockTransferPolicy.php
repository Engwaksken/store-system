<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\User;

class StockTransferPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasRole(Role::Admin, Role::OverallManager, Role::Storekeeper, Role::Accountant, Role::Auditor);
    }

    public function view(User $user, StockTransfer $transfer): bool
    {
        return $this->viewAny($user) && StockTransfer::visibleTo($user)->whereKey($transfer->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->hasRole(Role::Admin, Role::Storekeeper) && Store::operableBy($user)->exists();
    }

    /** Destination store user, never the dispatcher (sending/receiving confirmation by different people). */
    public function receive(User $user, StockTransfer $transfer): bool
    {
        return $transfer->status === 'in_transit' && $user->id !== $transfer->dispatched_by
            && $user->hasRole(Role::Admin, Role::Storekeeper)
            && Store::operableBy($user)->whereKey($transfer->to_store_id)->exists();
    }

    public function cancel(User $user, StockTransfer $transfer): bool
    {
        return $transfer->status === 'in_transit' && $user->is_active
            && ($user->isAdmin() || ($user->role === Role::Storekeeper && $user->id === $transfer->dispatched_by));
    }
}
